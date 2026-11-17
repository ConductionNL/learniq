<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T1
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Exam;

use DateTimeImmutable;
use OCA\Learniq\Service\Exam\WorkingDays;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Exam\WorkingDays
 */
class WorkingDaysTest extends TestCase {

	/**
	 * Ten working days from Friday 25 September 2026 end on Friday 9 October.
	 *
	 * @return void
	 */
	public function testTenWorkingDaysFromAFridayEndTwoFridaysLater(): void {
		$end = WorkingDays::after(start: new DateTimeImmutable('2026-09-25T14:10:00+02:00'), days: 10);

		self::assertSame('2026-10-09', $end->format('Y-m-d'));
	}//end testTenWorkingDaysFromAFridayEndTwoFridaysLater()

	/**
	 * A weekend is skipped and the start day itself never counts.
	 *
	 * @return void
	 */
	public function testAWeekendIsSkipped(): void {
		self::assertSame('2026-09-28', WorkingDays::after(start: new DateTimeImmutable('2026-09-25'), days: 1)->format('Y-m-d'));
		self::assertSame('2026-09-28', WorkingDays::after(start: new DateTimeImmutable('2026-09-26'), days: 1)->format('Y-m-d'));
		self::assertSame('2026-09-29', WorkingDays::after(start: new DateTimeImmutable('2026-09-28'), days: 1)->format('Y-m-d'));
	}//end testAWeekendIsSkipped()

	/**
	 * A term below one counts as one working day.
	 *
	 * @return void
	 */
	public function testATermBelowOneCountsAsOne(): void {
		self::assertSame('2026-09-28', WorkingDays::after(start: new DateTimeImmutable('2026-09-25'), days: 0)->format('Y-m-d'));
	}//end testATermBelowOneCountsAsOne()
}//end class
