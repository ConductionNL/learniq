<?php

/**
 * Contract of BulkEnrolService
 *
 * The public surface of BulkEnrolService that other components call, so they depend
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
 * Contract of BulkEnrolService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BulkEnrolServiceInterface {

	/**
	 * Enrol the learners of a group in a course.
	 *
	 * @param array<string, mixed> $course The course, as the caller reads it.
	 * @param array<string, mixed> $cohort The group, as the caller reads it.
	 *
	 * @return array{enrolled: int, skipped: int, refused: array<int, string>}
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-bulk-enrolment-via-cohort-role-department-or-csv
	 */
	public function enrol(array $course, array $cohort): array;
}//end interface
