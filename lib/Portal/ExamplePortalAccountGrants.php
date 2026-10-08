<?php

/**
 * Learniq example portal accounts
 *
 * Gives the learners an example set declares (a pupil, a student, a course
 * participant) the portal account their portal's `nextcloud` sign-in needs:
 * an ACTIVE portalAccount whose subjectRef is their Nextcloud user id, with
 * the `learniq.learnerRef` claim the pupil and participant pages scope by.
 * Without it the sign-in answers `no_portal_account` (portal proof run 1,
 * item 15).
 *
 * Portaliq writes the account (portaliq an-app-provisions-a-nextcloud-account,
 * #1381) and the claim (REQ-PIS-003) through its typed events; learniq never
 * writes portaliq's register. The events are named by class string, so
 * learniq keeps no dependency on portaliq (ADR-046 amendment A1). A portaliq
 * without the Nextcloud provisioning answers `portaliq-too-old` and nothing
 * is asked.
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
 * @spec openspec/changes/example-portal-install-steps/specs/example-sets/spec.md#requirement-loading-a-set-gives-its-declared-learners-a-portal-account
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks portaliq for the declared learners' portal accounts, once.
 *
 * @spec openspec/changes/example-portal-install-steps/specs/example-sets/spec.md#requirement-loading-a-set-gives-its-declared-learners-a-portal-account
 */
class ExamplePortalAccountGrants {

	/**
	 * The app id portaliq attributes the accounts and claims to.
	 */
	private const APP_ID = 'learniq';

	/**
	 * Portaliq's app id.
	 */
	private const PORTALIQ_APP_ID = 'portaliq';

	/**
	 * Portaliq's provision event.
	 */
	public const PROVISION_EVENT = 'OCA\\Portaliq\\Event\\PortalAccountProvisionRequestedEvent';

	/**
	 * Portaliq's claim event.
	 */
	public const CLAIM_EVENT = 'OCA\\Portaliq\\Event\\PortalAccountClaimRequestedEvent';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher          $dispatcher          Dispatches portaliq's typed events.
	 * @param IAppManager               $appManager          Whether portaliq is installed.
	 * @param ExamplePortalContent      $content             Reads the portal and its accounts.
	 * @param ExamplePortalDeclarations $declarations        The per-set declarations.
	 * @param LoggerInterface           $logger              Records what happened.
	 * @param string                    $provisionEventClass The provision event class (overridable in tests).
	 * @param string                    $claimEventClass     The claim event class (overridable in tests).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppManager $appManager,
		private readonly ExamplePortalContent $content,
		private readonly ExamplePortalDeclarations $declarations,
		private readonly LoggerInterface $logger,
		private readonly string $provisionEventClass=self::PROVISION_EVENT,
		private readonly string $claimEventClass=self::CLAIM_EVENT,
	) {
	}//end __construct()

	/**
	 * Give every declared account with a `portal` entry its portal account.
	 *
	 * - `granted`: portaliq wrote the account or a missing claim.
	 * - `kept`: the account already had the audience and every claim; nothing
	 *   was asked, so a second load writes nothing.
	 * - `waiting`: the portal has no organisation yet. Binding one is a
	 *   deployment step; load the set again after setting it.
	 * - `failed`: portaliq refused, with the reasons in `reasons`.
	 *
	 * `status` is `done`, `none` (nothing declared), `portaliq-absent` or
	 * `portaliq-too-old`.
	 *
	 * @param string $profileId The example set id.
	 *
	 * @return array{status: string, granted: int, kept: int, waiting: int, failed: int, reasons: array<int, string>}
	 *
	 * @spec openspec/changes/example-portal-install-steps/specs/example-sets/spec.md#requirement-loading-a-set-gives-its-declared-learners-a-portal-account
	 */
	public function grant(string $profileId): array {
		$result      = ['status' => 'none', 'granted' => 0, 'kept' => 0, 'waiting' => 0, 'failed' => 0, 'reasons' => []];
		$declaration = $this->declarations->forSet(setId: $profileId);
		$wanted      = array_values(
			array_filter(
				(array)($declaration['accounts'] ?? []),
				static fn ($account): bool => is_array($account) === true && is_array($account['portal'] ?? null) === true
			)
		);
		if ($declaration === null || $wanted === []) {
			return $result;
		}

		if ($this->appManager->isInstalled(self::PORTALIQ_APP_ID) === false) {
			return ['status' => 'portaliq-absent'] + $result;
		}

		// The Nextcloud provisioning came with portaliq #1381; an older event has no getNextcloudUid().
		if (class_exists($this->provisionEventClass) === false || class_exists($this->claimEventClass) === false
			|| method_exists($this->provisionEventClass, 'getNextcloudUid') === false
		) {
			return ['status' => 'portaliq-too-old'] + $result;
		}

		$result['status'] = 'done';
		$slug   = (string)($declaration['portal']['slug'] ?? '');
		$portal = $this->content->findOne(schema: 'portal', match: static fn (array $row): bool => ($row['slug'] ?? null) === $slug);
		$organisation = trim((string)($portal['organisation'] ?? ''));

		foreach ($wanted as $account) {
			try {
				$outcome = $this->one(account: $account, slug: $slug, organisation: $organisation);
			} catch (Throwable $exception) {
				$outcome = 'unavailable';
				$this->logger->warning(
					'[ExamplePortalAccountGrants] portal account for "{user}" failed: {msg}',
					['user' => (string)($account['userId'] ?? ''), 'msg' => $exception->getMessage()]
				);
			}

			if (in_array($outcome, ['granted', 'kept', 'waiting'], true) === true) {
				$result[$outcome]++;
				continue;
			}

			$result['failed']++;
			$result['reasons'][] = (string)($account['userId'] ?? '') . ': ' . $outcome;
		}//end foreach

		return $result;
	}//end grant()

	/**
	 * One account: kept when it is complete, else provisioned and claimed.
	 *
	 * @param array<string, mixed> $account      The declared account.
	 * @param string               $slug         The portal slug.
	 * @param string               $organisation The portal's organisation, or ''.
	 *
	 * @return string `granted`, `kept`, `waiting` or a refusal reason.
	 */
	private function one(array $account, string $slug, string $organisation): string {
		$uid      = trim((string)($account['userId'] ?? ''));
		$audience = trim((string)($account['portal']['audience'] ?? ''));
		$claims   = array_filter((array)($account['portal']['claims'] ?? []), static fn ($value): bool => is_string($value) === true && $value !== '');
		if ($uid === '' || $audience === '') {
			return 'refused';
		}

		$stored  = $this->content->findOne(
			schema: 'portalAccount',
			match: static fn (array $row): bool => ($row['subjectRef'] ?? null) === $uid && ($row['audience'] ?? null) === $audience
		);
		$missing = self::missingClaims(stored: $stored, claims: $claims);
		if ($stored !== null && ($stored['status'] ?? '') === 'active' && $missing === []) {
			return 'kept';
		}

		if ($organisation === '') {
			return 'waiting';
		}

		if ($stored === null || ($stored['status'] ?? '') !== 'active') {
			$event = new ($this->provisionEventClass)(
				appId: self::APP_ID,
				audience: $audience,
				organisation: $organisation,
				displayName: (string)($account['displayName'] ?? ''),
				nextcloudUid: $uid,
				portal: $slug,
			);
			$this->dispatch(event: $event);
			if ($event->getRefusal() !== '') {
				return $event->getRefusal();
			}

			if ($event->getSubjectRef() !== $uid || $event->getStatus() !== 'active') {
				return 'not_active';
			}
		}

		foreach ($missing as $name => $value) {
			$claim = new ($this->claimEventClass)(appId: self::APP_ID, subjectRef: $uid, claimName: $name, value: $value);
			$this->dispatch(event: $claim);
			if ($claim->getResult() !== 'ok') {
				return 'claim-refused';
			}
		}

		return 'granted';
	}//end one()

	/**
	 * The declared claims the stored account does not carry with that value.
	 *
	 * @param array<string, mixed>|null $stored The stored account, or null.
	 * @param array<string, string>     $claims The declared claims.
	 *
	 * @return array<string, string>
	 */
	private static function missingClaims(?array $stored, array $claims): array {
		$have = (array)(($stored['claims'] ?? [])[self::APP_ID] ?? []);

		return array_filter(
			$claims,
			static fn (string $value, string $name): bool => ($have[$name] ?? null) !== $value,
			ARRAY_FILTER_USE_BOTH
		);
	}//end missingClaims()

	/**
	 * Dispatch one of portaliq's typed events.
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
}//end class
