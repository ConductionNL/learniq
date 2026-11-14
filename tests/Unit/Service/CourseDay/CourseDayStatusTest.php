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

use OCA\Learniq\Service\CourseDay\CourseDayStatus;
use PHPUnit\Framework\TestCase;

/**
 * The course day status rules.
 *
 * @covers \OCA\Learniq\Service\CourseDay\CourseDayStatus
 */
class CourseDayStatusTest extends TestCase {

	/**
	 * Derive with defaults for a day in the future.
	 *
	 * @param array<string, mixed> $facts Overrides.
	 *
	 * @return string
	 */
	private static function dayStatusOf(array $facts): string {
		$facts += ['participants' => 0, 'capacity' => 12, 'minimum' => 6, 'decision' => null, 'dayDate' => '2026-10-22', 'today' => '2026-10-05', 'openResults' => false];
		return CourseDayStatus::derive(...$facts);
	}//end dayStatusOf()

	/**
	 * Four of six is open; the sixth booking makes it go ahead.
	 *
	 * @return void
	 */
	public function testTheSixthBookingMakesTheDayGoAhead(): void {
		self::assertSame('open', self::dayStatusOf(['participants' => 4]));
		self::assertSame('open', self::dayStatusOf(['participants' => 5]));
		self::assertSame('goes-ahead', self::dayStatusOf(['participants' => 6]));
	}//end testTheSixthBookingMakesTheDayGoAhead()

	/**
	 * At the maximum the day is full, ahead of goes-ahead.
	 *
	 * @return void
	 */
	public function testAtTheMaximumTheDayIsFull(): void {
		self::assertSame('full', self::dayStatusOf(['participants' => 12]));
		self::assertSame('goes-ahead', self::dayStatusOf(['participants' => 11]));
		self::assertSame('goes-ahead', self::dayStatusOf(['participants' => 3, 'capacity' => null, 'minimum' => 3]));
	}//end testAtTheMaximumTheDayIsFull()

	/**
	 * A go decision makes an undersubscribed day go ahead; no-go cancels whatever else holds.
	 *
	 * @return void
	 */
	public function testTheDecisionOverridesTheCount(): void {
		self::assertSame('goes-ahead', self::dayStatusOf(['participants' => 2, 'decision' => 'go']));
		self::assertSame('cancelled', self::dayStatusOf(['participants' => 12, 'decision' => 'no-go']));
		self::assertSame('cancelled', self::dayStatusOf(['decision' => 'no-go', 'dayDate' => '2026-09-01', 'openResults' => true]));
	}//end testTheDecisionOverridesTheCount()

	/**
	 * A past day rounds off while results are open, then is done; today is not past.
	 *
	 * @return void
	 */
	public function testAPastDayRoundsOffThenIsDone(): void {
		self::assertSame('rounding-off', self::dayStatusOf(['participants' => 9, 'dayDate' => '2026-10-04', 'openResults' => true]));
		self::assertSame('done', self::dayStatusOf(['participants' => 9, 'dayDate' => '2026-10-04']));
		self::assertSame('goes-ahead', self::dayStatusOf(['participants' => 9, 'dayDate' => '2026-10-05']));
	}//end testAPastDayRoundsOffThenIsDone()

	/**
	 * Without a minimum or a date a day stays open until decided.
	 *
	 * @return void
	 */
	public function testWithoutAMinimumADayIsOpenUntilDecided(): void {
		self::assertSame('open', self::dayStatusOf(['participants' => 5, 'minimum' => null, 'dayDate' => null]));
	}//end testWithoutAMinimumADayIsOpenUntilDecided()
}//end class
