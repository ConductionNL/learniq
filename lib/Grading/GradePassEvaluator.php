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
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#requirement-a-plan-satisfied-entirely-by-exemptions-passes
	 */
	public function evaluatePassed(
		string $formula,
		?float $value,
		array $entries,
		array $passRules,
		?float $passThreshold,
		array $components = [],
	): ?bool {
		if ($value === null) {
			return $this->exemptionsCoverThePlan(entries: $entries, passRules: $passRules, components: $components);
		}

		// Threshold check (applies to all formulas).
		if ($passThreshold !== null && $value < $passThreshold) {
			return false;
		}

		if ($formula !== 'all-must-pass' || empty($passRules) === true) {
			return true;
		}

		return $this->everyRuleMet(value: $value, entries: $entries, passRules: $passRules);
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
	 * @param array $passRules passRules from the CurriculumPlan.
	 * @param array<string, array> $components The plan's component index.
	 *
	 * @return true|null
	 *
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#requirement-a-plan-satisfied-entirely-by-exemptions-passes
	 */
	private function exemptionsCoverThePlan(array $entries, array $passRules, array $components): ?bool {
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

		$required = array_map('strval', array_keys($components));
		foreach ($passRules as $rule) {
			$ruleComponentId = (string)($rule['componentId'] ?? '');
			if ($ruleComponentId !== '') {
				$required[] = $ruleComponentId;
			}
		}

		foreach ($required as $componentId) {
			if (isset($exempted[$componentId]) === false) {
				return null;
			}
		}

		return true;
	}//end exemptionsCoverThePlan()

	/**
	 * Check every `all-must-pass` rule.
	 *
	 * A rule's minimum is `minValue`, the property the register declares. A
	 * rule with a componentId is met by the learner's best entry for that
	 * component; a rule with componentId null applies to the final grade and
	 * is met by the final value.
	 *
	 * Exam-board-case-handling: a component whose best entry is sourceKind
	 * exemption has no numeric value to compare — the exam board's decision
	 * *is* the pass signal, so that component's rule is satisfied without a
	 * numeric check. Every other component's check is unaffected.
	 *
	 * @param float $value The computed final value.
	 * @param array<int, array> $entries Published entries.
	 * @param array $passRules passRules from the CurriculumPlan.
	 *
	 * @return bool True when every rule is met.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#requirement-pass-rules-apply-their-declared-minimum
	 */
	private function everyRuleMet(float $value, array $entries, array $passRules): bool {
		$bestMap = $this->indexBestByComponent(entries: $entries);

		foreach ($passRules as $rule) {
			$ruleComponentId = (string)($rule['componentId'] ?? '');
			$minValue = (float)($rule['minValue'] ?? 0);

			if ($ruleComponentId === '') {
				if ($value < $minValue) {
					return false;
				}

				continue;
			}

			if ($this->componentMeets(bestEntry: $bestMap[$ruleComponentId] ?? null, minValue: $minValue) === false) {
				return false;
			}
		}//end foreach

		return true;
	}//end everyRuleMet()

	/**
	 * Whether a component's best entry meets its rule's minimum.
	 *
	 * @param array|null $bestEntry The learner's best entry for the component, or null when none.
	 * @param float $minValue The rule's minValue.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#requirement-pass-rules-apply-their-declared-minimum
	 */
	private function componentMeets(?array $bestEntry, float $minValue): bool {
		if ($bestEntry === null) {
			return false;
		}

		if (($bestEntry['sourceKind'] ?? null) === 'exemption') {
			// Satisfied by the exam board's decision — no numeric comparison.
			return true;
		}

		return (float)($bestEntry['value'] ?? 0) >= $minValue;
	}//end componentMeets()

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
