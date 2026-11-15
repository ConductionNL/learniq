<?php

/**
 * Contract of GradeFormulaEvaluator
 *
 * The public surface of GradeFormulaEvaluator that other components call, so they depend
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
 * Contract of GradeFormulaEvaluator.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GradeFormulaEvaluatorInterface {

	/**
	 * Evaluate the CurriculumPlan formula for a learner.
	 *
	 * Fetches the CurriculumPlan + its associated GradeScale + all published
	 * GradeEntries for this learner on this plan, then applies the declared
	 * formula to produce a value, a pass/fail verdict, and a breakdown.
	 *
	 * @param string $curriculumPlanId UUID of the CurriculumPlan.
	 * @param string $learnerId Nextcloud user ID of the learner.
	 *
	 * @return array{value: float|null, passed: bool|null, breakdown: array, lastRecomputedAt: string}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
	 * @spec openspec/specs/grading/spec.md#requirement-a-plan-satisfied-entirely-by-exemptions-passes
	 */
	public function evaluate(string $curriculumPlanId, string $learnerId): array;
}//end interface
