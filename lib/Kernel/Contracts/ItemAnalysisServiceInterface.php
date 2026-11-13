<?php

/**
 * Contract of ItemAnalysisService
 *
 * The public surface of ItemAnalysisService that other components call, so they depend
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
 * Contract of ItemAnalysisService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ItemAnalysisServiceInterface {

	/**
	 * Compute ItemStatistics for one (itemId, assessmentId) pair from that
	 * assessment's `graded` AssessmentResults.
	 *
	 * @param string $itemId UUID of the Item.
	 * @param string $assessmentId UUID of the Assessment.
	 *
	 * @return array{sampleSize: int, pValue: float|null, itemTotalCorrelation: float|null,
	 *               distractorAnalysis: array|null, insufficientData: bool, computedAt: string}
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
	 */
	public function computeItemStatistics(string $itemId, string $assessmentId): array;

	/**
	 * Compute AssessmentReliability (Cronbach's alpha) for an Assessment from
	 * its `graded` AssessmentResults.
	 *
	 * List-wise: only respondents who have a scored response for EVERY item
	 * referenced anywhere across the assessment's graded results are included
	 * — Cronbach's alpha requires a common item set per respondent.
	 *
	 * @param string $assessmentId UUID of the Assessment.
	 *
	 * @return array{sampleSize: int, itemCount: int, cronbachAlpha: float|null,
	 *               insufficientData: bool, computedAt: string}
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-per-assessment-reliability-cronbachs-alpha-is-computed-with-a-minimum-sample-size
	 */
	public function computeReliability(string $assessmentId): array;

	/**
	 * Resolve ItemAnalysisService's configuration for an Assessment, falling
	 * back to the schema defaults (which MUST match
	 * Assessment.itemAnalysisConfig's declared JSON Schema `default` values)
	 * when Assessment.itemAnalysisConfig is unset.
	 *
	 * @param array<string,mixed>|null $assessment Assessment data, or null when unresolvable.
	 *
	 * @return array{minSampleSize: int, reliabilityMinSampleSize: int, tooDifficultyBelow: float,
	 *               tooEasyAbove: float, lowDiscriminationBelow: float}
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
	 */
	public function resolveConfig(?array $assessment): array;
}//end interface
