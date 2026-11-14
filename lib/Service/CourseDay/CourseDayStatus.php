<?php

/**
 * Learniq CourseDayStatus
 *
 * The status of a course day (a cohort of a training course), derived from
 * how many participants it has, its minimum and maximum, the provider's
 * go/no-go decision and its date. Pure: the caller gathers the facts.
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

/**
 * Decides a course day's status.
 *
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */
final class CourseDayStatus {

	public const OPEN = 'open';

	public const GOES_AHEAD = 'goes-ahead';

	public const FULL = 'full';

	public const ROUNDING_OFF = 'rounding-off';

	public const DONE = 'done';

	public const CANCELLED = 'cancelled';

	/**
	 * Every status, in the order the board shows them.
	 *
	 * @var list<string>
	 */
	public const ALL = [self::OPEN, self::GOES_AHEAD, self::FULL, self::ROUNDING_OFF, self::DONE, self::CANCELLED];

	/**
	 * The status of a course day.
	 *
	 * A no-go decision cancels the day whatever else holds. A day whose date
	 * has passed is rounding off while results or certificates are open, else
	 * done. Before that: full at its maximum, goes ahead at its minimum or on a
	 * go decision, otherwise open. A day without a minimum goes ahead only by decision.
	 *
	 * @param array<string, mixed> $facts The facts: `participants` (int, the places taken),
	 *                                    `capacity` and `minimum` (int, or null when there is none),
	 *                                    `decision` (`go`, `no-go`, or null while undecided),
	 *                                    `dayDate` (the day's last date, Y-m-d, or null when unknown),
	 *                                    `today` (Y-m-d) and `openResults` (bool, whether results
	 *                                    or certificates of the day are still open).
	 *
	 * @return string One of {@see self::ALL}.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public static function derive(array $facts): string {
		if (($facts['decision'] ?? null) === 'no-go') {
			return self::CANCELLED;
		}

		if (self::isPast(facts: $facts) === true) {
			return self::pastStatus(facts: $facts);
		}

		if (self::isFull(facts: $facts) === true) {
			return self::FULL;
		}

		if (self::goesAhead(facts: $facts) === true) {
			return self::GOES_AHEAD;
		}

		return self::OPEN;
	}//end derive()

	/**
	 * Whether the day's last date lies before today.
	 *
	 * @param array<string, mixed> $facts The facts, see {@see self::derive()}.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public static function isPast(array $facts): bool {
		$day = ($facts['dayDate'] ?? null);

		return $day !== null && $day !== '' && $day < (string)($facts['today'] ?? '');
	}//end isPast()

	/**
	 * A past day is rounding off while results or certificates are open, else done.
	 *
	 * @param array<string, mixed> $facts The facts, see {@see self::derive()}.
	 *
	 * @return string
	 */
	private static function pastStatus(array $facts): string {
		if (($facts['openResults'] ?? false) === true) {
			return self::ROUNDING_OFF;
		}

		return self::DONE;
	}//end pastStatus()

	/**
	 * Whether the day has reached its maximum.
	 *
	 * @param array<string, mixed> $facts The facts, see {@see self::derive()}.
	 *
	 * @return bool
	 */
	private static function isFull(array $facts): bool {
		$capacity = ($facts['capacity'] ?? null);

		return $capacity !== null && $capacity > 0 && (int)($facts['participants'] ?? 0) >= $capacity;
	}//end isFull()

	/**
	 * Whether the day goes ahead: by a go decision or at its minimum.
	 *
	 * @param array<string, mixed> $facts The facts, see {@see self::derive()}.
	 *
	 * @return bool
	 */
	private static function goesAhead(array $facts): bool {
		if (($facts['decision'] ?? null) === 'go') {
			return true;
		}

		$minimum = ($facts['minimum'] ?? null);

		return $minimum !== null && (int)($facts['participants'] ?? 0) >= $minimum;
	}//end goesAhead()
}//end class
