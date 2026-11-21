<?php

/**
 * Learniq UnauthorisedHoursWindow — the count of lesuren without permission
 * against an attendance threshold, before the threshold is crossed.
 *
 * A teacher should see "14 van 16 uur" while it still helps, not only the
 * flag at 16. This class counts the lesuren a pupil missed without permission
 * in the threshold's rolling window ending today, and says whether the count
 * is at the warning level. It reads, never writes, and never creates a flag:
 * the warning is a read model (openspec/specs/attendance).
 *
 * @category Service
 * @package  OCA\Learniq\Service\Attendance
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/attendance/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Attendance;

use DateTimeImmutable;
use Throwable;

/**
 * Counts the window and reads it against the threshold.
 *
 * Only an active per-learner threshold with metric `unexcused-lesuren` and a
 * `rolling-weeks` window has a count; any other threshold answers no count.
 * A lesson counts as its length divided by the threshold's minutes per
 * lesuur; a record without a known lesson length counts as one lesuur.
 *
 * @spec openspec/specs/attendance/spec.md
 */
class UnauthorisedHoursWindow {

	/**
	 * The share of the limit a threshold warns at when it names no `warnAtHours`.
	 *
	 * @var float
	 */
	public const DEFAULT_WARN_SHARE = 0.75;

	/**
	 * The status that counts.
	 *
	 * @var string
	 */
	private const UNAUTHORISED = 'absent-unexcused';

	/**
	 * Whether a threshold gives a pupil a window count.
	 *
	 * @param array<string, mixed> $threshold The attendance threshold.
	 *
	 * @return bool True for an active per-learner unexcused-lesuren threshold over rolling weeks.
	 *
	 * @spec openspec/specs/attendance/spec.md
	 */
	public function applies(array $threshold): bool {
		return $this->isActivePerLearner(threshold: $threshold) === true
			&& ($threshold['metric'] ?? null) === 'unexcused-lesuren'
			&& $this->isRollingWeeks(threshold: $threshold) === true
			&& is_numeric($threshold['limit'] ?? null) === true;
	}//end applies()

	/**
	 * Whether a threshold is active and counts per learner.
	 *
	 * @param array<string, mixed> $threshold The attendance threshold.
	 *
	 * @return bool True when it is not switched off and its scope is per-learner.
	 */
	private function isActivePerLearner(array $threshold): bool {
		return ($threshold['active'] ?? true) !== false
			&& ($threshold['scope'] ?? 'per-learner') === 'per-learner';
	}//end isActivePerLearner()

	/**
	 * Whether a threshold's window is a rolling number of weeks above zero.
	 *
	 * @param array<string, mixed> $threshold The attendance threshold.
	 *
	 * @return bool True for a rolling-weeks window with at least one week.
	 */
	private function isRollingWeeks(array $threshold): bool {
		return ($threshold['window']['type'] ?? null) === 'rolling-weeks'
			&& $this->weeksOf(threshold: $threshold) > 0;
	}//end isRollingWeeks()

	/**
	 * The lesuren without permission in the threshold's window ending on a day.
	 *
	 * The window is the threshold's number of weeks, ending on and including `$today`.
	 *
	 * @param array<int, array<string, mixed>>                      $records   The pupil's AttendanceRecords.
	 * @param array<string, array{date: string, minutes: int|null}> $sessions Their lessons, by id.
	 * @param array<string, mixed>                                  $threshold The attendance threshold.
	 * @param string                                                $today     The last day of the window, Y-m-d.
	 *
	 * @return float The lesuren, rounded to one decimal.
	 *
	 * @spec openspec/specs/attendance/spec.md
	 */
	public function count(array $records, array $sessions, array $threshold, string $today): float {
		$last = DateTimeImmutable::createFromFormat('!Y-m-d', $today);
		if ($last === false) {
			return 0.0;
		}

		$first = $last->modify('-' . ((7 * $this->weeksOf(threshold: $threshold)) - 1) . ' days')->format('Y-m-d');
		$minutesPerHour = max(1, (int)($threshold['lessonHourMinutes'] ?? 60));

		$hours = 0.0;
		foreach ($records as $record) {
			$hours += $this->recordHours(
				record: $record,
				sessions: $sessions,
				first: $first,
				last: $today,
				minutesPerHour: $minutesPerHour
			);
		}

		return round($hours, 1);
	}//end count()

	/**
	 * The lesuren one record adds to the window: none unless it is without
	 * permission and falls between the first and last day.
	 *
	 * @param array<string, mixed>                                  $record         The AttendanceRecord.
	 * @param array<string, array{date: string, minutes: int|null}> $sessions       The lessons, by id.
	 * @param string                                                $first          The first day of the window, Y-m-d.
	 * @param string                                                $last           The last day of the window, Y-m-d.
	 * @param int                                                   $minutesPerHour The threshold's minutes per lesuur.
	 *
	 * @return float The lesuren.
	 */
	private function recordHours(array $record, array $sessions, string $first, string $last, int $minutesPerHour): float {
		if (($record['status'] ?? null) !== self::UNAUTHORISED) {
			return 0.0;
		}

		$session = ($sessions[(string)($record['sessionId'] ?? '')] ?? null);
		$day = $this->dayOf(record: $record, session: $session);
		if ($day === null || $day < $first || $day > $last) {
			return 0.0;
		}

		return $this->lesurenOf(minutes: ($session['minutes'] ?? null), minutesPerHour: $minutesPerHour);
	}//end recordHours()

	/**
	 * The count at which a threshold warns: its `warnAtHours`, else three quarters of the limit.
	 *
	 * @param array<string, mixed> $threshold The attendance threshold.
	 *
	 * @return float The warning level.
	 *
	 * @spec openspec/specs/attendance/spec.md
	 */
	public function warnAt(array $threshold): float {
		$warnAt = ($threshold['warnAtHours'] ?? null);
		if (is_numeric($warnAt) === true && (float)$warnAt >= 0) {
			return (float)$warnAt;
		}

		return round(((float)($threshold['limit'] ?? 0)) * self::DEFAULT_WARN_SHARE, 1);
	}//end warnAt()

	/**
	 * Whether a count is nearing the limit: at or above the warning level, under the limit.
	 *
	 * @param float                $hours     The window count.
	 * @param array<string, mixed> $threshold The attendance threshold.
	 *
	 * @return bool True when staff should see the pupil as nearing the limit.
	 *
	 * @spec openspec/specs/attendance/spec.md
	 */
	public function nearing(float $hours, array $threshold): bool {
		return $hours >= $this->warnAt(threshold: $threshold) && $hours < (float)($threshold['limit'] ?? 0);
	}//end nearing()

	/**
	 * The count against the limit as staff read it: "14 van 16 uur", "13,5 van 16 uur".
	 *
	 * @param float $hours The window count.
	 * @param float $limit The limit.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/specs/attendance/spec.md
	 */
	public static function label(float $hours, float $limit): string {
		return self::number(value: $hours) . ' van ' . self::number(value: $limit) . ' uur';
	}//end label()

	/**
	 * The fields the summary row of the current school year carries.
	 *
	 * @param array<int, array<string, mixed>>                      $records   The pupil's AttendanceRecords.
	 * @param array<string, array{date: string, minutes: int|null}> $sessions Their lessons, by id.
	 * @param array<string, mixed>|null                             $threshold The applicable threshold, or null.
	 * @param string                                                $today     The last day of the window, Y-m-d.
	 *
	 * @return array{unauthorisedHoursWindow: float|null, thresholdHours: float|null, windowLabel: string|null, thresholdNearing: bool}
	 *
	 * @spec openspec/specs/attendance/spec.md
	 */
	public function fields(array $records, array $sessions, ?array $threshold, string $today): array {
		if ($threshold === null || $this->applies(threshold: $threshold) === false) {
			return ['unauthorisedHoursWindow' => null, 'thresholdHours' => null, 'windowLabel' => null, 'thresholdNearing' => false];
		}

		$hours = $this->count(records: $records, sessions: $sessions, threshold: $threshold, today: $today);
		$limit = (float)$threshold['limit'];

		return [
			'unauthorisedHoursWindow' => $hours,
			'thresholdHours' => $limit,
			'windowLabel' => self::label(hours: $hours, limit: $limit),
			'thresholdNearing' => $this->nearing(hours: $hours, threshold: $threshold),
		];
	}//end fields()

	/**
	 * The lesuren one missed lesson counts for: its length in lesuren, else one.
	 *
	 * @param mixed $minutes        The lesson's length in minutes, when known.
	 * @param int   $minutesPerHour The threshold's minutes per lesuur.
	 *
	 * @return float The lesuren.
	 */
	private function lesurenOf(mixed $minutes, int $minutesPerHour): float {
		if (is_int($minutes) === true && $minutes > 0) {
			return $minutes / $minutesPerHour;
		}

		return 1.0;
	}//end lesurenOf()

	/**
	 * The number of weeks of a threshold's window.
	 *
	 * @param array<string, mixed> $threshold The attendance threshold.
	 *
	 * @return int The weeks; 0 when none are named.
	 */
	private function weeksOf(array $threshold): int {
		$weeks = ($threshold['window']['weeks'] ?? null);
		if (is_numeric($weeks) === false) {
			return 0;
		}

		return max(0, (int)$weeks);
	}//end weeksOf()

	/**
	 * The day a record counts on: its lesson's date, else the date of markedAt.
	 *
	 * @param array<string, mixed>                        $record  The record.
	 * @param array{date: string, minutes: int|null}|null $session Its lesson.
	 *
	 * @return string|null The day, Y-m-d.
	 */
	private function dayOf(array $record, ?array $session): ?string {
		if ($session !== null) {
			return $session['date'];
		}

		return $this->markedDay(marked: ($record['markedAt'] ?? null));
	}//end dayOf()

	/**
	 * The date part of a markedAt timestamp.
	 *
	 * @param mixed $marked The markedAt value.
	 *
	 * @return string|null The day, Y-m-d; null when it is not a date.
	 */
	private function markedDay(mixed $marked): ?string {
		if (is_string($marked) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', $marked) !== 1) {
			return null;
		}

		try {
			return (new DateTimeImmutable($marked))->format('Y-m-d');
		} catch (Throwable) {
			return null;
		}
	}//end markedDay()

	/**
	 * A number as Dutch staff write it: whole numbers plain, else one decimal with a comma.
	 *
	 * @param float $value The number.
	 *
	 * @return string The text.
	 */
	private static function number(float $value): string {
		if (floor($value) === $value) {
			return (string)(int)$value;
		}

		return str_replace('.', ',', (string)round($value, 1));
	}//end number()
}//end class
