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
	 * The month a school year starts in.
	 */
	private const FIRST_MONTH = 8;

	/**
	 * The school year of a date (`Y-m-d`), or null when it is not one.
	 *
	 * @param string $date The date.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#scenario-july-and-august-fall-in-different-school-years
	 */
	public static function of(string $date): ?string {
		if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $date, $parts) !== 1) {
			return null;
		}

		$year = (int)$parts[1];
		if ((int)$parts[2] < self::FIRST_MONTH) {
			$year--;
		}

		return $year . '-' . ($year + 1);
	}//end of()
}//end class
