<?php

/**
 * Learniq GradePlan unit tests.
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

use OCA\Learniq\Grading\GradePlan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GradePlan.
 */
#[CoversClass(GradePlan::class)]
class GradePlanTest extends TestCase {

	/**
	 * Formula components come first, then each component a rule names; final-grade rules add nothing.
	 *
	 * @return void
	 */
	public function testRequiredComponentIdsListsFormulaThenRuleComponents(): void {
		$plan = new GradePlan(
			passRules: [
				['componentId' => 'oral', 'minValue' => 5.5],
				['componentId' => null, 'minValue' => 5.5],
				['minValue' => 6],
				['componentId' => 'exam', 'minValue' => 5],
			],
			components: ['exam' => [], 7 => []],
		);

		$this->assertSame(['exam', '7', 'oral', 'exam'], $plan->requiredComponentIds());
	}//end testRequiredComponentIdsListsFormulaThenRuleComponents()

	/**
	 * A plan with no rules is met by any value.
	 *
	 * @return void
	 */
	public function testNoRulesIsAlwaysMet(): void {
		$plan = new GradePlan(passRules: [], components: []);

		$this->assertTrue($plan->rulesMetBy(value: 0.0, bestMap: []));
		$this->assertSame([], $plan->requiredComponentIds());
	}//end testNoRulesIsAlwaysMet()

	/**
	 * A final-grade rule compares the final value inclusively against its minimum.
	 *
	 * @return void
	 */
	public function testFinalRuleComparesFinalValueInclusively(): void {
		$plan = new GradePlan(passRules: [['minValue' => 5.5]], components: []);

		$this->assertTrue($plan->rulesMetBy(value: 5.5, bestMap: []));
		$this->assertFalse($plan->rulesMetBy(value: 5.49, bestMap: []));
	}//end testFinalRuleComparesFinalValueInclusively()

	/**
	 * A rule without minValue has a minimum of zero.
	 *
	 * @return void
	 */
	public function testMissingMinValueDefaultsToZero(): void {
		$plan = new GradePlan(passRules: [[]], components: []);

		$this->assertTrue($plan->rulesMetBy(value: 0.0, bestMap: []));
		$this->assertFalse($plan->rulesMetBy(value: -0.1, bestMap: []));
	}//end testMissingMinValueDefaultsToZero()

	/**
	 * A component rule is judged on the component's best entry, not the final value.
	 *
	 * @return void
	 */
	public function testComponentRuleUsesBestEntry(): void {
		$plan = new GradePlan(passRules: [['componentId' => 'exam', 'minValue' => 5.5]], components: []);

		$this->assertTrue($plan->rulesMetBy(value: 1.0, bestMap: ['exam' => ['value' => 5.5]]));
		$this->assertFalse($plan->rulesMetBy(value: 10.0, bestMap: ['exam' => ['value' => 5.4]]));
		$this->assertFalse($plan->rulesMetBy(value: 10.0, bestMap: ['exam' => []]));
	}//end testComponentRuleUsesBestEntry()

	/**
	 * A component rule with no best entry fails.
	 *
	 * @return void
	 */
	public function testComponentRuleWithoutEntryFails(): void {
		$plan = new GradePlan(passRules: [['componentId' => 'exam', 'minValue' => 0]], components: []);

		$this->assertFalse($plan->rulesMetBy(value: 10.0, bestMap: ['other' => ['value' => 10]]));
	}//end testComponentRuleWithoutEntryFails()

	/**
	 * An exemption meets a component rule without a numeric comparison.
	 *
	 * @return void
	 */
	public function testExemptionMeetsComponentRule(): void {
		$plan = new GradePlan(passRules: [['componentId' => 'exam', 'minValue' => 9]], components: []);

		$this->assertTrue($plan->rulesMetBy(value: 0.0, bestMap: ['exam' => ['sourceKind' => 'exemption', 'value' => null]]));
	}//end testExemptionMeetsComponentRule()

	/**
	 * Every rule must hold: one failing rule fails the plan.
	 *
	 * @return void
	 */
	public function testOneFailingRuleFailsThePlan(): void {
		$plan = new GradePlan(
			passRules: [
				['componentId' => 'a', 'minValue' => 5],
				['minValue' => 6],
			],
			components: [],
		);

		$this->assertTrue($plan->rulesMetBy(value: 6.0, bestMap: ['a' => ['value' => 5]]));
		$this->assertFalse($plan->rulesMetBy(value: 5.9, bestMap: ['a' => ['value' => 5]]));
		$this->assertFalse($plan->rulesMetBy(value: 6.0, bestMap: ['a' => ['value' => 4]]));
	}//end testOneFailingRuleFailsThePlan()
}//end class
