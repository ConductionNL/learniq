<?php

/**
 * Learniq Course Publish Guard
 *
 * Lifecycle guard for the Course schema's `publish` transition. Enforces that a
 * Course has at least one published Lesson before it may be published itself.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration."
 * Referenced from the Course schema's x-openregister-lifecycle.transitions.publish.requires
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
 * Guards the Course `publish` transition.
 *
 * Returns true only when the Course has at least one published Lesson, ensuring
 * learners cannot be enrolled onto a course with no available content.
 */
class CoursePublishGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'A course needs at least one published lesson before it can be published.';

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object service for querying Lessons.
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
	 * transition on a Course object. Returns true only when at least one
	 * published Lesson belongs to this Course.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 *
	 * @return bool True if the Course has at least one published Lesson; false blocks transition.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-13
	 */
	private function allows(array $object): bool {
		$courseId = $object['uuid'] ?? $object['id'] ?? null;
		$tenantId = $object['tenant_id'] ?? '';

		if ($courseId === null) {
			$this->logger->warning('[CoursePublishGuard] No course ID in transition context; blocking publish.');
			return false;
		}

		// H1: scope Lesson lookup to the same tenant.
		$lessonFilters = ['courseId' => $courseId, 'lifecycle' => 'published'];
		if ($tenantId !== '') {
			$lessonFilters['tenant_id'] = $tenantId;
		}

		$publishedLessons = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$lessonFilters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => 'lesson',
					]
				),
				'limit' => 1,
			]
		);

		if (empty($publishedLessons) === true) {
			$this->logger->info(
				'[CoursePublishGuard] Course {id} has no published Lessons; blocking publish transition.',
				['id' => $courseId]
			);
			return false;
		}

		return true;
	}//end allows()
}//end class
