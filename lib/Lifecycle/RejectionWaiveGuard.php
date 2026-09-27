<?php

/**
 * Learniq Rejection Waive Guard
 *
 * Lifecycle guard for the ExchangeRejection schema's `waive` transition
 * (`open|corrected` → `waived`). Mirrors PupilVoiceGuard's mandatory-reason
 * enforcement and MunicipalityFeedbackGuard's role-check + server-side-stamp
 * shape: requires a non-empty `waiveReason` and stamps `waivedBy`/`waivedAt`
 * server-side, never trusting a caller-supplied identity/timestamp for this
 * compliance-sensitive field.
 *
 * ADR-031 legitimate exception: this register has no declarative
 * field-scoped write-authorization extension, and no declarative mechanism
 * to express "block this transition unless a companion payload field is a
 * non-empty string" — a PHP guard is the only proven mechanism (same
 * rationale as PupilVoiceGuard's hoorrecht gate).
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the ExchangeRejection `open|corrected → waived` transition.
 *
 * The transition proceeds only when BOTH of the following hold:
 *   1. The acting user is in one of the authorised groups (`admin`, `coordinators`).
 *   2. `waiveReason` is a non-empty string. It arrives as a declared input
 *      of the waive transition, merged into the object before the guard runs.
 *
 * `waivedBy` (always the acting user, never a caller-supplied value) and
 * `waivedAt` (server clock) are stamped by StampTransitionActorAction, declared
 * on the same transition: OpenRegister calls guards by value, so a guard can
 * not write onto the object (learniq#983).
 *
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.4
 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-waiving-without-a-reason-is-refused
 */
class RejectionWaiveGuard implements LifecycleGuardInterface {

	/**
	 * Groups whose members may waive a rejection.
	 *
	 * @var string[]
	 */
	private const AUTHORISED_GROUPS = [
		'admin',
		'coordinators',
	];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager NC group manager to resolve the acting user's role groups.
	 * @param IUserManager $userManager User manager to resolve the acting user object for membership checks.
	 * @param LoggerInterface $logger PSR logger for guard rejections.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Assert the waive preconditions.
	 *
	 * Called by OpenRegister's LifecycleValidationListener before the
	 * `open|corrected → waived` transition is saved.
	 *
	 * @param array<string,mixed> $object The ExchangeRejection as it would be saved (status at `waived`, inputs merged).
	 * @param string              $action The transition action (`waive`).
	 * @param string              $userId The caller's uid, or '' without a session.
	 *
	 * @return GuardResult Allow, or deny with what is missing.
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.4
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$rejectionId = $object['id'] ?? ($object['uuid'] ?? '?');

		if ($userId === '') {
			$this->logger->warning(
				'[RejectionWaiveGuard] No session user — denying waive of {id}.',
				['id' => $rejectionId]
			);
			return GuardResult::deny('Only a signed-in admin or coordinator can waive a rejection.');
		}

		if ($this->actorIsAuthorised(actor: $userId) === false) {
			$this->logger->info(
				'[RejectionWaiveGuard] Actor {a} is not in an authorised group — denying waive of {id}.',
				['a' => $userId, 'id' => $rejectionId]
			);
			return GuardResult::deny('Only an admin or coordinator can waive a rejection.');
		}

		$waiveReason = $object['waiveReason'] ?? null;

		if (is_string($waiveReason) === false || trim($waiveReason) === '') {
			$this->logger->info(
				'[RejectionWaiveGuard] ExchangeRejection {id}: waiveReason is empty — denying waive.',
				['id' => $rejectionId]
			);
			return GuardResult::deny('A rejection can only be waived with a reason.');
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Whether the acting user is in one of the authorised groups.
	 *
	 * @param string $actor NC user ID of the requester.
	 *
	 * @return bool True when the user is in admin / coordinator.
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.4
	 */
	private function actorIsAuthorised(string $actor): bool {
		$user = $this->userManager->get($actor);
		if ($user === null) {
			return false;
		}

		$actorGroups = $this->groupManager->getUserGroupIds($user);

		return count(array_intersect($actorGroups, self::AUTHORISED_GROUPS)) > 0;
	}//end actorIsAuthorised()
}//end class
