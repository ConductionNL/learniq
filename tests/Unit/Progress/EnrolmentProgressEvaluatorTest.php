<?php

/**
 * Learniq EnrolmentProgressEvaluator unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Progress
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

namespace OCA\Learniq\Tests\Unit\Progress;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Service\EnrolmentProgressEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EnrolmentProgressEvaluator::evaluate().
 */
class EnrolmentProgressEvaluatorTest extends TestCase {

	/**
	 * Build an evaluator whose ObjectService returns the given completed and
	 * published-lesson counts for the (learnerId, courseId) pair.
	 *
	 * @param int $completedCount Number of LessonCompletion rows to return.
	 * @param int $publishedCount Number of published Lesson rows to return.
	 *
	 * @return EnrolmentProgressEvaluator
	 */
	private function makeEvaluator(int $completedCount, int $publishedCount): EnrolmentProgressEvaluator {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($completedCount, $publishedCount) {
				if ($config['filters']['schema'] === 'lesson-completion') {
					return array_fill(0, $completedCount, ['id' => 'x']);
				}

				if ($config['filters']['schema'] === 'lesson') {
					return array_fill(0, $publishedCount, ['id' => 'y']);
				}

				return [];
			}
		);

		return new EnrolmentProgressEvaluator($objectService);
	}//end makeEvaluator()

	/**
	 * A normal ratio computes the expected percentage.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-recomputes-when-a-lesson-is-completed
	 */
	public function testNormalRatio(): void {
		$evaluator = $this->makeEvaluator(completedCount: 4, publishedCount: 10);

		$result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

		self::assertSame(40, $result['progressPercent']);
		self::assertSame(4, $result['completedLessonCount']);
		self::assertSame(10, $result['totalPublishedLessonCount']);

	}//end testNormalRatio()

	/**
	 * Zero completions yields 0%, not an error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-is-null-safe-before-any-lesson-completes
	 */
	public function testZeroCompletions(): void {
		$evaluator = $this->makeEvaluator(completedCount: 0, publishedCount: 10);

		$result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

		self::assertSame(0, $result['progressPercent']);

	}//end testZeroCompletions()

	/**
	 * Zero published lessons yields 0%, never a divide-by-zero error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-is-null-safe-before-any-lesson-completes
	 */
	public function testZeroPublishedLessons(): void {
		$evaluator = $this->makeEvaluator(completedCount: 0, publishedCount: 0);

		$result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

		self::assertSame(0, $result['progressPercent']);
		self::assertSame(0, $result['totalPublishedLessonCount']);

	}//end testZeroPublishedLessons()

	/**
	 * A ratio that does not divide evenly is rounded.
	 *
	 * @return void
	 */
	public function testRatioRequiringRounding(): void {
		$evaluator = $this->makeEvaluator(completedCount: 1, publishedCount: 3);

		$result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

		// 1/3 * 100 = 33.33... -> rounds to 33.
		self::assertSame(33, $result['progressPercent']);

	}//end testRatioRequiringRounding()

	/**
	 * Full completion yields exactly 100%.
	 *
	 * @return void
	 */
	public function testFullCompletion(): void {
		$evaluator = $this->makeEvaluator(completedCount: 10, publishedCount: 10);

		$result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

		self::assertSame(100, $result['progressPercent']);

	}//end testFullCompletion()

	/**
	 * A retake counts only the completions of this enrolment: a row tied to
	 * an earlier enrolment never counts, a row with no enrolment counts only
	 * when it was completed after this enrolment started, and a lesson counts
	 * once (learniq#945).
	 *
	 * @return void
	 */
	public function testARetakeCountsOnlyThisEnrolmentsCompletions(): void {
		$rows = [
			['id' => 'c1', 'lessonId' => 'l1', 'enrolmentId' => 'enrol-1', 'completedAt' => '2026-03-01T10:00:00+00:00'],
			['id' => 'c2', 'lessonId' => 'l2', 'enrolmentId' => 'enrol-1', 'completedAt' => '2026-03-02T10:00:00+00:00'],
			['id' => 'c3', 'lessonId' => 'l3', 'completedAt' => '2026-03-03T10:00:00+00:00'],
			['id' => 'c4', 'lessonId' => 'l1', 'enrolmentId' => 'enrol-2', 'completedAt' => '2027-03-01T10:00:00+00:00'],
			['id' => 'c5', 'lessonId' => 'l2', 'completedAt' => '2027-03-02T10:00:00+00:00'],
			['id' => 'c6', 'lessonId' => 'l1', 'completedAt' => '2027-03-03T10:00:00+00:00'],
		];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($rows) {
				if ($config['filters']['schema'] === 'lesson-completion') {
					return $rows;
				}

				return array_fill(0, 4, ['id' => 'lesson']);
			}
		);
		$evaluator = new EnrolmentProgressEvaluator($objectService);

		$fresh = $evaluator->evaluate(
			learnerId: 'learner-1',
			courseId: 'course-1',
			enrolment: ['id' => 'enrol-3', '@self' => ['created' => '2028-01-01T00:00:00+00:00']]
		);
		self::assertSame(0, $fresh['completedLessonCount'], 'a brand-new enrolment starts at zero');
		self::assertSame(0, $fresh['progressPercent']);

		$retake = $evaluator->evaluate(
			learnerId: 'learner-1',
			courseId: 'course-1',
			enrolment: ['id' => 'enrol-2', '@self' => ['created' => '2027-01-01T00:00:00+00:00']]
		);
		self::assertSame(2, $retake['completedLessonCount'], 'l1 (tied, and again untied) and l2 (untied, after start)');
		self::assertSame(50, $retake['progressPercent']);

	}//end testARetakeCountsOnlyThisEnrolmentsCompletions()
}//end class
