<?php

/**
 * Contract of RolloverService
 *
 * The public surface of RolloverService that other components call, so they depend
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
 * Contract of RolloverService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RolloverServiceInterface {

	/**
	 * Produce a side-effect-free preview report for a plan.
	 *
	 * Computes per-cohort promote/retain/graduate/outflow counts, the cohorts to
	 * create, the incomplete mandatory enrolments to carry over, and the NC groups
	 * to sync — WITHOUT writing anything. A mapping whose action is null makes the
	 * preview "blocked" (the plan cannot be executed until resolved).
	 *
	 * @param array<string,mixed> $plan The RolloverPlan object.
	 *
	 * @return array<string,mixed> The dry-run report.
	 *
	 * @spec openspec/changes/archive/2026-06-15-school-year-rollover/tasks.md
	 */
	public function preview(array $plan): array;

	/**
	 * Whether a plan's stored preview still matches its current mappings.
	 *
	 * Editing mappings after a preview must drop the plan back to draft (the dry
	 * run no longer matches). This compares the report's blocking set / create
	 * list against a fresh preview of the current mappings.
	 *
	 * @param array<string,mixed> $plan The RolloverPlan object.
	 *
	 * @return bool True when the stored dryRunReport matches the current mappings.
	 *
	 * @spec openspec/changes/archive/2026-06-15-school-year-rollover/tasks.md
	 */
	public function previewMatchesMappings(array $plan): bool;

	/**
	 * Propose a default per-cohort mapping by incrementing the leerjaar.
	 *
	 * For each from-year cohort, parse the leading leerjaar digit from its name
	 * (e.g. "2A" → 2) and propose a `promote` action whose `toCohortName` carries
	 * the incremented digit ("3A"). A cohort whose name has no parseable leading
	 * digit yields `action: null` so the preview is blocked until a human resolves
	 * it — no silent guessing (D2).
	 *
	 * @param array<int,array<string,mixed>> $fromCohorts The from-year cohorts
	 *                                                    (each with id + name +
	 *                                                    programmeId).
	 *
	 * @return array<int,array<string,mixed>> Proposed mappings.
	 *
	 * @spec openspec/changes/archive/2026-06-15-school-year-rollover/tasks.md
	 */
	public function proposeDefaultMapping(array $fromCohorts): array;
}//end interface
