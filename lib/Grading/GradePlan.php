<?php

/**
 * Learniq grade pass plan.
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
 * @spec openspec/specs/grading/spec.md#requirement-a-plan-satisfied-entirely-by-exemptions-passes
 */

declare(strict_types=1);

namespace OCA\Learniq\Grading;

/**
 * What a final grade is judged against: the pass rules (a minimum per
 * component, or on the final value when a rule names none) and the
 * formula's components.
 *
 * @spec openspec/specs/grading/spec.md#requirement-a-plan-satisfied-entirely-by-exemptions-passes
 */
final class GradePlan {

	/**
	 * Constructor.
	 *
	 * @param array<int,array<string,mixed>> $passRules  The pass rules.
	 * @param array<string,mixed>            $components The formula's components, keyed by component id.
	 */
	public function __construct(
		public readonly array $passRules,
		public readonly array $components,
	) {
	}//end __construct()

	/**
	 * Every component the plan needs covered: the formula's components, then
	 * each component a rule names.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-a-plan-satisfied-entirely-by-exemptions-passes
	 */
	public function requiredComponentIds(): array {
		$required = array_map('strval', array_keys($this->components));
		foreach ($this->passRules as $rule) {
			$ruleComponentId = (string)($rule['componentId'] ?? '');
			if ($ruleComponentId !== '') {
				$required[] = $ruleComponentId;
			}
		}

		return $required;
	}//end requiredComponentIds()

	/**
	 * Whether every rule is met: a rule without a component by the final
	 * value, a component rule by the component's best entry.
	 *
	 * @param float                              $value   The final value.
	 * @param array<string,array<string,mixed>>  $bestMap The best entry per component id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-a-plan-satisfied-entirely-by-exemptions-passes
	 */
	public function rulesMetBy(float $value, array $bestMap): bool {
		foreach ($this->passRules as $rule) {
			$ruleComponentId = (string)($rule['componentId'] ?? '');
			$minValue = (float)($rule['minValue'] ?? 0);
			$met = ($value < $minValue) === false;
			if ($ruleComponentId !== '') {
				$met = self::componentMeets(bestEntry: ($bestMap[$ruleComponentId] ?? null), minValue: $minValue);
			}

			if ($met === false) {
				return false;
			}
		}

		return true;
	}//end rulesMetBy()

	/**
	 * Whether a component's best entry meets its minimum; an exemption is met
	 * by the exam board's decision, with no numeric comparison.
	 *
	 * @param array<string,mixed>|null $bestEntry The component's best entry, or null.
	 * @param float                    $minValue  The rule's minimum.
	 *
	 * @return bool
	 */
	private static function componentMeets(?array $bestEntry, float $minValue): bool {
		if ($bestEntry === null) {
			return false;
		}

		if (($bestEntry['sourceKind'] ?? null) === 'exemption') {
			return true;
		}

		return (float)($bestEntry['value'] ?? 0) >= $minValue;
	}//end componentMeets()
}//end class
