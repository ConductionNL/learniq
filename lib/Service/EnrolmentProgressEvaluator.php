<?php

/**
 * Learniq Enrolment Progress Evaluator
 *
 * Stateless calculation engine computing an Enrolment's progressPercent from
 * its declared completedLessonCount/totalPublishedLessonCount aggregate-refs.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata."
 * No division operator exists in this register's JSON-logic calculation DSL
 * (verified by a full scan of every x-openregister-calculations expression
 * in lib/Settings/learniq_register.json — see proposal.md) — a small PHP
 * class is genuinely needed, the same shape of gap FinalGrade.value /
 * GradeFormulaEvaluator already solved. Single responsibility: evaluate ->
 * return; no state, no audit writes.
 *
 * Consumed by:
 *   - EnrolmentProgressRollupHandler (via ObjectCreatedEvent<LessonCompletion>)
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Computes progressPercent = round(completedLessonCount / totalPublishedLessonCount * 100).
 */
class EnrolmentProgressEvaluator {

	private const LEARNIQ_REGISTER = 'learniq';
	private const LESSON_SCHEMA = 'lesson';
	private const LESSON_COMPLETION_SCHEMA = 'lesson-completion';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Evaluate progressPercent for a learner + course.
	 *
	 * Counts the lessons the learner completed and the course's published
	 * Lessons, then computes a null-safe percentage: 0 (never a
	 * divide-by-zero error) when either count is 0.
	 *
	 * Given the Enrolment, only that enrolment's completions count
	 * (learniq#945): a row whose enrolmentId names it, or a row with no
	 * enrolmentId completed after the enrolment was created. A row tied to
	 * an earlier enrolment never counts, so a retake starts at zero. Each
	 * lesson counts once. Without an Enrolment every row of the learner for
	 * the course counts, as before.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $courseId UUID of the Course.
	 * @param array<string, mixed> $enrolment The Enrolment being rolled up, or [].
	 *
	 * @return array{progressPercent: int, completedLessonCount: int, totalPublishedLessonCount: int}
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-is-null-safe-before-any-lesson-completes
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
	 */
	public function evaluate(string $learnerId, string $courseId, array $enrolment = []): array {
		$completedLessonCount = $this->countCompletedLessons(learnerId: $learnerId, courseId: $courseId, enrolment: $enrolment);
		$publishedCount = $this->countPublishedLessons(courseId: $courseId);

		if ($completedLessonCount === 0 || $publishedCount === 0) {
			return [
				'progressPercent' => 0,
				'completedLessonCount' => $completedLessonCount,
				'totalPublishedLessonCount' => $publishedCount,
			];
		}

		$progressPercent = (int)round(($completedLessonCount / $publishedCount) * 100);

		return [
			'progressPercent' => $progressPercent,
			'completedLessonCount' => $completedLessonCount,
			'totalPublishedLessonCount' => $publishedCount,
		];

	}//end evaluate()

	/**
	 * Count the distinct lessons the learner completed for a course, scoped to
	 * the Enrolment when one is given (see evaluate()).
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $courseId UUID of the Course.
	 * @param array<string, mixed> $enrolment The Enrolment, or [].
	 *
	 * @return int
	 *
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
	 */
	private function countCompletedLessons(string $learnerId, string $courseId, array $enrolment): int {
		$results = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::LESSON_COMPLETION_SCHEMA,
					'learnerId' => $learnerId,
					'courseId' => $courseId,
				],
			]
		);

		if ($enrolment === []) {
			return count($results);
		}

		$lessons = [];
		foreach ($results as $row) {
			if (is_array($row) === false) {
				$row = $row->jsonSerialize();
			}

			if ($this->belongsToEnrolment(completion: $row, enrolment: $enrolment) === true) {
				$lessons[(string)($row['lessonId'] ?? ($row['id'] ?? ''))] = true;
			}
		}

		return count($lessons);
	}//end countCompletedLessons()

	/**
	 * Whether a LessonCompletion counts for an Enrolment: tied to it by id, or
	 * untied and completed after the enrolment was created.
	 *
	 * @param array<string, mixed> $completion The LessonCompletion row.
	 * @param array<string, mixed> $enrolment The Enrolment row.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
	 */
	public function belongsToEnrolment(array $completion, array $enrolment): bool {
		$enrolmentId = (string)($enrolment['id'] ?? ($enrolment['uuid'] ?? ''));
		$tiedTo = (string)($completion['enrolmentId'] ?? '');
		if ($tiedTo !== '') {
			return $enrolmentId !== '' && $tiedTo === $enrolmentId;
		}

		$started = strtotime((string)($enrolment['@self']['created'] ?? ($enrolment['created'] ?? '')));
		$completedAt = strtotime((string)($completion['completedAt'] ?? ''));
		if ($started === false || $completedAt === false) {
			return false;
		}

		return $completedAt >= $started;
	}//end countCompletedLessons()

	/**
	 * Count a course's published Lessons.
	 *
	 * @param string $courseId UUID of the Course.
	 *
	 * @return int
	 */
	private function countPublishedLessons(string $courseId): int {
		$results = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::LESSON_SCHEMA,
					'courseId' => $courseId,
					'lifecycle' => 'published',
				],
			]
		);

		return count($results);
	}//end countPublishedLessons()
}//end class
