<?php

/**
 * Learniq Programme Publish Guard
 *
 * Lifecycle guard for the Programme schema's `publish` transition. Enforces that a
 * Programme has an assigned CurriculumPlan and that the plan is published with at
 * least one required course before the Programme itself may be published.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration."
 * Referenced from the Programme schema's x-openregister-lifecycle.transitions.publish.requires
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
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Programme `publish` transition.
 *
 * A Programme may only be published when:
 * 1. It has an assigned CurriculumPlan (curriculumPlanId is set).
 * 2. That CurriculumPlan is in lifecycle state `published`.
 * 3. The CurriculumPlan has at least one required course (requiredCourseIds is non-empty).
 */
class ProgrammePublishGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'A programme needs a published curriculum plan with at least one required course before it can be published.';

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object service for querying CurriculumPlans.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
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
	 * Called by OpenRegister's lifecycle engine before executing the `publish`
	 * transition on a Programme object. Returns true only when the Programme has
	 * an assigned published CurriculumPlan that lists at least one required course.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 *
	 * @return bool True if the Programme's CurriculumPlan is published and has ≥1 required
	 *              course; false blocks the transition.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-13
	 */
	private function allows(array $object): bool {
		$curriculumPlanId = $object['curriculumPlanId'] ?? null;
		$tenantId = $object['tenant_id'] ?? '';

		if ($curriculumPlanId === null) {
			$this->logger->info(
				'[ProgrammePublishGuard] Programme has no CurriculumPlan assigned; blocking publish.'
			);
			return false;
		}

		// H1: scope CurriculumPlan lookup to the same tenant.
		$planFilters = ['uuid' => $curriculumPlanId, 'lifecycle' => 'published'];
		if ($tenantId !== '') {
			$planFilters['tenant_id'] = $tenantId;
		}

		$plans = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => 'curriculum-plan',
				'filters' => $planFilters,
				'limit' => 1,
			]
		);

		if (empty($plans) === true) {
			$this->logger->info(
				'[ProgrammePublishGuard] CurriculumPlan {id} is not published; blocking Programme publish.',
				['id' => $curriculumPlanId]
			);
			return false;
		}

		$plan = $plans[0];
		$requiredCourseIds = $plan['requiredCourseIds'] ?? [];

		if (empty($requiredCourseIds) === true) {
			$this->logger->info(
				'[ProgrammePublishGuard] CurriculumPlan {id} has no required courses; blocking Programme publish.',
				['id' => $curriculumPlanId]
			);
			return false;
		}

		return true;
	}//end allows()
}//end class
