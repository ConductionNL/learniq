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
	 * @param int         $participants The places taken.
	 * @param int|null    $capacity     The maximum, or null when there is none.
	 * @param int|null    $minimum      The minimum, or null when there is none.
	 * @param string|null $decision     `go`, `no-go`, or null while undecided.
	 * @param string|null $dayDate      The day's last date (Y-m-d), or null when unknown.
	 * @param string      $today        Today (Y-m-d).
	 * @param bool        $openResults  Whether results or certificates of the day are still open.
	 *
	 * @return string One of {@see self::ALL}.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public static function derive(
		int $participants,
		?int $capacity,
		?int $minimum,
		?string $decision,
		?string $dayDate,
		string $today,
		bool $openResults
	): string {
		if ($decision === 'no-go') {
			return self::CANCELLED;
		}

		if ($dayDate !== null && $dayDate !== '' && $dayDate < $today) {
			if ($openResults === true) {
				return self::ROUNDING_OFF;
			}

			return self::DONE;
		}

		if ($capacity !== null && $capacity > 0 && $participants >= $capacity) {
			return self::FULL;
		}

		if ($decision === 'go' || ($minimum !== null && $participants >= $minimum)) {
			return self::GOES_AHEAD;
		}

		return self::OPEN;
	}//end derive()
}//end class
