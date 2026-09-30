<?php

/**
 * Learniq guardian portal invitation
 *
 * Links a guardian to the shared portal (portaliq) so they can read their
 * children's records there. Learniq's `parent` contribution scopes every
 * collection by the `learniq.guardianRef` claim on the guardian's
 * portalAccount (PortalContributionProvider, scopeClaim `guardianRef`), and
 * portaliq only ever writes a claim on behalf of the app that owns it
 * (portaliq portal-identity-space REQ-PIS-003). Nothing wrote that claim,
 * so a guardian signed in to the portal saw no child at all.
 *
 * This class provisions a pending portalAccount for the guardian's verified
 * email address (portaliq matches it on the first login, REQ-PIS-002) and
 * writes the claim, both through portaliq's typed events. The events are
 * named by class string and dispatched only when portaliq is installed, so
 * learniq keeps no dependency on portaliq (ADR-046 amendment A1).
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\Learniq\Service\LearnerRefResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Provisions a guardian's portal account and writes its guardianRef claim.
 *
 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
 */
class GuardianPortalInvitation {

	/**
	 * Portaliq's provision event (portal-identity-space REQ-PIS-001/003).
	 */
	public const PROVISION_EVENT = 'OCA\\Portaliq\\Event\\PortalAccountProvisionRequestedEvent';

	/**
	 * Portaliq's claim event (portal-identity-space REQ-PIS-003).
	 */
	public const CLAIM_EVENT = 'OCA\\Portaliq\\Event\\PortalAccountClaimRequestedEvent';

	/**
	 * The claim the parent contribution scopes by.
	 */
	public const CLAIM_NAME = 'guardianRef';

	/**
	 * The portal audience a guardian signs in as.
	 */
	private const AUDIENCE = 'parent';

	/**
	 * The app id portaliq files the claim under.
	 */
	private const APP_ID = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param LearnerRefResolver $profiles LearnerProfile by uuid.
	 * @param IEventDispatcher $dispatcher Dispatches portaliq's typed events.
	 * @param LoggerInterface $logger PSR logger.
	 * @param string $provisionEventClass The provision event class (overridable in tests).
	 * @param string $claimEventClass The claim event class (overridable in tests).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearnerRefResolver $profiles,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
		private readonly string $provisionEventClass=self::PROVISION_EVENT,
		private readonly string $claimEventClass=self::CLAIM_EVENT,
	) {
	}//end __construct()

	/**
	 * Invite one guardian to the portal.
	 *
	 * The email address must be one the school verified with the guardian
	 * (it is passed to portaliq as verified, which is what lets the first
	 * login find the account). Returns `status: invited` with the account's
	 * `subjectRef`, or `status: refused` with a `reason`.
	 *
	 * @param string $guardianRef The guardian's LearnerProfile uuid.
	 * @param string $email The guardian's verified email address.
	 * @param string $organisation The portal organisation slug.
	 *
	 * @return array{status: string, reason?: string, subjectRef?: string}
	 *
	 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
	 */
	public function invite(string $guardianRef, string $email, string $organisation): array {
		$email = trim($email);
		$organisation = trim($organisation);
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return self::refused(reason: 'email-invalid');
		}

		if ($organisation === '') {
			return self::refused(reason: 'organisation-missing');
		}

		$guardian = $this->guardian(guardianRef: $guardianRef);
		if ($guardian === null) {
			return self::refused(reason: 'guardian-unknown');
		}

		if (class_exists($this->provisionEventClass) === false || class_exists($this->claimEventClass) === false) {
			return self::refused(reason: 'portal-unavailable');
		}

		try {
			$subjectRef = $this->provision(guardian: $guardian, email: $email, organisation: $organisation);
			if ($subjectRef === '') {
				return self::refused(reason: 'provision-refused');
			}

			if ($this->claim(subjectRef: $subjectRef, guardianRef: (string)$guardian['id']) === false) {
				return self::refused(reason: 'claim-refused');
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[GuardianPortalInvitation] Could not invite guardian {guardian}: {msg}',
				['guardian' => $guardianRef, 'msg' => $exception->getMessage()]
			);
			return self::refused(reason: 'portal-unavailable');
		}

		return [
			'status' => 'invited',
			'subjectRef' => $subjectRef,
		];
	}//end invite()

	/**
	 * The guardian's active profile, or null when the uuid names no profile
	 * with the `parent` role.
	 *
	 * @param string $guardianRef The guardian's LearnerProfile uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function guardian(string $guardianRef): ?array {
		$profile = $this->profiles->byRef(learnerRef: trim($guardianRef));
		if ($profile === null) {
			return null;
		}

		$roles = ($profile['roles'] ?? []);
		if (is_array($roles) === false || in_array('parent', $roles, true) === false) {
			return null;
		}

		return $profile;
	}//end guardian()

	/**
	 * Ask portaliq for the guardian's account; answers its subjectRef or ''.
	 *
	 * @param array<string, mixed> $guardian The guardian's profile.
	 * @param string $email The verified email address.
	 * @param string $organisation The portal organisation slug.
	 *
	 * @return string
	 */
	private function provision(array $guardian, string $email, string $organisation): string {
		$displayName = trim(((string)($guardian['givenName'] ?? '')) . ' ' . ((string)($guardian['familyName'] ?? '')));
		$event = new ($this->provisionEventClass)(
			appId: self::APP_ID,
			audience: self::AUDIENCE,
			organisation: $organisation,
			identityType: '',
			identityRef: '',
			email: $email,
			verifiedEmail: true,
			displayName: $displayName,
		);
		$this->dispatch(event: $event);

		return (string)$event->getSubjectRef();
	}//end provision()

	/**
	 * Write `claims.learniq.guardianRef` on the account.
	 *
	 * @param string $subjectRef The account's subjectRef.
	 * @param string $guardianRef The guardian's LearnerProfile uuid.
	 *
	 * @return bool True when portaliq answered `ok`.
	 */
	private function claim(string $subjectRef, string $guardianRef): bool {
		$event = new ($this->claimEventClass)(
			appId: self::APP_ID,
			subjectRef: $subjectRef,
			claimName: self::CLAIM_NAME,
			value: $guardianRef,
		);
		$this->dispatch(event: $event);

		return $event->getResult() === 'ok';
	}//end claim()

	/**
	 * Dispatch one typed event.
	 *
	 * @param object $event The event.
	 *
	 * @return void
	 */
	private function dispatch(object $event): void {
		if ($event instanceof Event === true) {
			$this->dispatcher->dispatchTyped($event);
		}
	}//end dispatch()

	/**
	 * A refusal answer.
	 *
	 * @param string $reason The refusal reason.
	 *
	 * @return array{status: string, reason: string}
	 */
	private static function refused(string $reason): array {
		return [
			'status' => 'refused',
			'reason' => $reason,
		];
	}//end refused()
}//end class
