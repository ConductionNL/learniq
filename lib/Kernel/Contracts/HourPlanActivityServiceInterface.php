<?php

/**
 * Contract of HourPlanActivityService
 *
 * The public surface of HourPlanActivityService that other components call, so they depend
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
 * Contract of HourPlanActivityService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HourPlanActivityServiceInterface {

	/**
	 * The activities of a school year.
	 *
	 * @param string $academicYear The school year, `YYYY-YYYY`.
	 *
	 * @return array{academicYear:string,activities:array<int,array<string,mixed>>,cohortsWithoutPlan:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	public function forYear(string $academicYear): array;

	/**
	 * The intake year of a cohort in its programme year: the school year it started.
	 *
	 * 2026-2027 in programme year 2 started in 2025-2026.
	 *
	 * @param string $academicYear  The school year, `YYYY-YYYY`.
	 * @param int    $programmeYear The cohort's year of its programme (1 or more).
	 *
	 * @return string|null The intake year, or null for an unreadable school year.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	public function intakeYearOf(string $academicYear, int $programmeYear): ?string;
}//end interface
