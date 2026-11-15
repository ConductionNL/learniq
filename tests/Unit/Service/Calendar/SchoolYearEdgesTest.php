<?php

/**
 * SchoolYear: day-level edges of the school year setting.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Calendar;

use OCA\Learniq\Service\Calendar\SchoolYear;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Edges of the school year: its first day, a calendar-year school, leap days.
 *
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */
#[CoversClass(SchoolYear::class)]
class SchoolYearEdgesTest extends TestCase {

	/**
	 * A SchoolYear over a stored value.
	 *
	 * @param string $stored The stored `school_year_start`.
	 *
	 * @return SchoolYear
	 */
	private function year(string $stored): SchoolYear {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('learniq', SchoolYear::KEY, '08-01')->willReturn($stored);

		return new SchoolYear(appConfig: $config);
	}//end year()

	/**
	 * The start day itself is the first day of the new year; the day before is the old one.
	 *
	 * @return void
	 */
	public function testTheStartDayOpensTheNewYear(): void {
		$year = $this->year(stored: '09-01');

		self::assertSame('2026-2027', $year->yearOf(date: '2026-09-01'));
		self::assertSame('2025-2026', $year->yearOf(date: '2026-08-31'));
	}//end testTheStartDayOpensTheNewYear()

	/**
	 * A school year that starts on 1 January is the calendar year.
	 *
	 * @return void
	 */
	public function testASchoolYearStartingInJanuaryIsTheCalendarYear(): void {
		$year = $this->year(stored: '01-01');

		self::assertSame('2026-2027', $year->yearOf(date: '2026-01-01'));
		self::assertSame('2026-2027', $year->yearOf(date: '2026-12-31'));
		self::assertSame(['2026-01-01', '2026-12-31'], $year->bounds(academicYear: '2026-2027'));
	}//end testASchoolYearStartingInJanuaryIsTheCalendarYear()

	/**
	 * 29 February is no start day every year has, so it reads as 1 August.
	 *
	 * @return void
	 */
	public function testALeapDayIsNoUsableStart(): void {
		self::assertSame('08-01', $this->year(stored: '02-29')->start());
		self::assertSame('02-28', $this->year(stored: '02-28')->start());
	}//end testALeapDayIsNoUsableStart()

	/**
	 * Surrounding whitespace is ignored; a value without zero padding is not used.
	 *
	 * @return void
	 */
	public function testWhitespaceIsTrimmedButPaddingIsRequired(): void {
		self::assertSame('09-15', $this->year(stored: " 09-15\n")->start());
		self::assertSame('08-01', $this->year(stored: '9-15')->start());
		self::assertSame('08-01', $this->year(stored: '09/15')->start());
	}//end testWhitespaceIsTrimmedButPaddingIsRequired()

	/**
	 * A year that crosses a leap day still ends the day before the next start.
	 *
	 * @return void
	 */
	public function testAroundAYearWithALeapDay(): void {
		$year = $this->year(stored: '03-01');

		self::assertSame(['2027-03-01', '2028-02-29'], $year->around(date: '2028-02-29'));
		self::assertSame(['2028-03-01', '2029-02-28'], $year->around(date: '2028-03-01'));
	}//end testAroundAYearWithALeapDay()
}//end class
