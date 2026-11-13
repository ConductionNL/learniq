<?php

/**
 * Contract of RolloverExecutionService
 *
 * The public surface of RolloverExecutionService that other components call, so they depend
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

use OCA\Learniq\Exception\RolloverInterruptedException;

/**
 * Contract of RolloverExecutionService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RolloverExecutionServiceInterface {

	/**
	 * Execute a previewed plan idempotently.
	 *
	 * For each not-yet-done mapping: create the to-year Cohort (idempotent on
	 * toAcademicYear+toCohortName+tenant), move learnerIds per mapping + override,
	 * archive the from-year Cohort, sync the NC group, carry over incomplete
	 * mandatory enrolments, and queue OSO jobs for outflow learners. Per-mapping
	 * completion is recorded so a re-run of a failed plan skips done mappings.
	 * A mapping whose from-cohort no longer exists is skipped without writes and
	 * left out of the progress map.
	 *
	 * Returns the updated perMappingProgress map. The caller persists the plan and
	 * fires the terminal transition.
	 *
	 * @param array<string,mixed> $plan The RolloverPlan object (lifecycle executing).
	 *
	 * @return array<string,string> Map of fromCohortId => 'done'.
	 *
	 * @throws RolloverInterruptedException When a mapping fails; it carries the progress so far.
	 *
	 * @spec openspec/changes/archive/2026-06-15-school-year-rollover/tasks.md
	 */
	public function execute(array $plan): array;
}//end interface
