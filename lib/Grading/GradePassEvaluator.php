<?php

/**
 * Learniq Grade Pass Evaluator
 *
 * Stateless verdict half of the grading calculation, extracted from
 * `GradeFormulaEvaluator` so each class carries one cohesive responsibility:
 * this one owns the *pass/fail decision* (the GradeScale threshold check plus
 * the `all-must-pass` per-component rule sweep), while `GradeAggregationEngine`
 * owns the arithmetic and `GradeFormulaEvaluator` the orchestration.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata."
 * The `all-must-pass` verdict requires branching over `CurriculumPlan.passRules`
 * against the best entry per component, which JSON-logic cannot express. Single
 * responsibility: decide → return; no state, no reads, no writes.
 *
 * Consumed by:
 *   - GradeFormulaEvaluator (constructor injection)
 *
 * @category Grading
 * @package  OCA\Learniq\Grading
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

namespace OCA\Learniq\Grading;

/**
 * Decides the pass/fail verdict for an already-aggregated final grade value.
 */
class GradePassEvaluator {
	/**
	 * Constructor.
	 *
	 * @param GradeAggregationEngine $aggregation Supplies the best-entry-per-component set for `all-must-pass`.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly GradeAggregationEngine $aggregation,
	) {
	}//end __construct()

	/**
	 * Determine whether the learner has passed.
	 *
	 * A null value means no numeric entry exists. That is insufficient data,
	 * except when exemptions cover the whole plan: then the exam board's
	 * decision is the pass signal (see exemptionsCoverThePlan()).
	 *
	 * @param string $formula Formula name.
	 * @param float|null $value Computed final value.
	 * @param array<int, array> $entries Published entries.
	 * @param array $passRules passRules from the CurriculumPlan.
	 * @param float|null $passThreshold Threshold from the GradeScale.
	 * @param array<string, array> $components The plan's component index (componentId => component).
	 *
	 * @return bool|null Null if insufficient data.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
	 * @spec openspec/specs/grading/spec.md#requirement-a-plan-satisfied-entirely-by-exemptions-passes
	 */
	public function evaluatePassed(
		string $formula,
		?float $value,
		array $entries,
		array $passRules,
		?float $passThreshold,
		array $components = [],
	): ?bool {
		$plan = new GradePlan(passRules: $passRules, components: $components);
		if ($value === null) {
			return $this->exemptionsCoverThePlan(entries: $entries, plan: $plan);
		}

		// Threshold check (applies to all formulas).
		if ($passThreshold !== null && $value < $passThreshold) {
			return false;
		}

		if ($formula !== 'all-must-pass' || empty($passRules) === true) {
			return true;
		}

		return $plan->rulesMetBy(value: $value, bestMap: $this->indexBestByComponent(entries: $entries));
	}//end evaluatePassed()

	/**
	 * Whether exemptions alone satisfy the plan.
	 *
	 * True only when there is at least one entry, every entry is an
	 * exemption, and an exemption covers every component the plan declares
	 * and every component a pass rule names. A final-grade rule
	 * (componentId null) is satisfied by the exam board's decision, since
	 * there is no final value to compare. Anything else stays null: a learner
	 * exempted from one of two units has not completed the plan.
	 *
	 * @param array<int, array> $entries Published entries.
	 * @param GradePlan $plan The rules and components the grade is judged against.
	 *
	 * @return true|null
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-a-plan-satisfied-entirely-by-exemptions-passes
	 */
	private function exemptionsCoverThePlan(array $entries, GradePlan $plan): ?bool {
		$exempted = self::exemptedComponents(entries: $entries);
		if ($exempted === null) {
			return null;
		}

		foreach ($plan->requiredComponentIds() as $componentId) {
			if (isset($exempted[$componentId]) === false) {
				return null;
			}
		}

		return true;
	}//end exemptionsCoverThePlan()

	/**
	 * The components the entries exempt, or null when there are no entries
	 * or any of them is not an exemption.
	 *
	 * @param array<int,array<string,mixed>> $entries The grade entries.
	 *
	 * @return array<string,true>|null
	 */
	private static function exemptedComponents(array $entries): ?array {
		if ($entries === []) {
			return null;
		}

		$exempted = [];
		foreach ($entries as $entry) {
			if (($entry['sourceKind'] ?? null) !== 'exemption') {
				return null;
			}

			$exempted[(string)($entry['componentId'] ?? '')] = true;
		}

		return $exempted;
	}//end exemptedComponents()

	/**
	 * Build a componentId → best-entry map from the learner's published entries.
	 *
	 * @param array<int, array> $entries Published entries.
	 *
	 * @return array<string, array> Component id → the highest-valued entry for that component.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
	 */
	private function indexBestByComponent(array $entries): array {
		$bestMap = [];

		foreach ($this->aggregation->bestOfNEntries(entries: $entries) as $entry) {
			$bestMap[$entry['componentId'] ?? ''] = $entry;
		}

		return $bestMap;
	}//end indexBestByComponent()
}//end class
