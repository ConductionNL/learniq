<?php

/**
 * Contract of PlanninqTimetableImport
 *
 * The public surface of PlanninqTimetableImport that other components call, so they depend
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
 * Contract of PlanninqTimetableImport.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PlanninqTimetableImportInterface {

	/**
	 * Whether a timetable-import job goes to planninq on this instance.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
	 */
	public function applies(): bool;

	/**
	 * Ask integriq to deliver the job's rostering source into planninq.
	 *
	 * @param array<string,mixed>      $job     The DataExchangeJob data.
	 * @param array<string,mixed>|null $profile The job's DataMappingProfile, or null.
	 *
	 * @return array{state:string,fields:array<string,mixed>} The transition to apply and the job fields to save.
	 *
	 * @throws RuntimeException With a readable message when the delivery cannot happen or failed.
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
	 */
	public function deliver(array $job, ?array $profile): array;

	/**
	 * Scan the lessons planninq holds for the job's mapped cohorts and teachers.
	 *
	 * A failed read is logged, not thrown: the delivery already succeeded, and
	 * a conflict scan that cannot run must not undo it.
	 *
	 * @param array<string,mixed> $job The DataExchangeJob data.
	 *
	 * @return int Number of lessons scanned.
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-conflict-detection-runs-on-the-adapters-lessons-req-004
	 */
	public function scanConflicts(array $job): int;
}//end interface
