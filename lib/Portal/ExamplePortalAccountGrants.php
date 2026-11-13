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
	 * The answer of grant() before any account is counted.
	 */
	private const NO_GRANTS = ['status' => 'none', 'granted' => 0, 'kept' => 0, 'waiting' => 0, 'failed' => 0, 'reasons' => []];

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
		$declaration = $this->declarations->forSet(setId: $profileId);
		$wanted      = self::wanted(declaration: $declaration);
		$status      = $this->readiness(wanted: $wanted);
		if ($status !== 'done') {
			return ['status' => $status] + self::NO_GRANTS;
		}

		return $this->grantEach(wanted: $wanted, portal: $this->portalOf(declaration: $declaration));
	}//end grant()

	/**
	 * The declared portal with the organisation it is bound to ('' while unbound).
	 *
	 * @param array<string, mixed>|null $declaration The set's declaration.
	 *
	 * @return ExamplePortalTarget
	 */
	private function portalOf(?array $declaration): ExamplePortalTarget {
		$slug   = (string)($declaration['portal']['slug'] ?? '');
		$portal = $this->content->findOne(schema: 'portal', match: static fn (array $row): bool => ($row['slug'] ?? null) === $slug);

		return new ExamplePortalTarget(slug: $slug, organisation: trim((string)($portal['organisation'] ?? '')));
	}//end portalOf()

	/**
	 * Ask for every wanted account in turn and count the outcomes.
	 *
	 * @param array<int, array<string, mixed>> $wanted The accounts that ask for one.
	 * @param ExamplePortalTarget              $portal The portal.
	 *
	 * @return array{status: string, granted: int, kept: int, waiting: int, failed: int, reasons: array<int, string>}
	 */
	private function grantEach(array $wanted, ExamplePortalTarget $portal): array {
		$result = ['status' => 'done'] + self::NO_GRANTS;
		foreach ($wanted as $account) {
			$outcome = $this->safely(account: $account, portal: $portal);
			if (in_array($outcome, ['granted', 'kept', 'waiting'], true) === true) {
				$result[$outcome]++;
				continue;
			}

			$result['failed']++;
			$result['reasons'][] = (string)($account['userId'] ?? '') . ': ' . $outcome;
		}

		return $result;
	}//end grantEach()

	/**
	 * The declared accounts that ask for a portal account.
	 *
	 * @param array<string, mixed>|null $declaration The set's declaration.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function wanted(?array $declaration): array {
		return array_values(
			array_filter(
				(array)($declaration['accounts'] ?? []),
				static fn ($account): bool => is_array($account) === true && is_array($account['portal'] ?? null) === true
			)
		);
	}//end wanted()

	/**
	 * Whether portaliq can be asked: `done`, `none`, `portaliq-absent` or `portaliq-too-old`.
	 *
	 * @param array<int, array<string, mixed>> $wanted The accounts that ask for one.
	 *
	 * @return string
	 */
	private function readiness(array $wanted): string {
		if ($wanted === []) {
			return 'none';
		}

		if ($this->appManager->isInstalled(self::PORTALIQ_APP_ID) === false) {
			return 'portaliq-absent';
		}

		if ($this->portaliqProvisionsNextcloudAccounts() === false) {
			return 'portaliq-too-old';
		}

		return 'done';
	}//end readiness()

	/**
	 * One account, never throwing: an error is the outcome `unavailable`.
	 *
	 * @param array<string, mixed> $account The declared account.
	 * @param ExamplePortalTarget  $portal  The portal.
	 *
	 * @return string
	 */
	private function safely(array $account, ExamplePortalTarget $portal): string {
		try {
			return $this->one(account: $account, portal: $portal);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ExamplePortalAccountGrants] portal account for "{user}" failed: {msg}',
				['user' => (string)($account['userId'] ?? ''), 'msg' => $exception->getMessage()]
			);
			return 'unavailable';
		}
	}//end safely()

	/**
	 * One account: kept when it is complete, else provisioned and claimed.
	 *
	 * @param array<string, mixed> $account The declared account.
	 * @param ExamplePortalTarget  $portal  The portal.
	 *
	 * @return string `granted`, `kept`, `waiting` or a refusal reason.
	 */
	private function one(array $account, ExamplePortalTarget $portal): string {
		$request = new ExamplePortalAccountRequest(account: $account, portal: $portal);
		if ($request->isNamed() === false) {
			return 'refused';
		}

		return $this->settle(request: $request);
	}//end one()

	/**
	 * A named account: kept when it is active with every claim, waiting while
	 * the portal has no organisation, else provisioned when needed and claimed.
	 *
	 * @param ExamplePortalAccountRequest $request The declared account.
	 *
	 * @return string `granted`, `kept`, `waiting` or a refusal reason.
	 */
	private function settle(ExamplePortalAccountRequest $request): string {
		$stored  = $this->storedAccount(request: $request);
		$missing = self::missingClaims(stored: $stored, claims: $request->claims);
		if (($stored['status'] ?? '') === 'active' && $missing === []) {
			return 'kept';
		}

		if ($request->portal->organisation === '') {
			return 'waiting';
		}

		$refusal = $this->provisionUnlessActive(request: $request, stored: $stored);
		if ($refusal !== '') {
			return $refusal;
		}

		return $this->claim(uid: $request->uid, claims: $missing);
	}//end settle()

	/**
	 * The stored portal account for the request's user and audience, or null.
	 *
	 * @param ExamplePortalAccountRequest $request The declared account.
	 *
	 * @return array<string, mixed>|null
	 */
	private function storedAccount(ExamplePortalAccountRequest $request): ?array {
		$uid      = $request->uid;
		$audience = $request->audience;

		return $this->content->findOne(
			schema: 'portalAccount',
			match: static fn (array $row): bool => ($row['subjectRef'] ?? null) === $uid && ($row['audience'] ?? null) === $audience
		);
	}//end storedAccount()

	/**
	 * Provision the account unless the stored one is active; answers '' or the refusal.
	 *
	 * @param ExamplePortalAccountRequest $request The declared account.
	 * @param array<string, mixed>|null   $stored  The stored account, or null.
	 *
	 * @return string
	 */
	private function provisionUnlessActive(ExamplePortalAccountRequest $request, ?array $stored): string {
		if (($stored['status'] ?? '') === 'active') {
			return '';
		}

		return $this->provision(
			uid: $request->uid,
			audience: $request->audience,
			organisation: $request->portal->organisation,
			slug: $request->portal->slug,
			displayName: $request->displayName()
		);
	}//end provisionUnlessActive()

	/**
	 * Ask portaliq for the active `nextcloud` account; answers '' or the refusal.
	 *
	 * @param string $uid          The Nextcloud user id.
	 * @param string $audience     The audience.
	 * @param string $organisation The portal's organisation.
	 * @param string $slug         The portal slug.
	 * @param string $displayName  The name.
	 *
	 * @return string
	 */
	private function provision(string $uid, string $audience, string $organisation, string $slug, string $displayName): string {
		$event = new ($this->provisionEventClass)(
			appId: self::APP_ID,
			audience: $audience,
			organisation: $organisation,
			displayName: $displayName,
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

		return '';
	}//end provision()

	/**
	 * Write the missing claims; answers `granted` or `claim-refused`.
	 *
	 * @param string                $uid    The account's subjectRef.
	 * @param array<string, string> $claims The claims to write.
	 *
	 * @return string
	 */
	private function claim(string $uid, array $claims): string {
		foreach ($claims as $name => $value) {
			$event = new ($this->claimEventClass)(appId: self::APP_ID, subjectRef: $uid, claimName: $name, value: $value);
			$this->dispatch(event: $event);
			if ($event->getResult() !== 'ok') {
				return 'claim-refused';
			}
		}

		return 'granted';
	}//end claim()

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

	/**
	 * Whether portaliq's events exist and provision Nextcloud accounts.
	 *
	 * The Nextcloud provisioning came with portaliq #1381; an older event has no getNextcloudUid().
	 *
	 * @return bool
	 */
	private function portaliqProvisionsNextcloudAccounts(): bool {
		return class_exists($this->provisionEventClass) === true && class_exists($this->claimEventClass) === true
			&& method_exists($this->provisionEventClass, 'getNextcloudUid') === true;
	}//end portaliqProvisionsNextcloudAccounts()
}//end class
