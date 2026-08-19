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
	 * @param string $formula Formula name.
	 * @param float|null $value Computed final value.
	 * @param array<int, array> $entries Published entries.
	 * @param array $passRules passRules from the CurriculumPlan.
	 * @param float|null $passThreshold Threshold from the GradeScale.
	 *
	 * @return bool|null Null if insufficient data.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
	 */
	public function evaluatePassed(
		string $formula,
		?float $value,
		array $entries,
		array $passRules,
		?float $passThreshold,
	): ?bool {
		if ($value === null) {
			return null;
		}

		// Threshold check (applies to all formulas).
		if ($passThreshold !== null && $value < $passThreshold) {
			return false;
		}

		if ($formula !== 'all-must-pass' || empty($passRules) === true) {
			return true;
		}

		return $this->everyComponentRuleWith(entries: $entries, passRules: $passRules);
	}//end evaluatePassed()

	/**
	 * Check every `all-must-pass` component rule against the learner's best entry per component.
	 *
	 * Exam-board-case-handling: a component whose best entry is sourceKind
	 * exemption has no numeric value to compare — the exam board's decision
	 * *is* the pass signal, so that component's rule is satisfied without a
	 * numeric check. Every other component's check is unaffected.
	 *
	 * @param array<int, array> $entries Published entries.
	 * @param array $passRules passRules from the CurriculumPlan.
	 *
	 * @return bool True when every rule is met.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
	 */
	private function everyComponentRuleWith(array $entries, array $passRules): bool {
		$bestMap = $this->indexBestByComponent(entries: $entries);

		foreach ($passRules as $rule) {
			$ruleComponentId = $rule['componentId'] ?? '';
			$ruleThreshold = (float)($rule['passThreshold'] ?? 0);
			$bestEntry = $bestMap[$ruleComponentId] ?? null;

			if ($bestEntry === null) {
				return false;
			}

			if (($bestEntry['sourceKind'] ?? null) === 'exemption') {
				// Satisfied by the exam board's decision — no numeric comparison.
				continue;
			}

			if ((float)($bestEntry['value'] ?? 0) < $ruleThreshold) {
				return false;
			}
		}//end foreach

		return true;
	}//end everyComponentRuleMet()

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
