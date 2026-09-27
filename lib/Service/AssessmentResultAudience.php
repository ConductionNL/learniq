<?php

/**
 * Learniq AssessmentResult Audience
 *
 * Stamps who may read an attempt onto the AssessmentResult itself when it is
 * created: `teacherIds` (the teachers of the assessment's course) and
 * `managerId` (the learner's manager, from LearnerProfile.managerId).
 * AssessmentResult's `authorization` block grants read to exactly those
 * people plus the learner, because an OpenRegister `match` can only compare
 * a field on the object itself with the caller (learniq#949).
 *
 * Who counts as "the course's teachers": the `teacherIds` of the cohort the
 * Assessment is scoped to when it names one, otherwise of every cohort of the
 * Assessment's course.
 *
 * Posture: the stamp always overwrites what the client sent, so a learner
 * cannot widen their own audience at create time. When a lookup fails the
 * audience is stamped EMPTY, which leaves the learner and admins; it never
 * blocks the attempt and never widens access.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/assessment/spec.md#requirement-assessment-results-are-read-by-the-learner-their-manager-and-the-courses-teachers
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps teacherIds and managerId on a new AssessmentResult. Called by
 * AssessmentAttemptGateListener for every AssessmentResult create it lets
 * through (admins included), so the audience is always server-set.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-assessment-results-are-read-by-the-learner-their-manager-and-the-courses-teachers
 */
class AssessmentResultAudience {

	private const LEARNIQ_REGISTER = 'learniq';
	private const ASSESSMENT_SCHEMA = 'exam';
	private const COHORT_SCHEMA = 'cohort';
	private const PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
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
	 * Stamp the audience of the AssessmentResult being created, merging with
	 * data other listeners already set.
	 *
	 * @param ObjectCreatingEvent $event The AssessmentResult creating event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-assessment-results-are-read-by-the-learner-their-manager-and-the-courses-teachers
	 */
	public function stamp(ObjectCreatingEvent $event): void {
		$payload = $event->getObject()->jsonSerialize();
		$audience = ['teacherIds' => [], 'managerId' => null];
		try {
			$audience = [
				'teacherIds' => $this->courseTeachers(assessmentId: (string)($payload['assessmentId'] ?? '')),
				'managerId' => $this->learnerManager(learnerId: (string)($payload['learnerId'] ?? '')),
			];
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[AssessmentResultAudience] Could not resolve the audience, stamping it empty: {msg}',
				['msg' => $exception->getMessage()]
			);
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $audience));
	}//end stamp()

	/**
	 * The teachers of the Assessment's cohort, or of every cohort of its course.
	 *
	 * @param string $assessmentId UUID of the Assessment.
	 *
	 * @return array<int, string>
	 */
	private function courseTeachers(string $assessmentId): array {
		if ($assessmentId === '') {
			return [];
		}

		$assessment = $this->objectService->find(
			id: $assessmentId,
			register: self::LEARNIQ_REGISTER,
			schema: self::ASSESSMENT_SCHEMA,
			_rbac: false
		);
		if ($assessment === null) {
			return [];
		}

		$row = $assessment->jsonSerialize();
		$cohortId = (string)($row['cohortId'] ?? '');
		$courseId = (string)($row['courseId'] ?? '');
		$cohorts = [];
		if ($cohortId !== '') {
			$cohort = $this->objectService->find(
				id: $cohortId,
				register: self::LEARNIQ_REGISTER,
				schema: self::COHORT_SCHEMA,
				_rbac: false
			);
			$cohorts = array_filter([$cohort]);
		} elseif ($courseId !== '') {
			$cohorts = $this->objectService->findAll(
				[
					'filters' => [
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::COHORT_SCHEMA,
						'courseId' => $courseId,
					],
				],
				_rbac: false
			);
		}

		return $this->collectTeachers(cohorts: $cohorts);
	}//end courseTeachers()

	/**
	 * The learner's manager from their LearnerProfile, or null.
	 *
	 * @param string $learnerId NC user id of the learner.
	 *
	 * @return string|null
	 */
	private function learnerManager(string $learnerId): ?string {
		if ($learnerId === '') {
			return null;
		}

		$profiles = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::PROFILE_SCHEMA,
					'ncUserId' => $learnerId,
				],
				'limit' => 1,
			],
			_rbac: false
		);
		if (empty($profiles) === true) {
			return null;
		}

		$profile = $this->toRow(object: $profiles[0]);
		$managerId = ($profile['managerId'] ?? null);
		if (is_string($managerId) === false || $managerId === '') {
			return null;
		}

		return $managerId;
	}//end learnerManager()

	/**
	 * The distinct teacher ids across the given cohorts.
	 *
	 * @param array<int|string, mixed> $cohorts Cohort rows or entities.
	 *
	 * @return array<int, string>
	 */
	private function collectTeachers(array $cohorts): array {
		$teachers = [];
		foreach ($cohorts as $cohort) {
			foreach ((array)($this->toRow(object: $cohort)['teacherIds'] ?? []) as $teacherId) {
				if (is_string($teacherId) === true && $teacherId !== '') {
					$teachers[$teacherId] = true;
				}
			}
		}

		return array_keys($teachers);
	}//end collectTeachers()

	/**
	 * Normalise an ObjectService result (array or entity) to a plain array.
	 *
	 * @param mixed $object The result row.
	 *
	 * @return array<string, mixed>
	 */
	private function toRow(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		return $object->jsonSerialize();
	}//end toRow()
}//end class
