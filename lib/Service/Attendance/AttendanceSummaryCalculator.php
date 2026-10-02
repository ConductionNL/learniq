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
			$minutes = null;
			if ($end !== null && $end > $start) {
				$minutes = intdiv(($end->getTimestamp() - $start->getTimestamp()), 60);
			}

			$days[$id] = ['date' => $start->format('Y-m-d'), 'minutes' => $minutes];
		}

		return $days;
	}//end sessionDays()

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

			$years[$year] = ($years[$year] ?? self::emptyCounts());
			if ($status === self::LATE) {
				$years[$year]['lateCount']++;
				$years[$year]['lateMinutes'] += $this->lateMinutes(record: $record, session: $session);
				continue;
			}

			// A day is unauthorised once any absence on it is.
			$absences[$year][$day] = (($absences[$year][$day] ?? false) || $status === self::ABSENT_UNAUTHORISED);
		}//end foreach

		$years = $this->addAbsentDays(years: $years, absences: $absences);
		ksort($years);

		return $years;
	}//end summarise()

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
		if (is_int($late) === true || (is_string($late) === true && ctype_digit($late) === true)) {
			return max(0, (int)$late);
		}

		$attended = ($record['minutesAttended'] ?? null);
		if ($session === null || $session['minutes'] === null || is_numeric($attended) === false) {
			return 0;
		}

		return max(0, ($session['minutes'] - (int)$attended));
	}//end lateMinutes()

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
			if ($field === 'teacherIds') {
				$left = array_values((array)$stored);
				$right = $value;
				sort($left);
				sort($right);
				if ($left !== $right) {
					return false;
				}

				continue;
			}

			if (is_int($value) === true) {
				$stored = $this->intOrNull(value: $stored);
			}

			if ($stored !== $value) {
				return false;
			}
		}

		return true;
	}//end unchanged()

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
