<?php

/**
 * Learniq Contact Hours Calendar
 *
 * The date arithmetic of the contact hours report: how much of an hour plan
 * line falls inside a window, and a lesson's length in hours. A line with a
 * period counts fully when the whole report period lies inside the window,
 * and not at all otherwise; a line with no period counts by the share of the
 * school year's teaching days the window covers, weekends and the report
 * periods' holidays left out.
 *
 * @category Timetabling
 * @package  OCA\Learniq\Timetabling
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
 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling;

use DateTimeImmutable;
use Throwable;

/**
 * Proration and lesson length.
 *
 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */
class ContactHoursCalendar {

	/**
	 * The share of a line that falls inside the window, between 0 and 1.
	 *
	 * @param string|null                      $periodCode   The line's period, or null for the whole year.
	 * @param array<int, array<string, mixed>> $periods      The school year's report periods.
	 * @param string                           $academicYear The school year (YYYY-YYYY).
	 * @param string                           $from         First day of the window (Y-m-d).
	 * @param string                           $to           Last day of the window (Y-m-d).
	 *
	 * @return float
	 *
	 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
	 */
	public function share(?string $periodCode, array $periods, string $academicYear, string $from, string $to): float {
		if ($periodCode !== null && $periodCode !== '') {
			foreach ($periods as $period) {
				if ((string)($period['periodCode'] ?? '') === $periodCode) {
					return (float)((string)($period['startDate'] ?? '') >= $from && (string)($period['endDate'] ?? '9999') <= $to);
				}
			}

			return 0.0;
		}

		[$yearStart, $yearEnd] = $this->yearBounds(periods: $periods, academicYear: $academicYear);
		$holidays = $this->holidays(periods: $periods);
		$all = $this->teachingDays(from: $yearStart, to: $yearEnd, holidays: $holidays);
		if ($all === 0) {
			return 0.0;
		}

		$inside = $this->teachingDays(from: max($from, $yearStart), to: min($to, $yearEnd), holidays: $holidays);

		return $inside / $all;
	}//end share()

	/**
	 * A lesson's length in hours.
	 *
	 * @param string $startsAt Start.
	 * @param string $endsAt   End.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
	 */
	public function hours(string $startsAt, string $endsAt): float {
		$start = strtotime($startsAt);
		$end = strtotime($endsAt);
		if ($start === false || $end === false || $end <= $start) {
			return 0.0;
		}

		return ($end - $start) / 3600;
	}//end hours()

	/**
	 * Weekdays between two dates, inclusive, holidays left out.
	 *
	 * @param string             $from     First day (Y-m-d).
	 * @param string             $to       Last day (Y-m-d).
	 * @param array<string, true> $holidays Holiday dates.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
	 */
	public function teachingDays(string $from, string $to, array $holidays): int {
		try {
			$day = new DateTimeImmutable($from);
			$last = new DateTimeImmutable($to);
		} catch (Throwable $exception) {
			unset($exception);
			return 0;
		}

		$count = 0;
		while ($day <= $last) {
			if ((int)$day->format('N') <= 5 && isset($holidays[$day->format('Y-m-d')]) === false) {
				$count++;
			}

			$day = $day->modify('+1 day');
		}

		return $count;
	}//end teachingDays()

	/**
	 * The school year's first and last day: its report periods, else 1 August to 31 July.
	 *
	 * @param array<int, array<string, mixed>> $periods      The report periods.
	 * @param string                           $academicYear The school year (YYYY-YYYY).
	 *
	 * @return array{0: string, 1: string}
	 */
	private function yearBounds(array $periods, string $academicYear): array {
		$starts = array_filter(array_map(static fn (array $period): string => (string)($period['startDate'] ?? ''), $periods));
		$ends = array_filter(array_map(static fn (array $period): string => (string)($period['endDate'] ?? ''), $periods));
		if ($starts !== [] && $ends !== []) {
			return [min($starts), max($ends)];
		}

		$first = (int)substr($academicYear, 0, 4);

		return [sprintf('%04d-08-01', $first), sprintf('%04d-07-31', $first + 1)];
	}//end yearBounds()

	/**
	 * Every holiday date of the report periods.
	 *
	 * @param array<int, array<string, mixed>> $periods The report periods.
	 *
	 * @return array<string, true>
	 */
	private function holidays(array $periods): array {
		$dates = [];
		foreach ($periods as $period) {
			foreach ((array)($period['holidays'] ?? []) as $holiday) {
				$from = (string)($holiday['startDate'] ?? '');
				$to = (string)($holiday['endDate'] ?? $from);
				for ($day = strtotime($from); $day !== false && $day <= strtotime($to); $day += 86400) {
					$dates[gmdate('Y-m-d', $day)] = true;
				}
			}
		}

		return $dates;
	}//end holidays()
}//end class
