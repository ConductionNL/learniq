<?php

/**
 * Learniq PlannerToday
 *
 * The rules of the planner's Today, without any reads: which course day asks
 * for a go/no-go decision now, how many working days are left until a date,
 * and up to which day the occupancy runs.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CourseDay
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseDay;

use DateTimeImmutable;

/**
 * Pure rules for the planner's Today.
 *
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */
final class PlannerToday {

	/**
	 * A decision comes first on Today when it is due within this many working days.
	 *
	 * @var int
	 */
	public const DECISION_WORKING_DAYS = 3;

	/**
	 * The number of working days after `$today` up to and including `$date`;
	 * negative when the date has passed, 0 on the day itself.
	 *
	 * Saturday and Sunday are not working days.
	 *
	 * @param string $today Today (Y-m-d).
	 * @param string $date  The date (Y-m-d).
	 *
	 * @return int|null Null when either date cannot be read.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#scenario-four-of-six
	 */
	public static function workingDaysUntil(string $today, string $date): ?int {
		$from = self::date(value: $today);
		$to   = self::date(value: $date);
		if ($from === null || $to === null) {
			return null;
		}

		if ($to < $from) {
			return -1 * self::workingDaysBetween(from: $to, to: $from);
		}

		return self::workingDaysBetween(from: $from, to: $to);
	}//end workingDaysUntil()

	/**
	 * The working days after `$from` up to and including `$to` ($from <= $to).
	 *
	 * @param DateTimeImmutable $from The earlier date.
	 * @param DateTimeImmutable $to   The later date.
	 *
	 * @return int
	 */
	private static function workingDaysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int {
		$count = 0;
		for ($day = $from->modify('+1 day'); $day <= $to; $day = $day->modify('+1 day')) {
			if ((int)$day->format('N') <= 5) {
				$count++;
			}
		}

		return $count;
	}//end workingDaysBetween()

	/**
	 * The last day the occupancy shows: the Friday of the week two weeks after
	 * this one (on Monday 5 October: Friday 23 October).
	 *
	 * @param string $today Today (Y-m-d).
	 *
	 * @return string|null Y-m-d, or null when today cannot be read.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public static function occupancyUntil(string $today): ?string {
		$day = self::date(value: $today);
		if ($day === null) {
			return null;
		}

		$toFriday = (5 - (int)$day->format('N'));

		return $day->modify(sprintf('%+d days', ($toFriday + 14)))->format('Y-m-d');
	}//end occupancyUntil()

	/**
	 * Whether a course day asks the planner for a decision today: it is still
	 * to come, nobody decided, it has a minimum it does not reach, and its
	 * decision date is at most {@see self::DECISION_WORKING_DAYS} working days
	 * away (or already passed).
	 *
	 * @param array{date: string|null, decision: string|null, minimum: int|null, participants: int, decideBy: string|null} $day   The day's facts.
	 * @param string                                                                                                        $today Today (Y-m-d).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#scenario-four-of-six
	 */
	public static function needsDecision(array $day, string $today): bool {
		if ($day['decision'] !== null || $day['minimum'] === null || $day['minimum'] <= 0) {
			return false;
		}

		if ($day['participants'] >= $day['minimum'] || $day['date'] === null || $day['date'] < $today) {
			return false;
		}

		$left = null;
		if ($day['decideBy'] !== null) {
			$left = self::workingDaysUntil(today: $today, date: $day['decideBy']);
		}

		return $left !== null && $left <= self::DECISION_WORKING_DAYS;
	}//end needsDecision()

	/**
	 * A Y-m-d string as a date, or null.
	 *
	 * @param string $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function date(string $value): ?DateTimeImmutable {
		$date = substr($value, 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
			return null;
		}

		return $parsed;
	}//end date()
}//end class
