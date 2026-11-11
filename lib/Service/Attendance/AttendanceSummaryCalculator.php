<?php

/**
 * Learniq Attendance Summary Calculator
 *
 * The counting rules of AttendanceSummary, in one place and without any I/O,
 * so the listener's job, the repair step and the primary school example set
 * generator (scripts/example-sets/po.py) agree on one definition.
 *
 * - A school year runs from 1 August to 31 July and is written `2025-2026`.
 * - The day of a record is the date of its lesson's `startsAt`, read in the
 *   offset stored on that timestamp, or of `markedAt` when the lesson is
 *   unknown.
 * - A date with at least one absence is one absent day; it is unauthorised
 *   when one of its absences is `absent-unexcused`, otherwise authorised.
 * - Every `late` record counts once; its minutes are `lateMinutes`, or the
 *   lesson length minus `minutesAttended` for older records.
 * - `left-early` and `present` are not counted.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Attendance
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
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-absence-and-lateness-are-counted-per-learner-per-school-year
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Attendance;

use DateTimeImmutable;
use Throwable;

/**
 * Counts absent days and late arrivals per school year.
 *
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-absence-and-lateness-are-counted-per-learner-per-school-year
 */
class AttendanceSummaryCalculator {

	public const ABSENT_AUTHORISED = 'absent-excused';
	public const ABSENT_UNAUTHORISED = 'absent-unexcused';
	public const LATE = 'late';

	/**
	 * The statuses the summary counts; a recount reads only these.
	 */
	public const COUNTED_STATUSES = [self::ABSENT_AUTHORISED, self::ABSENT_UNAUTHORISED, self::LATE];

	/**
	 * The month a school year starts in.
	 */
	private const FIRST_MONTH = 8;

	/**
	 * The counts of a summary with nothing in it.
	 *
	 * @return array{absentDays: int, absentAuthorisedDays: int, absentUnauthorisedDays: int, lateCount: int, lateMinutes: int}
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-absence-and-lateness-are-counted-per-learner-per-school-year
	 */
	public static function emptyCounts(): array {
		return ['absentDays' => 0, 'absentAuthorisedDays' => 0, 'absentUnauthorisedDays' => 0, 'lateCount' => 0, 'lateMinutes' => 0];
	}//end emptyCounts()

	/**
	 * The school year of a date (`Y-m-d`), or null when it is not one.
	 *
	 * @param string $date The date.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#scenario-july-and-august-fall-in-different-school-years
	 */
	public static function schoolYearOf(string $date): ?string {
		if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $date, $parts) !== 1) {
			return null;
		}

		$year = (int)$parts[1];
		if ((int)$parts[2] < self::FIRST_MONTH) {
			$year--;
		}

		return $year . '-' . ($year + 1);
	}//end schoolYearOf()

	/**
	 * Whether a value is a school year such as `2025-2026`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-absence-and-lateness-are-counted-per-learner-per-school-year
	 */
	public static function isSchoolYear(mixed $value): bool {
		if (is_string($value) === false || preg_match('/^(\d{4})-(\d{4})$/', $value, $parts) !== 1) {
			return false;
		}

		return (int)$parts[2] === ((int)$parts[1] + 1);
	}//end isSchoolYear()

	/**
	 * The date and length of each lesson, keyed by its uuid.
	 *
	 * @param array<int, array<string, mixed>> $rows Session rows.
	 *
	 * @return array<string, array{date: string, minutes: int|null}>
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-absence-and-lateness-are-counted-per-learner-per-school-year
	 */
	public static function sessionDays(array $rows): array {
		$days = [];
		foreach ($rows as $row) {
			$id = (string)($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? '')));
			$start = self::moment(value: ($row['startsAt'] ?? null));
			if ($id === '' || $start === null) {
				continue;
			}

			$end = self::moment(value: ($row['endsAt'] ?? null));
			$days[$id] = ['date' => $start->format('Y-m-d'), 'minutes' => self::minutesBetween(start: $start, end: $end)];
		}

		return $days;
	}//end sessionDays()

	/**
	 * The whole minutes from start to a later end, or null without one.
	 *
	 * @param DateTimeImmutable      $start The lesson start.
	 * @param DateTimeImmutable|null $end   The lesson end, or null.
	 *
	 * @return int|null
	 */
	private static function minutesBetween(DateTimeImmutable $start, ?DateTimeImmutable $end): ?int {
		if ($end !== null && $end > $start) {
			return intdiv(($end->getTimestamp() - $start->getTimestamp()), 60);
		}

		return null;
	}//end minutesBetween()

	/**
	 * The counts per school year of one learner's records.
	 *
	 * @param array<int, array<string, mixed>>                     $records  The learner's AttendanceRecords.
	 * @param array<string, array{date: string, minutes: int|null}> $sessions From sessionDays().
	 *
	 * @return array<string, array{absentDays: int, absentAuthorisedDays: int, absentUnauthorisedDays: int, lateCount: int, lateMinutes: int}>
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-absence-and-lateness-are-counted-per-learner-per-school-year
	 */
	public function summarise(array $records, array $sessions): array {
		$absences = [];
		$years = [];
		foreach ($this->datedMarks(records: $records, sessions: $sessions) as [$status, $year, $day, $record, $session]) {
			$years[$year] = ($years[$year] ?? self::emptyCounts());
			if ($status === self::LATE) {
				$years[$year] = self::withLate(counts: $years[$year], minutes: $this->lateMinutes(record: $record, session: $session));
				continue;
			}

			// A day is unauthorised once any absence on it is.
			$absences[$year][$day] = (($absences[$year][$day] ?? false) || $status === self::ABSENT_UNAUTHORISED);
		}

		$years = $this->addAbsentDays(years: $years, absences: $absences);
		ksort($years);

		return $years;
	}//end summarise()

	/**
	 * The counted records that fall on a day of a school year, in order, each
	 * as [status, school year, day, record, its lesson or null].
	 *
	 * @param array<int, array<string, mixed>>                      $records  The learner's AttendanceRecords.
	 * @param array<string, array{date: string, minutes: int|null}> $sessions From sessionDays().
	 *
	 * @return \Generator<int, array{string, string, string, array<string, mixed>, array{date: string, minutes: int|null}|null}>
	 */
	private function datedMarks(array $records, array $sessions): \Generator {
		foreach ($records as $record) {
			$status = (string)($record['status'] ?? '');
			if (in_array($status, self::COUNTED_STATUSES, true) === false) {
				continue;
			}

			$session = ($sessions[(string)($record['sessionId'] ?? '')] ?? null);
			$day = $this->dayOf(record: $record, session: $session);
			$year = self::schoolYearOf(date: (string)$day);
			if ($day === null || $year === null) {
				continue;
			}

			yield [$status, $year, $day, $record, $session];
		}
	}//end datedMarks()

	/**
	 * The counts with one more late arrival of these minutes.
	 *
	 * @param array<string, int> $counts  The school year's counts.
	 * @param int                $minutes The minutes the arrival adds.
	 *
	 * @return array<string, int>
	 */
	private static function withLate(array $counts, int $minutes): array {
		$counts['lateCount']++;
		$counts['lateMinutes'] += $minutes;

		return $counts;
	}//end withLate()

	/**
	 * Add the absent days to the counts of their school years.
	 *
	 * @param array<string, array<string, int>>  $years    Counts per school year.
	 * @param array<string, array<string, bool>> $absences Per school year, per day: whether it is unauthorised.
	 *
	 * @return array<string, array<string, int>>
	 */
	private function addAbsentDays(array $years, array $absences): array {
		foreach ($absences as $year => $days) {
			foreach ($days as $unauthorised) {
				$years[$year]['absentDays']++;
				$field = 'absentAuthorisedDays';
				if ($unauthorised === true) {
					$field = 'absentUnauthorisedDays';
				}

				$years[$year][$field]++;
			}
		}

		return $years;
	}//end addAbsentDays()

	/**
	 * The day a record counts on: its lesson's date, else the date of markedAt.
	 *
	 * @param array<string, mixed>                          $record  The record.
	 * @param array{date: string, minutes: int|null}|null $session Its lesson.
	 *
	 * @return string|null
	 */
	private function dayOf(array $record, ?array $session): ?string {
		if ($session !== null) {
			return $session['date'];
		}

		return self::moment(value: ($record['markedAt'] ?? null))?->format('Y-m-d');
	}//end dayOf()

	/**
	 * The minutes a late record adds.
	 *
	 * @param array<string, mixed>                          $record  The record.
	 * @param array{date: string, minutes: int|null}|null $session Its lesson.
	 *
	 * @return int
	 */
	private function lateMinutes(array $record, ?array $session): int {
		$late = ($record['lateMinutes'] ?? null);
		if (self::isWholeNumber(value: $late) === true) {
			return max(0, (int)$late);
		}

		return self::unattendedMinutes(record: $record, session: $session);
	}//end lateMinutes()

	/**
	 * Whether a value is an integer or a string of digits.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function isWholeNumber(mixed $value): bool {
		return is_int($value) === true || (is_string($value) === true && ctype_digit($value) === true);
	}//end isWholeNumber()

	/**
	 * The lesson minutes a record did not attend, or 0 without a lesson length or attendance.
	 *
	 * @param array<string, mixed>                          $record  The record.
	 * @param array{date: string, minutes: int|null}|null $session Its lesson.
	 *
	 * @return int
	 */
	private static function unattendedMinutes(array $record, ?array $session): int {
		$attended = ($record['minutesAttended'] ?? null);
		if ($session === null || $session['minutes'] === null || is_numeric($attended) === false) {
			return 0;
		}

		return max(0, ($session['minutes'] - (int)$attended));
	}//end unattendedMinutes()

	/**
	 * A timestamp in its own offset, or null.
	 *
	 * @param mixed $value An ISO 8601 date-time.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}
	}//end moment()
	/**
	 * Whether a stored row already holds these numbers, teachers and refs.
	 *
	 * @param array<string, mixed> $row  The stored row.
	 * @param array<string, mixed> $data The recounted row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
	 */
	public function unchanged(array $row, array $data): bool {
		foreach ($data as $field => $value) {
			$stored = ($row[$field] ?? null);
			$same = match ($field) {
				'teacherIds' => self::sameMembers(stored: $stored, value: $value),
				default => $this->sameValue(stored: $stored, value: $value),
			};
			if ($same === false) {
				return false;
			}
		}

		return true;
	}//end unchanged()

	/**
	 * Whether two teacher lists hold the same ids, in any order.
	 *
	 * @param mixed $stored The stored list.
	 * @param mixed $value  The recounted list.
	 *
	 * @return bool
	 */
	private static function sameMembers(mixed $stored, mixed $value): bool {
		$left = array_values((array)$stored);
		$right = $value;
		sort($left);
		sort($right);

		return $left === $right;
	}//end sameMembers()

	/**
	 * Whether a stored value equals a recounted one; a recounted integer also
	 * matches a numeric stored value.
	 *
	 * @param mixed $stored The stored value.
	 * @param mixed $value  The recounted value.
	 *
	 * @return bool
	 */
	private function sameValue(mixed $stored, mixed $value): bool {
		if (is_int($value) === true) {
			$stored = $this->intOrNull(value: $stored);
		}

		return $stored === $value;
	}//end sameValue()

	/**
	 * An integer, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null
	 */
	private function intOrNull(mixed $value): ?int {
		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end intOrNull()

}//end class
