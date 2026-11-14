<?php

/**
 * LateMinutes unit tests: how late a learner was for one lesson.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Attendance
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

namespace OCA\Learniq\Tests\Unit\Service\Attendance;

use OCA\Learniq\Service\Attendance\LateMinutes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Minutes late: the recorded number, else the lesson's unattended minutes.
 */
#[CoversClass(LateMinutes::class)]
class LateMinutesTest extends TestCase {

	/**
	 * A recorded whole number wins over the attended minutes.
	 *
	 * @return void
	 */
	public function testARecordedNumberWins(): void {
		$late = new LateMinutes();

		self::assertSame(7, $late->minutesOf(record: ['lateMinutes' => 7, 'minutesAttended' => 10], session: ['minutes' => 50]));
		self::assertSame(12, $late->minutesOf(record: ['lateMinutes' => '12'], session: null));
		self::assertSame(0, $late->minutesOf(record: ['lateMinutes' => 0, 'minutesAttended' => 10], session: ['minutes' => 50]));
	}//end testARecordedNumberWins()

	/**
	 * A negative recorded number counts as zero.
	 *
	 * @return void
	 */
	public function testANegativeRecordedNumberIsZero(): void {
		self::assertSame(0, (new LateMinutes())->minutesOf(record: ['lateMinutes' => -5], session: ['minutes' => 50]));
	}//end testANegativeRecordedNumberIsZero()

	/**
	 * Without a usable recorded number the lesson's unattended minutes count.
	 *
	 * @return void
	 */
	public function testUnattendedMinutesFillIn(): void {
		$late = new LateMinutes();

		self::assertSame(15, $late->minutesOf(record: ['minutesAttended' => 35], session: ['minutes' => 50]));
		self::assertSame(20, $late->minutesOf(record: ['lateMinutes' => '5.5', 'minutesAttended' => '30'], session: ['minutes' => 50]));
		self::assertSame(10, $late->minutesOf(record: ['lateMinutes' => '-3', 'minutesAttended' => 40], session: ['minutes' => 50]));
		self::assertSame(0, $late->minutesOf(record: ['minutesAttended' => 60], session: ['minutes' => 50]));
	}//end testUnattendedMinutesFillIn()

	/**
	 * Without a lesson length or attended minutes, nobody was late.
	 *
	 * @return void
	 */
	public function testWithoutLengthOrAttendanceNobodyIsLate(): void {
		$late = new LateMinutes();

		self::assertSame(0, $late->minutesOf(record: ['minutesAttended' => 10], session: null));
		self::assertSame(0, $late->minutesOf(record: ['minutesAttended' => 10], session: ['minutes' => null]));
		self::assertSame(0, $late->minutesOf(record: [], session: ['minutes' => 50]));
		self::assertSame(0, $late->minutesOf(record: ['minutesAttended' => 'all'], session: ['minutes' => 50]));
	}//end testWithoutLengthOrAttendanceNobodyIsLate()
}//end class
