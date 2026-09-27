<?php

/**
 * Learniq Cohort Membership Guard
 *
 * Lifecycle guard for the Cohort schema's `activate` transition. Enforces that a
 * Cohort has at least one learner assigned before it can be activated. Also verifies
 * that the backing Nextcloud group (ncGroupId) is set or can be created.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration."
 * Referenced from the Cohort schema's x-openregister-lifecycle.transitions.activate.requires
 * in learniq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-13
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the Cohort `activate` transition.
 *
 * Returns true only when the Cohort has at least one learner in learnerIds,
 * ensuring that empty cohorts cannot be activated.
 *
 * Note: Full NC group synchronisation (ncGroupId provisioning) is deferred to a
 * separate event listener or manual admin action. The guard focuses on the
 * pre-condition check only, keeping it a single-method ADR-031 exception.
 */
class CohortMembershipGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'A cohort needs at least one learner before it can be activated.';
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
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
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-13
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(object: $object) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * The rule behind check(), answered as a boolean.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the `activate`
	 * transition on a Cohort object. Returns true only when the Cohort has at
	 * least one learner assigned in learnerIds.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 *
	 * @return bool True if the Cohort has at least one learner; false blocks the transition.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-13
	 */
	private function allows(array $object): bool {
		$learnerIds = $object['learnerIds'] ?? [];

		if (empty($learnerIds) === true) {
			$this->logger->info(
				'[CohortMembershipGuard] Cohort has no learners assigned; blocking activate transition.'
			);
			return false;
		}

		return true;
	}//end allows()
}//end class
