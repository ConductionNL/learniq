<?php

/**
 * Unit tests for SchoolYearSoFar.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Pupil;

use OCA\Learniq\Service\Calendar\SchoolYear as SchoolCalendarYear;
use OCA\Learniq\Service\Leave\LeaveCalendar;
use OCA\Learniq\Service\Pupil\SchoolYearSoFar;
use PHPUnit\Framework\TestCase;

/**
 * The school days held so far count from the school year's first day.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T2
 */
class SchoolYearSoFarTest extends TestCase {

	/**
	 * Monday 26 October counts from 1 August; a value that is no date counts nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T2
	 */
	public function testTheSchoolDaysFromTheYearsFirstDay(): void {
		$calendar = $this->createMock(originalClassName: LeaveCalendar::class);
		$calendar->expects($this->once())->method('schoolDays')->with('2026-08-01', '2026-10-26')->willReturn(45);

		$year = new SchoolYearSoFar(calendar: $calendar, schoolYear: new SchoolCalendarYear());

		self::assertSame(expected: '08-01', actual: $year->start());
		self::assertSame(expected: 45, actual: $year->schoolDaysUpTo(today: '2026-10-26'));
		self::assertNull(actual: $year->schoolDaysUpTo(today: 'monday'));
	}//end testTheSchoolDaysFromTheYearsFirstDay()
}//end class
