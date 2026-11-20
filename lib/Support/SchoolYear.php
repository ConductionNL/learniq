<?php

/**
 * SchoolYear: the school year a date falls in.
 *
 * A school year runs from 1 August to 31 July and is written `2025-2026`.
 * The attendance recount and the rolling-window sweep both name a year this
 * way, so the rule lives here once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Learniq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#scenario-july-and-august-fall-in-different-school-years
 */

declare(strict_types=1);

namespace OCA\Learniq\Support;

/**
 * Names the school year of a date.
 *
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#scenario-july-and-august-fall-in-different-school-years
 */
final class SchoolYear {

	/**
	 * The first day of a school year when the school sets none, `MM-DD`.
	 */
	public const DEFAULT_START = '08-01';

	/**
	 * The school year of a date (`Y-m-d`), or null when it is not one.
	 *
	 * The year starts on `$start` (`MM-DD`, the school's `school_year_start`,
	 * read by `Service\Calendar\SchoolYear`); a start that is not a day of the
	 * year reads as 1 August.
	 *
	 * @param string $date  The date.
	 * @param string $start The first day of the school year, `MM-DD`.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#scenario-july-and-august-fall-in-different-school-years
	 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
	 */
	public static function of(string $date, string $start=self::DEFAULT_START): ?string {
		if (preg_match('/^(\d{4})-(\d{2}-\d{2})$/', $date, $parts) !== 1) {
			return null;
		}

		if (preg_match('/^(\d{2})-(\d{2})$/', $start, $day) !== 1 || checkdate((int)$day[1], (int)$day[2], 2001) === false) {
			$start = self::DEFAULT_START;
		}

		$year = (int)$parts[1];
		if ($parts[2] < $start) {
			$year--;
		}

		return $year . '-' . ($year + 1);
	}//end of()
}//end class
