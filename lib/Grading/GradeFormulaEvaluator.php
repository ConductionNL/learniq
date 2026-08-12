<?php

/**
 * Scholiq Grade Formula Evaluator
 *
 * Stateless calculation engine that applies a CurriculumPlan's declared formula
 * over a learner's published GradeEntries to produce a final grade value, a
 * pass/fail verdict, and a per-period/per-component breakdown.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata."
 * The weighted-average / last-attempt / best-of-n / all-must-pass formulas
 * cannot be expressed in JSON-logic; they require iteration over aggregated
 * GradeEntry sets and conditional branching on CurriculumPlan.passRules.
 * Single responsibility: evaluate → return; no state, no audit writes.
 *
 * Consumed by:
 *   - GradeRollupHandler (via ObjectTransitionedEvent)
 *   - FinalGrade x-openregister-calculations engine (referenced by FQCN)
 *
 * @category Grading
 * @package  OCA\Scholiq\Grading
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Scholiq\Grading;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Evaluates CurriculumPlan formulas over a learner's published GradeEntries.
 */
class GradeFormulaEvaluator {

	private const SCHOLIQ_REGISTER = 'scholiq';
	private const GRADE_ENTRY_SCHEMA = 'grade-entry';
	private const CURRICULUM_PLAN_SCHEMA = 'curriculum-plan';
	private const GRADE_SCALE_SCHEMA = 'grade-scale';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param GradeAggregationEngine $aggregation Formula reduction + weighted-average arithmetic.
	 * @param GradePassEvaluator $passEvaluator Pass/fail verdict over the aggregated value.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly GradeAggregationEngine $aggregation,
		private readonly GradePassEvaluator $passEvaluator,
	) {
	}//end __construct()

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
	 */
	public function evaluate(string $curriculumPlanId, string $learnerId): array {
		$plan = $this->fetchPlan(curriculumPlanId: $curriculumPlanId);
		if ($plan === null) {
			return $this->emptyResult();
		}

		$entries = $this->fetchPublishedEntries(
			curriculumPlanId: $curriculumPlanId,
			learnerId: $learnerId
		);

		if (empty($entries) === true) {
			return $this->emptyResult();
		}

		$components = $this->aggregation->indexComponents(plan: $plan);
		$formula = $plan['formula'] ?? 'weighted-average';
		$passRules = $plan['passRules'] ?? [];
		$gradeScaleId = $plan['gradeScaleId'] ?? null;

		$passThreshold = $this->fetchPassThreshold(gradeScaleId: $gradeScaleId);

		[$value, $breakdown] = $this->aggregation->applyFormula(
			formula: $formula,
			entries: $entries,
			components: $components
		);

		$passed = $this->passEvaluator->evaluatePassed(
			formula: $formula,
			value: $value,
			entries: $entries,
			passRules: $passRules,
			passThreshold: $passThreshold
		);

		return [
			'value' => $value,
			'passed' => $passed,
			'breakdown' => $breakdown,
			'lastRecomputedAt' => (new DateTimeImmutable())->format(\DATE_ATOM),
		];

	}//end evaluate()

	/**
	 * Fetch the CurriculumPlan object.
	 *
	 * @param string $curriculumPlanId UUID.
	 *
	 * @return array|null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
	 */
	private function fetchPlan(string $curriculumPlanId): ?array {
		$obj = $this->objectService->find(
			id: $curriculumPlanId,
			register: self::SCHOLIQ_REGISTER,
			schema: self::CURRICULUM_PLAN_SCHEMA
		);

		if ($obj === null) {
			return null;
		}

		return $obj->jsonSerialize();
	}//end fetchPlan()

	/**
	 * Fetch all published GradeEntries for this learner on this plan.
	 *
	 * @param string $curriculumPlanId UUID.
	 * @param string $learnerId NC user ID.
	 *
	 * @return array<int, array>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
	 */
	private function fetchPublishedEntries(string $curriculumPlanId, string $learnerId): array {
		$results = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::GRADE_ENTRY_SCHEMA,
				'filters' => [
					'learnerId' => $learnerId,
					'curriculumPlanId' => $curriculumPlanId,
					'lifecycle' => 'published',
				],
			]
		);

		if (empty($results) === true) {
			return [];
		}

		return array_map(
			static function ($obj) {
				if (is_array($obj) === true) {
					return $obj;
				}

				return $obj->jsonSerialize();
			},
			$results
		);

	}//end fetchPublishedEntries()

	/**
	 * Fetch the passThreshold from the GradeScale.
	 *
	 * @param string|null $gradeScaleId UUID or null.
	 *
	 * @return float|null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
	 */
	private function fetchPassThreshold(?string $gradeScaleId): ?float {
		if ($gradeScaleId === null) {
			return null;
		}

		$obj = $this->objectService->find(
			id: $gradeScaleId,
			register: self::SCHOLIQ_REGISTER,
			schema: self::GRADE_SCALE_SCHEMA
		);

		if ($obj === null) {
			return null;
		}

		$scale = $obj->jsonSerialize();

		$threshold = $scale['passThreshold'] ?? null;
		if ($threshold === null) {
			return null;
		}

		return (float)$threshold;
	}//end fetchPassThreshold()

	/**
	 * Return an empty result (no entries yet).
	 *
	 * @return array{value: null, passed: null, breakdown: array, lastRecomputedAt: string}
	 */
	private function emptyResult(): array {
		return [
			'value' => null,
			'passed' => null,
			'breakdown' => [],
			'lastRecomputedAt' => (new DateTimeImmutable())->format(\DATE_ATOM),
		];

	}//end emptyResult()
}//end class
