<?php

/**
 * ContactHoursCalendar: teaching days, holidays and proration by teaching weeks.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Timetabling
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
 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Timetabling;

use OCA\Learniq\Timetabling\ContactHoursCalendar;
use PHPUnit\Framework\TestCase;

/**
 * The date arithmetic of the report.
 */
class ContactHoursCalendarTest extends TestCase {

	/**
	 * Weekends and holidays are not teaching days.
	 *
	 * @return void
	 */
	public function testTeachingDaysLeaveOutWeekendsAndHolidays(): void {
		$calendar = new ContactHoursCalendar();

		self::assertSame(5, $calendar->teachingDays('2026-10-12', '2026-10-18', []));
		self::assertSame(3, $calendar->teachingDays('2026-10-12', '2026-10-18', ['2026-10-12' => true, '2026-10-13' => true]));
	}//end testTeachingDaysLeaveOutWeekendsAndHolidays()

	/**
	 * A line without a period counts by the share of teaching days, holidays excluded.
	 *
	 * @return void
	 */
	public function testWholeYearLineIsProratedByTeachingDays(): void {
		$periods = [
			['periodCode' => '1', 'startDate' => '2026-09-07', 'endDate' => '2026-09-18', 'holidays' => [['name' => 'Studieweek', 'startDate' => '2026-09-14', 'endDate' => '2026-09-18']]],
			['periodCode' => '2', 'startDate' => '2026-09-21', 'endDate' => '2026-10-02', 'holidays' => []],
		];

		$share = (new ContactHoursCalendar())->share(null, $periods, '2026-2027', '2026-09-07', '2026-09-18');

		self::assertEqualsWithDelta(5 / 15, $share, 0.0001);
	}//end testWholeYearLineIsProratedByTeachingDays()

	/**
	 * A period line counts fully only when its period lies inside the window.
	 *
	 * @return void
	 */
	public function testPeriodLineCountsWhenTheWholePeriodIsInside(): void {
		$periods = [['periodCode' => '1', 'startDate' => '2026-09-01', 'endDate' => '2026-11-06']];
		$calendar = new ContactHoursCalendar();

		self::assertSame(1.0, $calendar->share('1', $periods, '2026-2027', '2026-08-01', '2026-12-31'));
		self::assertSame(0.0, $calendar->share('1', $periods, '2026-2027', '2026-10-01', '2026-12-31'));
		self::assertSame(0.0, $calendar->share('9', $periods, '2026-2027', '2026-08-01', '2026-12-31'));
		self::assertSame(1.5, $calendar->hours('2026-09-01T09:00:00+02:00', '2026-09-01T10:30:00+02:00'));
	}//end testPeriodLineCountsWhenTheWholePeriodIsInside()
}//end class
