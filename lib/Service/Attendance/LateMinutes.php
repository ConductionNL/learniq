<?php

/**
 * Learniq Late Minutes
 *
 * The minutes a late attendance record adds to its school year's lateness.
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

/**
 * Reads how late a late record was.
 *
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-absence-and-lateness-are-counted-per-learner-per-school-year
 */
final class LateMinutes {

	/**
	 * The minutes a late record adds: its whole, non-negative `lateMinutes`,
	 * else the lesson minutes it did not attend.
	 *
	 * @param array<string, mixed>                          $record  The record.
	 * @param array{date: string, minutes: int|null}|null $session Its lesson.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-absence-and-lateness-are-counted-per-learner-per-school-year
	 */
	public function minutesOf(array $record, ?array $session): int {
		$late = ($record['lateMinutes'] ?? null);
		if (self::isWholeNumber(value: $late) === true) {
			return max(0, (int)$late);
		}

		return self::unattendedMinutes(record: $record, session: $session);
	}//end minutesOf()

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
}//end class
