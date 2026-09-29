<?php

/**
 * Learniq Report Card Reopen Guard
 *
 * Lifecycle guard for the ReportCard schema's `reopen` transition
 * (finalised -> rapportvergadering-review). Restricted to admin/mentor/
 * principal — an explicit correction path before parent publication, never
 * a self-service action for a subject teacher.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema
 * declaration." Mirrors {@see ExternalTrainingVerificationGuard}'s
 * role-group-check shape. Referenced from the ReportCard schema's
 * x-openregister-lifecycle.transitions.reopen.requires in
 * learniq_register.json.
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
 * @spec openspec/specs/report-card/spec.md#scenario-a-mentor-reopens-a-finalised-report-card-to-correct-it-before-publication
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the ReportCard `reopen` (finalised -> rapportvergadering-review)
 * lifecycle transition.
 *
 * Allows the transition only when the acting user is in one of the
 * privileged groups (`admin`, `team-leads`, `administration-managers`).
 *
 * @spec openspec/specs/report-card/spec.md#requirement-the-rapportvergadering-review-lifecycle-gates-parent-visibility-behind-a-finalise-step
 */
class ReportCardReopenGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'Only an administrator, a team lead or an administration manager can reopen a finalised report card.';

	/**
	 * Groups whose members may reopen a finalised report card.
	 *
	 * @var string[]
	 */
	private const REOPEN_GROUPS = ['admin', 'team-leads', 'administration-managers'];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager OR/NC group manager to resolve the acting user's role groups.
	 * @param IUserManager $userManager User manager to resolve the acting user object for membership checks.
	 * @param LoggerInterface $logger PSR logger.
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
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-a-mentor-reopens-a-finalised-report-card-to-correct-it-before-publication
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(object: $object, userId: $userId) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * The rule behind check(), answered as a boolean.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $userId The uid of the caller.
	 *
	 * @return bool True when the actor holds admin/mentor/principal; false blocks it.
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-a-mentor-reopens-a-finalised-report-card-to-correct-it-before-publication
	 */
	private function allows(array $object, string $userId): bool {
		$actor = $userId;

		if ($actor === '') {
			$this->logger->warning('[ReportCardReopenGuard] No acting user — denying reopen.');
			return false;
		}

		$user = $this->userManager->get($actor);
		if ($user === null) {
			$this->logger->info(
				'[ReportCardReopenGuard] Actor {actor} could not be resolved — denying reopen.',
				['actor' => $actor]
			);
			return false;
		}

		$actorGroups = $this->groupManager->getUserGroupIds($user);

		if (count(array_intersect($actorGroups, self::REOPEN_GROUPS)) === 0) {
			$this->logger->info(
				'[ReportCardReopenGuard] ReportCard {id} reopen denied — actor {actor} holds no admin/mentor/principal role.',
				['id' => ($object['id'] ?? ($object['uuid'] ?? '')), 'actor' => $actor]
			);
			return false;
		}

		return true;
	}//end allows()
}//end class
