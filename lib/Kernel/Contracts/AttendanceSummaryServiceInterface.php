<?php

/**
 * Contract of AttendanceSummaryService
 *
 * The public surface of AttendanceSummaryService that other components call, so they depend
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
 * Contract of AttendanceSummaryService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AttendanceSummaryServiceInterface {

	/**
	 * Recount every school year of a learner and save what changed.
	 *
	 * The years written are those with counted records, those already stored
	 * (so a year whose last absence was removed drops to zero), and the years
	 * the caller names (so a pupil marked present gets a row of zeros).
	 *
	 * @param string            $learnerId   Nextcloud user id of the learner.
	 * @param array<int,string> $schoolYears School years to write even without counted records.
	 * @param string            $tenantId    Tenant to use when no record or row names one.
	 * @param string|null       $learnerRef  Profile uuid to use when no record or row names one.
	 *
	 * @return int The number of rows saved.
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
	 */
	public function recompute(string $learnerId, array $schoolYears=[], string $tenantId='', ?string $learnerRef=null): int;

	/**
	 * The school years the given lessons fall in.
	 *
	 * @param array<int,string> $sessionIds Session uuids.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
	 */
	public function schoolYearsOf(array $sessionIds): array;
}//end interface
