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
use OCP\Log\Audit\CriticalActionPerformedEvent;
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
	 * Portaliq's invitation event: portaliq mails the guardian a one-time
	 * link (invitation-secret-joins-the-signed-in-account REQ-PIS-007).
	 */
	public const INVITATION_EVENT = 'OCA\\Portaliq\\Event\\PortalAccountInvitationRequestedEvent';

	/**
	 * The invitation mail left.
	 */
	public const MAIL_SENT = 'sent';

	/**
	 * Portaliq made the invitation but its mail did not leave.
	 */
	public const MAIL_NOT_SENT = 'not-sent';

	/**
	 * This portaliq sends no invitation mail (an older version), or it
	 * refused this account.
	 */
	public const MAIL_UNAVAILABLE = 'unavailable';

	/**
	 * Portaliq made a code for a paper letter; the answer carries it.
	 */
	public const LETTER_CODE = 'code';

	/**
	 * The invitation goes out by mail, inside a link.
	 */
	public const CHANNEL_MAIL = 'mail';

	/**
	 * The invitation goes out on paper, as a short code the school prints.
	 */
	public const CHANNEL_LETTER = 'letter';

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
	 * @param string $invitationEventClass The invitation event class (overridable in tests).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearnerRefResolver $profiles,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
		private readonly string $provisionEventClass=self::PROVISION_EVENT,
		private readonly string $claimEventClass=self::CLAIM_EVENT,
		private readonly string $invitationEventClass=self::INVITATION_EVENT,
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
	 * Once the account is linked, portaliq is asked to mail the guardian a
	 * one-time link. `invitation` says what came of it: `sent`, `not-sent`
	 * (the mail did not leave; inviting again sends a new one) or
	 * `unavailable` (this portaliq sends none). The link never comes back
	 * here, so nobody at the school sees it. The guardian is linked either
	 * way: a first sign-in with the verified address still finds the account.
	 *
	 * On the channel `letter` no mail is sent. Portaliq answers a short
	 * one-time code instead, and `invitation` is `code` with the code under
	 * `code` and its expiry under `expiresAt`. The school prints it in a
	 * letter; the guardian signs in and types it on "My account".
	 *
	 * @param string $guardianRef The guardian's LearnerProfile uuid.
	 * @param string $email The guardian's verified email address.
	 * @param string $organisation The portal organisation slug.
	 * @param string $channel CHANNEL_MAIL or CHANNEL_LETTER.
	 * @param string $issuedBy Who issued it: the staff user's uid, or `occ`.
	 *
	 * @return array{status: string, reason?: string, subjectRef?: string, invitation?: string, code?: string, expiresAt?: string}
	 *
	 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
	 * @spec openspec/changes/portal-guardian-invitation-mail/specs/portal-identity/spec.md
	 * @spec openspec/changes/portal-guardian-invitation-letter/specs/portal-identity/spec.md
	 */
	public function invite(string $guardianRef, string $email, string $organisation, string $channel=self::CHANNEL_MAIL, string $issuedBy=''): array {
		$email = trim($email);
		$organisation = trim($organisation);
		$inputRefusal = $this->inputRefusal(email: $email, organisation: $organisation, channel: $channel);
		if ($inputRefusal !== '') {
			return self::refused(reason: $inputRefusal);
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

			$sent = $this->sendInvitation(subjectRef: $subjectRef, channel: $channel);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[GuardianPortalInvitation] Could not invite guardian {guardian}: {msg}',
				['guardian' => $guardianRef, 'msg' => $exception->getMessage()]
			);
			return self::refused(reason: 'portal-unavailable');
		}

		$this->recordIssue(issuedBy: $issuedBy, guardianRef: (string)$guardian['id'], channel: $channel, organisation: $organisation);

		return ([
			'status' => 'invited',
			'subjectRef' => $subjectRef,
		] + $sent);
	}//end invite()

	/**
	 * Record who issued an invitation (security review L5): the issuer, the
	 * guardian, the channel and the organisation, never the code or the
	 * link. Nextcloud's audit log (admin_audit) takes the event; the app log
	 * gets the same line.
	 *
	 * @param string $issuedBy The staff user's uid, `occ`, or '' when unknown.
	 * @param string $guardianRef The guardian's LearnerProfile uuid.
	 * @param string $channel The channel.
	 * @param string $organisation The portal organisation slug.
	 *
	 * @return void
	 */
	private function recordIssue(string $issuedBy, string $guardianRef, string $channel, string $organisation): void {
		if ($issuedBy === '') {
			$issuedBy = 'unknown';
		}

		$facts = [
			'issuedBy' => $issuedBy,
			'guardianRef' => $guardianRef,
			'channel' => $channel,
			'organisation' => $organisation,
		];
		$this->dispatcher->dispatchTyped(
			new CriticalActionPerformedEvent(
				'Portal invitation issued by "%s" for guardian "%s" on channel "%s" in organisation "%s"',
				$facts
			)
		);
		$this->logger->info('[GuardianPortalInvitation] Portal invitation issued', $facts);
	}//end recordIssue()

	/**
	 * Why the caller's input is refused, or '' when it is usable.
	 *
	 * @param string $email The trimmed email address.
	 * @param string $organisation The trimmed organisation slug.
	 * @param string $channel The channel asked for.
	 *
	 * @return string The refusal reason, or ''.
	 */
	private function inputRefusal(string $email, string $organisation, string $channel): string {
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return 'email-invalid';
		}

		if ($organisation === '') {
			return 'organisation-missing';
		}

		if (in_array($channel, [self::CHANNEL_MAIL, self::CHANNEL_LETTER], true) === false) {
			return 'channel-unknown';
		}

		return '';
	}//end inputRefusal()

	/**
	 * Have portaliq send the invitation on the channel asked for: a mailed
	 * link, or a code for a paper letter.
	 *
	 * @param string $subjectRef The account's subjectRef.
	 * @param string $channel CHANNEL_MAIL or CHANNEL_LETTER.
	 *
	 * @return array{invitation: string, code?: string, expiresAt?: string}
	 *
	 * @spec openspec/changes/portal-guardian-invitation-letter/specs/portal-identity/spec.md
	 */
	private function sendInvitation(string $subjectRef, string $channel): array {
		if ($channel === self::CHANNEL_LETTER) {
			return $this->letterCode(subjectRef: $subjectRef);
		}

		return ['invitation' => $this->mailInvitation(subjectRef: $subjectRef)];
	}//end sendInvitation()

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
	 * Ask portaliq to mail the guardian the one-time link of the account.
	 *
	 * A portaliq without the event (an older version) sends nothing, and
	 * the invitation stands without a mail.
	 *
	 * @param string $subjectRef The account's subjectRef.
	 *
	 * @return string One of the MAIL_* constants.
	 *
	 * @spec openspec/changes/portal-guardian-invitation-mail/specs/portal-identity/spec.md
	 */
	private function mailInvitation(string $subjectRef): string {
		if (class_exists($this->invitationEventClass) === false) {
			return self::MAIL_UNAVAILABLE;
		}

		$event = new ($this->invitationEventClass)(
			appId: self::APP_ID,
			subjectRef: $subjectRef,
		);
		$this->dispatch(event: $event);

		$result = (string)$event->getResult();
		if ($result === 'sent') {
			return self::MAIL_SENT;
		}

		if ($result === 'not_sent') {
			return self::MAIL_NOT_SENT;
		}

		return self::MAIL_UNAVAILABLE;
	}//end mailInvitation()

	/**
	 * Ask portaliq for the one-time code of the account, for a paper letter.
	 *
	 * A portaliq without the event, or with the event from before it had a
	 * channel, makes no code: the answer is `invitation: unavailable` and the
	 * guardian stays linked on the verified address.
	 *
	 * @param string $subjectRef The account's subjectRef.
	 *
	 * @return array{invitation: string, code?: string, expiresAt?: string}
	 *
	 * @spec openspec/changes/portal-guardian-invitation-letter/specs/portal-identity/spec.md
	 */
	private function letterCode(string $subjectRef): array {
		if (class_exists($this->invitationEventClass) === false) {
			return ['invitation' => self::MAIL_UNAVAILABLE];
		}

		try {
			$event = new ($this->invitationEventClass)(
				appId: self::APP_ID,
				subjectRef: $subjectRef,
				channel: self::CHANNEL_LETTER,
			);
			$this->dispatch(event: $event);
			$code = (string)$event->getCode();
		} catch (Throwable) {
			// An event class without the channel or the code slot: an older
			// portaliq. Nothing was issued.
			return ['invitation' => self::MAIL_UNAVAILABLE];
		}

		if ((string)$event->getResult() !== 'code' || $code === '') {
			return ['invitation' => self::MAIL_UNAVAILABLE];
		}

		return [
			'invitation' => self::LETTER_CODE,
			'code'       => $code,
			'expiresAt'  => (string)$event->getExpiresAt(),
		];
	}//end letterCode()

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
