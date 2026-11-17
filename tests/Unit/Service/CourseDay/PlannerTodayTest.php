<?php

/**
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CourseDay;

use OCA\Learniq\Service\CourseDay\PlannerToday;
use PHPUnit\Framework\TestCase;

/**
 * When a course day asks the planner for a decision, and how far the occupancy runs.
 *
 * @covers \OCA\Learniq\Service\CourseDay\PlannerToday
 */
class PlannerTodayTest extends TestCase {

	/**
	 * Hybride warmtepomp on 22 October: 4 of minimum 6, decide by Thursday 8 October.
	 *
	 * @param array<string, mixed> $changes Changes to the day.
	 *
	 * @return array{date: string|null, decision: string|null, minimum: int|null, participants: int, decideBy: string|null}
	 */
	private static function day(array $changes=[]): array {
		return array_merge(['date' => '2026-10-22', 'decision' => null, 'minimum' => 6, 'participants' => 4, 'decideBy' => '2026-10-08'], $changes);
	}//end day()

	public function testWorkingDaysSkipTheWeekend(): void {
		// Monday 5 October to Thursday 8 October: Tue, Wed, Thu.
		self::assertSame(3, PlannerToday::workingDaysUntil(today: '2026-10-05', date: '2026-10-08'));
		// Friday 9 October to Monday 12 October: only the Monday.
		self::assertSame(1, PlannerToday::workingDaysUntil(today: '2026-10-09', date: '2026-10-12'));
		self::assertSame(0, PlannerToday::workingDaysUntil(today: '2026-10-08', date: '2026-10-08'));
		self::assertSame(-2, PlannerToday::workingDaysUntil(today: '2026-10-08', date: '2026-10-06'));
		self::assertNull(PlannerToday::workingDaysUntil(today: '2026-10-08', date: '8 oktober'));
		self::assertNull(PlannerToday::workingDaysUntil(today: '2026-10-08', date: '2026-02-30'));
	}//end testWorkingDaysSkipTheWeekend()

	public function testTheOccupancyRunsToTheFridayTwoWeeksOn(): void {
		self::assertSame('2026-10-23', PlannerToday::occupancyUntil(today: '2026-10-05'));
		self::assertSame('2026-10-23', PlannerToday::occupancyUntil(today: '2026-10-09'));
		self::assertSame('2026-10-23', PlannerToday::occupancyUntil(today: '2026-10-11'));
		self::assertSame('2026-10-30', PlannerToday::occupancyUntil(today: '2026-10-12'));
		self::assertNull(PlannerToday::occupancyUntil(today: ''));
	}//end testTheOccupancyRunsToTheFridayTwoWeeksOn()

	public function testFourOfSixWithTheDecisionThreeWorkingDaysAwayAsksForADecision(): void {
		self::assertTrue(PlannerToday::needsDecision(day: self::day(), today: '2026-10-05'));
	}//end testFourOfSixWithTheDecisionThreeWorkingDaysAwayAsksForADecision()

	public function testAPassedDecisionDateStillAsksWhileTheDayIsToCome(): void {
		self::assertTrue(PlannerToday::needsDecision(day: self::day(), today: '2026-10-12'));
	}//end testAPassedDecisionDateStillAsksWhileTheDayIsToCome()

	public function testADecisionFurtherAwayWaits(): void {
		// Friday 2 October to Thursday 8 October is 4 working days.
		self::assertFalse(PlannerToday::needsDecision(day: self::day(), today: '2026-10-02'));
	}//end testADecisionFurtherAwayWaits()

	public function testNoDecisionIsAskedWhenTheFactsDoNotCallForOne(): void {
		$today = '2026-10-05';
		self::assertFalse(PlannerToday::needsDecision(day: self::day(['participants' => 6]), today: $today));
		self::assertFalse(PlannerToday::needsDecision(day: self::day(['decision' => 'go']), today: $today));
		self::assertFalse(PlannerToday::needsDecision(day: self::day(['decision' => 'no-go']), today: $today));
		self::assertFalse(PlannerToday::needsDecision(day: self::day(['minimum' => null]), today: $today));
		self::assertFalse(PlannerToday::needsDecision(day: self::day(['minimum' => 0]), today: $today));
		self::assertFalse(PlannerToday::needsDecision(day: self::day(['decideBy' => null]), today: $today));
		self::assertFalse(PlannerToday::needsDecision(day: self::day(['date' => null]), today: $today));
		self::assertFalse(PlannerToday::needsDecision(day: self::day(['date' => '2026-10-04']), today: $today));
	}//end testNoDecisionIsAskedWhenTheFactsDoNotCallForOne()
}//end class
