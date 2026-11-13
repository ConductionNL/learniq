<?php

/**
 * Contract of AttendanceWindowAggregator
 *
 * The public surface of AttendanceWindowAggregator that other components call, so they depend
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
 * Contract of AttendanceWindowAggregator.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AttendanceWindowAggregatorInterface {

	/**
	 * Aggregate a learner's `AttendanceRecord`s whose `sessionId` is in
	 * `$sessionIds` into an `attendanceSummary` object, mirroring the formula
	 * documented on `ReportCard.attendanceSummary.attendancePercent`:
	 * `(present + late + leftEarly) / total`, null when the window has zero
	 * sessions for this learner.
	 *
	 * @param string $learnerId NC user ID.
	 * @param array<int,string> $sessionIds Session UUIDs within the ReportPeriod's window.
	 *
	 * @return array<string,mixed>
	 * @spec openspec/specs/report-card/spec.md#scenario-composing-a-period-creates-one-reportcard-per-cohort-learner
	 */
	public function buildAttendanceSummary(string $learnerId, array $sessionIds): array;

	/**
	 * Resolve every Session UUID in `cohortIds[]` whose `[startsAt, endsAt]`
	 * overlaps `[startDate, endDate]`, mirroring
	 * `TimetableController::fetchSessions()`'s window-overlap style.
	 *
	 * @param array<int,string> $cohortIds ReportPeriod.cohortIds.
	 * @param string $startDate Window start (ISO 8601 date).
	 * @param string $endDate Window end (ISO 8601 date).
	 *
	 * @return array<int,string> Session UUIDs within the window.
	 * @spec openspec/specs/report-card/spec.md#scenario-composing-a-period-creates-one-reportcard-per-cohort-learner
	 */
	public function fetchWindowSessionIds(array $cohortIds, string $startDate, string $endDate): array;
}//end interface
