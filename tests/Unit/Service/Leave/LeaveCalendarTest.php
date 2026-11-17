<?php

/**
 * LeaveCalendar unit tests
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Leave
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Leave;

use OCA\Learniq\Service\Calendar\SchoolYear;
use OCA\Learniq\Service\ContactHoursReader;
use OCA\Learniq\Service\Leave\LeaveCalendar;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * School days leave the weekends and the report periods' holidays out; the
 * school answers within the set number of school days, five by default.
 */
class LeaveCalendarTest extends TestCase {

	/**
	 * The school years asked for, in order.
	 *
	 * @var array<int, string>
	 */
	private array $askedYears = [];

	/**
	 * The calendar over the 2026-2027 herfstvakantie (19 to 23 October) and a stored deadline setting.
	 *
	 * @param string|null $setting The stored `leave_decision_school_days`; null when unset.
	 *
	 * @return LeaveCalendar
	 */
	private function calendar(?string $setting = null): LeaveCalendar {
		$reader = $this->createMock(ContactHoursReader::class);
		$reader->method('reportPeriods')->willReturnCallback(
			function (string $academicYear): array {
				$this->askedYears[] = $academicYear;
				if ($academicYear !== '2026-2027') {
					return [];
				}

				return [
					['academicYear' => '2026-2027', 'holidays' => [['name' => 'Herfstvakantie', 'startDate' => '2026-10-19', 'endDate' => '2026-10-23']]],
					['academicYear' => '2026-2027', 'holidays' => [['name' => 'Studiedag', 'startDate' => '2026-11-11'], ['startDate' => 'not a date']]],
				];
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($app === 'learniq' && $key === LeaveCalendar::KEY_DECISION_DAYS && $setting !== null ? $setting : $default)
		);

		return new LeaveCalendar(reader: $reader, schoolYear: new SchoolYear(), appConfig: $config);
	}//end calendar()

	/**
	 * Thursday and Friday are two school days; a week with a weekend is five.
	 *
	 * @return void
	 */
	public function testWeekdaysAreSchoolDays(): void {
		$calendar = $this->calendar();

		self::assertSame(2, $calendar->schoolDays(from: '2026-10-29', to: '2026-10-30'));
		self::assertSame(5, $calendar->schoolDays(from: '2026-11-02', to: '2026-11-08'));
		self::assertSame(0, $calendar->schoolDays(from: '2026-10-31', to: '2026-11-01'));
		self::assertSame(['2026-2027'], array_values(array_unique($this->askedYears)));
	}//end testWeekdaysAreSchoolDays()

	/**
	 * The herfstvakantie and a one-day study day are not school days.
	 *
	 * @return void
	 */
	public function testHolidaysAreNotSchoolDays(): void {
		$calendar = $this->calendar();

		self::assertSame(0, $calendar->schoolDays(from: '2026-10-19', to: '2026-10-23'));
		self::assertSame(4, $calendar->schoolDays(from: '2026-10-15', to: '2026-10-27'));
		self::assertSame(4, $calendar->schoolDays(from: '2026-11-09', to: '2026-11-13'));
	}//end testHolidaysAreNotSchoolDays()

	/**
	 * A range running backwards, or a date that is not a date, has no count.
	 *
	 * @return void
	 */
	public function testABadRangeHasNoCount(): void {
		$calendar = $this->calendar();

		self::assertNull($calendar->schoolDays(from: '2026-10-30', to: '2026-10-29'));
		self::assertNull($calendar->schoolDays(from: '2026-02-30', to: '2026-03-02'));
		self::assertNull($calendar->schoolDays(from: '', to: '2026-03-02'));
		self::assertNull($calendar->schoolDays(from: '29-10-2026', to: '2026-10-30'));
	}//end testABadRangeHasNoCount()

	/**
	 * Asked on Wednesday 30 September, five school days later is Wednesday 7 October.
	 *
	 * @return void
	 */
	public function testTheSchoolAnswersWithinFiveSchoolDays(): void {
		$calendar = $this->calendar();

		self::assertSame(5, $calendar->decisionDays());
		self::assertSame('2026-10-07', $calendar->decideBy(askedOn: '2026-09-30'));
		self::assertSame('2026-10-07', $calendar->decideBy(askedOn: '2026-09-30T14:12:00+00:00'));
		self::assertNull($calendar->decideBy(askedOn: 'yesterday'));
	}//end testTheSchoolAnswersWithinFiveSchoolDays()

	/**
	 * A deadline that runs into the herfstvakantie skips it.
	 *
	 * @return void
	 */
	public function testTheDeadlineSkipsTheHolidays(): void {
		self::assertSame('2026-10-30', $this->calendar()->decideBy(askedOn: '2026-10-16'));
	}//end testTheDeadlineSkipsTheHolidays()

	/**
	 * The school sets its own deadline; a setting that is no whole number reads as five, a large one is capped.
	 *
	 * @return void
	 */
	public function testTheDeadlineIsASetting(): void {
		self::assertSame(10, $this->calendar(setting: '10')->decisionDays());
		self::assertSame('2026-10-14', $this->calendar(setting: '10')->decideBy(askedOn: '2026-09-30'));
		self::assertSame(5, $this->calendar(setting: 'vijf')->decisionDays());
		self::assertSame(5, $this->calendar(setting: '0')->decisionDays());
		self::assertSame(60, $this->calendar(setting: '365')->decisionDays());
	}//end testTheDeadlineIsASetting()
}//end class
