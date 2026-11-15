<?php

/**
 * Contract of ContactHoursCalendar
 *
 * The public surface of ContactHoursCalendar that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

/**
 * Contract of ContactHoursCalendar.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactHoursCalendarInterface {

	/**
	 * A lesson's length in hours.
	 *
	 * @param string $startsAt Start.
	 * @param string $endsAt   End.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
	 */
	public function hours(string $startsAt, string $endsAt): float;

	/**
	 * The share of a line that falls inside the window, between 0 and 1.
	 *
	 * @param string|null                      $periodCode   The line's period, or null for the whole year.
	 * @param array<int, array<string, mixed>> $periods      The school year's report periods.
	 * @param string                           $academicYear The school year (YYYY-YYYY).
	 * @param array{0: string, 1: string}      $window       First and last day of the window (Y-m-d).
	 *
	 * @return float
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
	 */
	public function share(?string $periodCode, array $periods, string $academicYear, array $window): float;
}//end interface
