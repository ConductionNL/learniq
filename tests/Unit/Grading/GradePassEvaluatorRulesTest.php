<?php

/**
 * Characterisation tests for GradePassEvaluator's rule and threshold paths.
 *
 * Complements GradePassEvaluatorTest: the scale threshold, formulas other
 * than all-must-pass, an exempted component inside an all-must-pass plan, a
 * rule naming a component without any entry, and the per-rule minimum on the
 * final value.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Grading;

use OCA\Learniq\Grading\GradeAggregationEngine;
use OCA\Learniq\Grading\GradePassEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pass verdict's rule and threshold paths.
 */
class GradePassEvaluatorRulesTest extends TestCase {

	/**
	 * The evaluator under test.
	 *
	 * @param string                           $formula   The formula.
	 * @param float|null                       $value     The final value.
	 * @param array<int,array<string,mixed>>   $entries   The grade entries.
	 * @param array<int,array<string,mixed>>   $rules     The pass rules.
	 * @param float|null                       $threshold The scale threshold.
	 *
	 * @return bool|null
	 */
	private static function verdict(string $formula, ?float $value, array $entries, array $rules, ?float $threshold): ?bool {
		return (new GradePassEvaluator(aggregation: new GradeAggregationEngine()))->evaluatePassed(
			formula: $formula,
			value: $value,
			entries: $entries,
			passRules: $rules,
			passThreshold: $threshold
		);
	}//end verdict()

	/**
	 * The threshold applies to every formula; other formulas pass above it whatever the rules.
	 *
	 * @return void
	 */
	public function testThresholdAndFormula(): void {
		$rules = [['componentId' => 'a', 'minValue' => 9.0]];
		$entries = [['componentId' => 'a', 'value' => 1.0, 'sourceKind' => 'grade']];

		self::assertFalse(self::verdict('weighted-average', 5.4, $entries, $rules, 5.5));
		self::assertTrue(self::verdict('weighted-average', 5.5, $entries, $rules, 5.5));
		self::assertTrue(self::verdict('weighted-average', 1.0, $entries, $rules, null));
		self::assertTrue(self::verdict('all-must-pass', 6.0, $entries, [], 5.5));
		self::assertFalse(self::verdict('all-must-pass', 6.0, $entries, $rules, 5.5));
	}//end testThresholdAndFormula()

	/**
	 * An exempted component meets its rule; a rule component without entries fails; a final-value rule compares the value.
	 *
	 * @return void
	 */
	public function testRuleKinds(): void {
		$exempt = [['componentId' => 'a', 'value' => 0.0, 'sourceKind' => 'exemption'], ['componentId' => 'b', 'value' => 7.0, 'sourceKind' => 'grade']];
		self::assertTrue(self::verdict('all-must-pass', 7.0, $exempt, [['componentId' => 'a', 'minValue' => 5.5], ['componentId' => 'b', 'minValue' => 5.5]], null));
		self::assertFalse(self::verdict('all-must-pass', 7.0, $exempt, [['componentId' => 'c', 'minValue' => 1.0]], null));

		self::assertTrue(self::verdict('all-must-pass', 6.0, $exempt, [['minValue' => 6.0]], null));
		self::assertFalse(self::verdict('all-must-pass', 5.9, $exempt, [['componentId' => '', 'minValue' => 6.0]], null));
		self::assertTrue(self::verdict('all-must-pass', 5.9, $exempt, [['componentId' => 'b']], null));
	}//end testRuleKinds()
}//end class
