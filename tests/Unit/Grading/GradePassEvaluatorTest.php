<?php

/**
 * Learniq GradePassEvaluator unit tests.
 *
 * Pins the two verdict defects the example sets surfaced (learniq #1052 and
 * #1055): the evaluator read `passRules[].passThreshold` while the register
 * declares `passRules[].minValue`, so component minimums compared against 0;
 * a rule with `componentId: null` (the final grade) was looked up as
 * component `''`; and an exemption-only plan came out `passed: null`, which
 * study advice counts as no credit.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Grading
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
 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#requirement-pass-rules-apply-their-declared-minimum
 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#requirement-a-plan-satisfied-entirely-by-exemptions-passes
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Grading;

use OCA\Learniq\Grading\GradeAggregationEngine;
use OCA\Learniq\Grading\GradePassEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GradePassEvaluator::evaluatePassed().
 */
class GradePassEvaluatorTest extends TestCase {

	/**
	 * The evaluator under test, over the real (stateless) aggregation engine.
	 *
	 * @var GradePassEvaluator
	 */
	private GradePassEvaluator $evaluator;

	/**
	 * Build the evaluator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->evaluator = new GradePassEvaluator(aggregation: new GradeAggregationEngine());
	}//end setUp()

	/**
	 * A graded entry.
	 *
	 * @param string $componentId Component id.
	 * @param float $value Grade value.
	 *
	 * @return array<string,mixed>
	 */
	private static function graded(string $componentId, float $value): array {
		return ['componentId' => $componentId, 'sourceKind' => 'manual', 'value' => $value, 'weight' => 1, 'gradedAt' => '2026-01-01T00:00:00Z'];
	}//end graded()

	/**
	 * An exemption entry, as ExemptionCase writes it: no value.
	 *
	 * @param string|null $componentId Component id, or null for a plan without components.
	 *
	 * @return array<string,mixed>
	 */
	private static function exempted(?string $componentId): array {
		return ['componentId' => $componentId, 'sourceKind' => 'exemption', 'value' => null, 'weight' => 1, 'gradedAt' => '2026-01-02T00:00:00Z'];
	}//end exempted()

	/**
	 * A component index as GradeAggregationEngine::indexComponents() builds it.
	 *
	 * @param string ...$componentIds Component ids.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function components(string ...$componentIds): array {
		$index = [];
		foreach ($componentIds as $componentId) {
			$index[$componentId] = ['componentId' => $componentId, 'weight' => 1, 'period' => 'P1', 'kind' => 'assessment', 'label' => $componentId];
		}

		return $index;
	}//end components()

	/**
	 * A component under its minValue fails the plan even when the average
	 * clears the scale threshold. Red before the fix: the evaluator read
	 * `passThreshold`, found nothing, and compared 4.0 against 0.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#scenario-a-component-below-its-minimum-fails-the-plan
	 */
	public function testAComponentBelowItsMinValueFailsThePlan(): void {
		$rules = [
			['componentId' => 'comp-a', 'minValue' => 5.5],
			['componentId' => 'comp-b', 'minValue' => 5.5],
		];
		$entries = [self::graded('comp-a', 8.0), self::graded('comp-b', 4.0)];

		$passed = $this->evaluator->evaluatePassed(
			formula: 'all-must-pass',
			value: 6.0,
			entries: $entries,
			passRules: $rules,
			passThreshold: 5.5
		);

		self::assertFalse($passed);
	}//end testAComponentBelowItsMinValueFailsThePlan()

	/**
	 * Every component at or above its minValue passes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#scenario-every-component-at-or-above-its-minimum-passes
	 */
	public function testEveryComponentAtItsMinValuePasses(): void {
		$rules = [
			['componentId' => 'comp-a', 'minValue' => 5.5],
			['componentId' => 'comp-b', 'minValue' => 5.5],
		];
		$entries = [self::graded('comp-a', 6.0), self::graded('comp-b', 5.5)];

		self::assertTrue(
			$this->evaluator->evaluatePassed(formula: 'all-must-pass', value: 5.75, entries: $entries, passRules: $rules, passThreshold: 5.5)
		);
	}//end testEveryComponentAtItsMinValuePasses()

	/**
	 * A rule with componentId null is the final-grade minimum. Red before the
	 * fix: it was looked up as component '' and failed every learner whose
	 * entries carry a component id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#scenario-a-final-grade-rule-compares-the-final-value
	 */
	public function testAFinalGradeRuleComparesTheFinalValue(): void {
		$rules = [['componentId' => null, 'minValue' => 5.5]];

		$passing = $this->evaluator->evaluatePassed(
			formula: 'all-must-pass',
			value: 6.5,
			entries: [self::graded('comp-a', 7.0), self::graded('comp-b', 6.0)],
			passRules: $rules,
			passThreshold: null
		);
		$failing = $this->evaluator->evaluatePassed(
			formula: 'all-must-pass',
			value: 5.0,
			entries: [self::graded('comp-a', 5.0), self::graded('comp-b', 5.0)],
			passRules: $rules,
			passThreshold: null
		);

		self::assertTrue($passing);
		self::assertFalse($failing);
	}//end testAFinalGradeRuleComparesTheFinalValue()

	/**
	 * A plan whose only component is exempted passes with no value. Red
	 * before the fix: a null value always gave `passed: null`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#scenario-an-exempted-unit-passes
	 */
	public function testAnExemptionOnlyPlanPasses(): void {
		$passed = $this->evaluator->evaluatePassed(
			formula: 'weighted-average',
			value: null,
			entries: [self::exempted('unit-1')],
			passRules: [],
			passThreshold: 5.5,
			components: self::components('unit-1')
		);

		self::assertTrue($passed);
	}//end testAnExemptionOnlyPlanPasses()

	/**
	 * An exemption-only all-must-pass plan with a final-grade rule passes:
	 * the exam board's decision stands in for the missing final value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#scenario-an-exempted-unit-passes
	 */
	public function testAnExemptionOnlyAllMustPassPlanWithAFinalGradeRulePasses(): void {
		$passed = $this->evaluator->evaluatePassed(
			formula: 'all-must-pass',
			value: null,
			entries: [self::exempted(null)],
			passRules: [['componentId' => null, 'minValue' => 5.5]],
			passThreshold: 5.5
		);

		self::assertTrue($passed);
	}//end testAnExemptionOnlyAllMustPassPlanWithAFinalGradeRulePasses()

	/**
	 * A plan without declared components passes on its exemption entries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#requirement-a-plan-satisfied-entirely-by-exemptions-passes
	 */
	public function testAnExemptionOnlyPlanWithoutComponentsPasses(): void {
		self::assertTrue(
			$this->evaluator->evaluatePassed(formula: 'best-of-n', value: null, entries: [self::exempted(null)], passRules: [], passThreshold: 5.5)
		);
	}//end testAnExemptionOnlyPlanWithoutComponentsPasses()

	/**
	 * One exemption on a two-component plan leaves the verdict open.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#scenario-a-partial-exemption-with-a-missing-component-stays-open
	 */
	public function testAPartialExemptionStaysOpen(): void {
		$passed = $this->evaluator->evaluatePassed(
			formula: 'weighted-average',
			value: null,
			entries: [self::exempted('unit-1')],
			passRules: [],
			passThreshold: 5.5,
			components: self::components('unit-1', 'unit-2')
		);

		self::assertNull($passed);
	}//end testAPartialExemptionStaysOpen()

	/**
	 * An exemption on a component while a rule names another, unentered
	 * component leaves the verdict open, not failed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#scenario-a-partial-exemption-with-a-missing-component-stays-open
	 */
	public function testARuleComponentWithoutAnExemptionKeepsTheVerdictOpen(): void {
		$passed = $this->evaluator->evaluatePassed(
			formula: 'all-must-pass',
			value: null,
			entries: [self::exempted('unit-1')],
			passRules: [['componentId' => 'unit-2', 'minValue' => 5.5]],
			passThreshold: null
		);

		self::assertNull($passed);
	}//end testARuleComponentWithoutAnExemptionKeepsTheVerdictOpen()

	/**
	 * No entries at all stays insufficient data.
	 *
	 * @return void
	 */
	public function testNoEntriesStaysNull(): void {
		self::assertNull(
			$this->evaluator->evaluatePassed(formula: 'weighted-average', value: null, entries: [], passRules: [], passThreshold: 5.5)
		);
	}//end testNoEntriesStaysNull()
}//end class
