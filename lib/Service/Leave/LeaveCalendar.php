<?php

/**
 * The school days of a leave request and the date the school answers by
 *
 * A school day is a weekday that is not in a holiday of the school year's
 * report periods (`ReportPeriod.holidays`, the same source the contact-hours
 * count reads). The school answers within the number of school days the
 * setting `leave_decision_school_days` names, five when it is not set: the
 * boards say "U beslist binnen vijf schooldagen". The deadline is a setting
 * per school, not law in code.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Leave
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

namespace OCA\Learniq\Service\Leave;

use DateTimeImmutable;
use OCA\Learniq\Service\Calendar\SchoolYear;
use OCA\Learniq\Service\ContactHoursReader;
use OCP\IAppConfig;
use Throwable;

/**
 * Counts school days and finds the decision deadline.
 *
 * @spec openspec/changes/leave-requests-decided-by-the-school/specs/attendance/spec.md#requirement-a-leave-request-is-asked-in-advance-and-decided-by-the-head-of-the-school
 */
class LeaveCalendar {

	public const KEY_DECISION_DAYS = 'leave_decision_school_days';

	public const DEFAULT_DECISION_DAYS = 5;

	public const MAX_DECISION_DAYS = 60;

	private const APP_ID = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ContactHoursReader $reader     The school year's report periods.
	 * @param SchoolYear         $schoolYear The school year a date falls in.
	 * @param IAppConfig         $appConfig  The decision deadline setting.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContactHoursReader $reader,
		private readonly SchoolYear $schoolYear,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The school days from the first to the last day, both included.
	 *
	 * @param string $from The first day (Y-m-d).
	 * @param string $to   The last day (Y-m-d).
	 *
	 * @return int|null The count, or null when a date is not a date or the range runs backwards.
	 *
	 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
	 */
	public function schoolDays(string $from, string $to): ?int {
		$first = self::day(value: $from);
		$last = self::day(value: $to);
		if ($first === null || $last === null || $last < $first) {
			return null;
		}

		$holidays = $this->holidays(from: $first, to: $last);
		$count = 0;
		for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
			if (self::isSchoolDay(day: $day, holidays: $holidays) === true) {
				$count++;
			}
		}

		return $count;
	}//end schoolDays()

	/**
	 * The date the school answers by: the decision days' last school day after the day it was asked.
	 *
	 * @param string $askedOn The day the request was asked (Y-m-d or an ISO 8601 moment).
	 *
	 * @return string|null The date (Y-m-d), or null when the day is not a date.
	 *
	 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
	 */
	public function decideBy(string $askedOn): ?string {
		$day = self::day(value: substr($askedOn, 0, 10));
		if ($day === null) {
			return null;
		}

		$days = $this->decisionDays();
		// Holidays of a quarter ahead: no setting of sixty school days runs past it.
		$holidays = $this->holidays(from: $day, to: $day->modify('+120 days'));
		$counted = 0;
		while ($counted < $days) {
			$day = $day->modify('+1 day');
			if (self::isSchoolDay(day: $day, holidays: $holidays) === true) {
				$counted++;
			}
		}

		return $day->format('Y-m-d');
	}//end decideBy()

	/**
	 * Within how many school days the school answers.
	 *
	 * @return int Between 1 and MAX_DECISION_DAYS; 5 when unset or not a whole number.
	 *
	 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
	 */
	public function decisionDays(): int {
		$raw = trim($this->appConfig->getValueString(self::APP_ID, self::KEY_DECISION_DAYS, (string)self::DEFAULT_DECISION_DAYS));
		if (ctype_digit($raw) === false || (int)$raw < 1) {
			return self::DEFAULT_DECISION_DAYS;
		}

		return min(self::MAX_DECISION_DAYS, (int)$raw);
	}//end decisionDays()

	/**
	 * Every holiday date of the school years a range touches.
	 *
	 * @param DateTimeImmutable $from The first day.
	 * @param DateTimeImmutable $to   The last day.
	 *
	 * @return array<string, true> The dates (Y-m-d).
	 */
	private function holidays(DateTimeImmutable $from, DateTimeImmutable $to): array {
		$years = array_unique(
			[
				$this->schoolYear->yearOf(date: $from->format('Y-m-d')),
				$this->schoolYear->yearOf(date: $to->format('Y-m-d')),
			]
		);

		$dates = [];
		foreach ($years as $year) {
			foreach ($this->reader->reportPeriods(academicYear: $year) as $period) {
				foreach ((array)($period['holidays'] ?? []) as $holiday) {
					$dates += self::datesOf(holiday: (array)$holiday);
				}
			}
		}

		return $dates;
	}//end holidays()

	/**
	 * The dates of one holiday range.
	 *
	 * @param array<string, mixed> $holiday `{startDate, endDate}`; a missing end is the start.
	 *
	 * @return array<string, true> The dates.
	 */
	private static function datesOf(array $holiday): array {
		$first = self::day(value: (string)($holiday['startDate'] ?? ''));
		$last = self::day(value: (string)($holiday['endDate'] ?? ($holiday['startDate'] ?? '')));
		if ($first === null || $last === null) {
			return [];
		}

		$dates = [];
		for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
			$dates[$day->format('Y-m-d')] = true;
		}

		return $dates;
	}//end datesOf()

	/**
	 * Whether a day is a weekday outside the holidays.
	 *
	 * @param DateTimeImmutable   $day      The day.
	 * @param array<string, true> $holidays The holiday dates.
	 *
	 * @return bool
	 */
	private static function isSchoolDay(DateTimeImmutable $day, array $holidays): bool {
		return (int)$day->format('N') <= 5 && isset($holidays[$day->format('Y-m-d')]) === false;
	}//end isSchoolDay()

	/**
	 * A Y-m-d date as a day, or null.
	 *
	 * @param string $value The date.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function day(string $value): ?DateTimeImmutable {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return null;
		}

		try {
			$day = new DateTimeImmutable($value . 'T00:00:00+00:00');
		} catch (Throwable $exception) {
			unset($exception);
			return null;
		}

		if ($day->format('Y-m-d') !== $value) {
			return null;
		}

		return $day;
	}//end day()
}//end class
