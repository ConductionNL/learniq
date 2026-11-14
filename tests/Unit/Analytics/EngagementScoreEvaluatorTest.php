<?php

/**
 * Learniq EngagementScoreEvaluator unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Analytics
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
 * @spec openspec/specs/student-analytics/spec.md#requirement-persist-engagementscore-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Analytics;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Service\EngagementScoreEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EngagementScoreEvaluator::evaluate().
 */
class EngagementScoreEvaluatorTest extends TestCase {

	/**
	 * Build an evaluator whose ObjectService returns the given XapiStatements
	 * and published Lessons.
	 *
	 * @param array<int, array<string, mixed>> $statements XapiStatement rows.
	 * @param array<int, array<string, mixed>> $lessons Published Lesson rows.
	 *
	 * @return EngagementScoreEvaluator
	 */
	private function makeEvaluator(array $statements, array $lessons): EngagementScoreEvaluator {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($statements, $lessons) {
				if ($config['filters']['schema'] === 'xapi-statement') {
					return $statements;
				}

				if ($config['filters']['schema'] === 'lesson') {
					return $lessons;
				}

				return [];
			}
		);

		return new EngagementScoreEvaluator($objectService);
	}//end makeEvaluator()

	/**
	 * Multiple statements with duration extensions sum correctly.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/student-analytics/spec.md#scenario-time-on-task-accumulates-across-statements
	 */
	public function testMultipleStatementsSumDurations(): void {
		$statements = [
			['result' => ['duration' => 'PT10M'], 'timestamp' => '2026-07-01T09:00:00+02:00'],
			['result' => ['duration' => 'PT20M'], 'timestamp' => '2026-07-02T09:00:00+02:00'],
			['result' => ['duration' => 'PT30M'], 'timestamp' => '2026-07-03T09:00:00+02:00'],
		];

		$evaluator = $this->makeEvaluator(statements: $statements, lessons: [['durationMinutes' => 120]]);

		$result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

		self::assertSame(60.0, $result['timeOnTaskMinutes']);

	}//end testMultipleStatementsSumDurations()

	/**
	 * A statement with no duration extension contributes 0, not an error.
	 *
	 * @return void
	 */
	public function testStatementMissingDurationContributesZero(): void {
		$statements = [
			['result' => ['duration' => 'PT10M'], 'timestamp' => '2026-07-01T09:00:00+02:00'],
			['result' => [], 'timestamp' => '2026-07-02T09:00:00+02:00'],
			['timestamp' => '2026-07-03T09:00:00+02:00'],
		];

		$evaluator = $this->makeEvaluator(statements: $statements, lessons: [['durationMinutes' => 120]]);

		$result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

		self::assertSame(10.0, $result['timeOnTaskMinutes']);

	}//end testStatementMissingDurationContributesZero()

	/**
	 * lastActivityAt resolves to the latest statement's timestamp.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/student-analytics/spec.md#scenario-engagementscore-objects-persist-and-recompute-from-xapi-activity
	 */
	public function testLastActivityAtResolvesToLatest(): void {
		$statements = [
			['result' => ['duration' => 'PT5M'], 'timestamp' => '2026-07-03T09:00:00+02:00'],
			['result' => ['duration' => 'PT5M'], 'timestamp' => '2026-07-01T09:00:00+02:00'],
			['result' => ['duration' => 'PT5M'], 'timestamp' => '2026-07-02T09:00:00+02:00'],
		];

		$evaluator = $this->makeEvaluator(statements: $statements, lessons: []);

		$result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

		self::assertSame('2026-07-03T09:00:00+02:00', $result['lastActivityAt']);

	}//end testLastActivityAtResolvesToLatest()

	/**
	 * score is bounded to [0, 100] even for very high time-on-task.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/student-analytics/spec.md#scenario-engagementscore-objects-persist-and-recompute-from-xapi-activity
	 */
	public function testScoreBoundedToRange(): void {
		$statements = [
			['result' => ['duration' => 'PT100H'], 'timestamp' => '2026-07-03T09:00:00+02:00'],
		];

		$evaluator = $this->makeEvaluator(statements: $statements, lessons: [['durationMinutes' => 10]]);

		$result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

		self::assertGreaterThanOrEqual(0, $result['score']);
		self::assertLessThanOrEqual(100, $result['score']);

	}//end testScoreBoundedToRange()

	/**
	 * No activity at all yields a zero score and null lastActivityAt, never
	 * an error.
	 *
	 * @return void
	 */
	public function testNoActivityYieldsZeroScore(): void {
		$evaluator = $this->makeEvaluator(statements: [], lessons: [['durationMinutes' => 60]]);

		$result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

		self::assertSame(0.0, $result['timeOnTaskMinutes']);
		self::assertNull($result['lastActivityAt']);
		self::assertSame(0, $result['score']);

	}//end testNoActivityYieldsZeroScore()

	/**
	 * The recency factor decays linearly over 14 days from the prior activity,
	 * holds at full for a first, earlier-dated or unreadable prior, and the
	 * score is 70 parts completion and 30 parts recency.
	 *
	 * @return void
	 */
	public function testTheScoreWeighsCompletionAndRecency(): void {
		$statements = [
			['result' => ['duration' => 'PT30M'], 'timestamp' => '2026-07-15T09:00:00+00:00'],
			['result' => ['duration' => 'PT15M'], 'timestamp' => ''],
			['result' => ['duration' => 'nonsense'], 'timestamp' => 20260720],
			['timestamp' => null],
		];
		$evaluator = $this->makeEvaluator(statements: $statements, lessons: [['durationMinutes' => 60], ['durationMinutes' => '30'], []]);

		$cases = [
			[null, 65],
			['2026-07-08T09:00:00+00:00', 50],
			['2026-07-01T09:00:00+00:00', 35],
			['2026-06-01T09:00:00+00:00', 35],
			['2026-07-20T09:00:00+00:00', 65],
			['not a date', 65],
			['2026-07-15T09:00:00+00:00', 65],
		];
		foreach ($cases as [$previous, $score]) {
			$result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1', previousActivityAt: $previous);
			self::assertSame(['timeOnTaskMinutes' => 45.0, 'lastActivityAt' => '2026-07-15T09:00:00+00:00', 'score' => $score], $result, (string)$previous);
		}
	}//end testTheScoreWeighsCompletionAndRecency()

	/**
	 * A course without published lesson time scores on recency alone, and a
	 * learner without a dated statement scores no recency.
	 *
	 * @return void
	 */
	public function testNoLessonTimeAndNoDatedStatement(): void {
		$dated = $this->makeEvaluator(statements: [['result' => ['duration' => 'PT1H2M3S'], 'timestamp' => '2026-07-15T09:00:00+00:00']], lessons: []);
		self::assertSame(
			['timeOnTaskMinutes' => 62.05, 'lastActivityAt' => '2026-07-15T09:00:00+00:00', 'score' => 30],
			$dated->evaluate(learnerId: 'learner-1', courseId: 'course-1')
		);

		$undated = $this->makeEvaluator(statements: [['result' => ['duration' => 'P1DT1H']]], lessons: [['durationMinutes' => 10000]]);
		self::assertSame(
			['timeOnTaskMinutes' => 1500.0, 'lastActivityAt' => null, 'score' => 11],
			$undated->evaluate(learnerId: 'learner-1', courseId: 'course-1', previousActivityAt: '2026-07-01T09:00:00+00:00')
		);
	}//end testNoLessonTimeAndNoDatedStatement()
}//end class
