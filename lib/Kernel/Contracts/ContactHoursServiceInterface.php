<?php

/**
 * Contract of ContactHoursService
 *
 * The public surface of ContactHoursService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of ContactHoursService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactHoursServiceInterface {

	/**
	 * The report for a window, per group, course and learner.
	 *
	 * @param string      $from     First day (Y-m-d).
	 * @param string      $to       Last day (Y-m-d), inclusive.
	 * @param string|null $cohortId One group, or null for every group of the window's school years.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the timetable source cannot be read.
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
	 */
	public function forPeriod(string $from, string $to, ?string $cohortId=null): array;
}//end interface
