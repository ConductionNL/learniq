<?php

/**
 * Learniq GradeAggregationEngine unit tests.
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GradeAggregationEngine.
 */
#[CoversClass(GradeAggregationEngine::class)]
class GradeAggregationEngineTest extends TestCase {

	/**
	 * The engine under test.
	 *
	 * @var GradeAggregationEngine
	 */
	private GradeAggregationEngine $engine;

	/**
	 * Build the engine.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->engine = new GradeAggregationEngine();
	}//end setUp()

	/**
	 * Components are keyed by componentId; components without one are dropped.
	 *
	 * @return void
	 */
	public function testIndexComponentsKeysByIdAndSkipsUnidentified(): void {
		$plan = [
			'components' => [
				['componentId' => 'exam', 'weight' => 2],
				['weight' => 5],
				['componentId' => 'essay', 'weight' => 1],
			],
		];

		$this->assertSame(
			[
				'exam' => ['componentId' => 'exam', 'weight' => 2],
				'essay' => ['componentId' => 'essay', 'weight' => 1],
			],
			$this->engine->indexComponents(plan: $plan)
		);
	}//end testIndexComponentsKeysByIdAndSkipsUnidentified()

	/**
	 * A plan without components yields an empty index.
	 *
	 * @return void
	 */
	public function testIndexComponentsWithoutComponentsIsEmpty(): void {
		$this->assertSame([], $this->engine->indexComponents(plan: []));
	}//end testIndexComponentsWithoutComponentsIsEmpty()

	/**
	 * The most recent attempt per component wins, regardless of input order.
	 *
	 * @return void
	 */
	public function testLastAttemptKeepsMostRecentPerComponent(): void {
		$entries = [
			['componentId' => 'a', 'value' => 9, 'gradedAt' => '2026-03-01T00:00:00Z'],
			['componentId' => 'a', 'value' => 4, 'gradedAt' => '2026-01-01T00:00:00Z'],
			['componentId' => 'b', 'value' => 5, 'gradedAt' => '2026-01-01T00:00:00Z'],
			['componentId' => 'b', 'value' => 7, 'gradedAt' => '2026-02-01T00:00:00Z'],
		];

		$result = $this->engine->lastAttemptEntries(entries: $entries);

		$this->assertSame([9, 7], array_column($result, 'value'));
		$this->assertSame(['a', 'b'], array_column($result, 'componentId'));
	}//end testLastAttemptKeepsMostRecentPerComponent()

	/**
	 * An unparseable or missing gradedAt sorts as the epoch, and a tie keeps the first entry.
	 *
	 * @return void
	 */
	public function testLastAttemptTreatsBadDatesAsEpochAndKeepsFirstOnTie(): void {
		$entries = [
			['componentId' => 'a', 'value' => 1, 'gradedAt' => 'not a date'],
			['componentId' => 'a', 'value' => 2],
			['componentId' => 'a', 'value' => 3, 'gradedAt' => '1970-01-01T00:00:00Z'],
		];

		$result = $this->engine->lastAttemptEntries(entries: $entries);

		$this->assertCount(1, $result);
		$this->assertSame(1, $result[0]['value']);
	}//end testLastAttemptTreatsBadDatesAsEpochAndKeepsFirstOnTie()

	/**
	 * Entries without a componentId are grouped under the empty id.
	 *
	 * @return void
	 */
	public function testLastAttemptGroupsMissingComponentIdTogether(): void {
		$entries = [
			['value' => 3, 'gradedAt' => '2026-01-01T00:00:00Z'],
			['value' => 8, 'gradedAt' => '2026-05-01T00:00:00Z'],
		];

		$result = $this->engine->lastAttemptEntries(entries: $entries);

		$this->assertSame([8], array_column($result, 'value'));
	}//end testLastAttemptGroupsMissingComponentIdTogether()

	/**
	 * The highest value per component wins; a tie keeps the first.
	 *
	 * @return void
	 */
	public function testBestOfNKeepsHighestValuePerComponent(): void {
		$entries = [
			['componentId' => 'a', 'value' => 6, 'id' => 1],
			['componentId' => 'a', 'value' => 8.5, 'id' => 2],
			['componentId' => 'a', 'value' => 8.5, 'id' => 3],
			['componentId' => 'b', 'value' => 4, 'id' => 4],
			['componentId' => 'b', 'id' => 5],
		];

		$result = $this->engine->bestOfNEntries(entries: $entries);

		$this->assertSame([2, 4], array_column($result, 'id'));
	}//end testBestOfNKeepsHighestValuePerComponent()

	/**
	 * The weighted average uses the plan's component weights and breaks down per period and component.
	 *
	 * @return void
	 */
	public function testWeightedAverageUsesPlanWeightsAndBreaksDown(): void {
		$components = ['exam' => ['weight' => 3], 'essay' => ['weight' => 1]];
		$entries = [
			['componentId' => 'exam', 'value' => 8, 'period' => 'P1'],
			['componentId' => 'essay', 'value' => 4, 'period' => 'P2'],
		];

		[$value, $breakdown] = $this->engine->applyFormula(formula: 'weighted-average', entries: $entries, components: $components);

		$this->assertSame(7.0, $value);
		$this->assertSame(['P1' => 8.0, 'P2' => 4.0], $breakdown['periods']);
		$this->assertSame(
			[
				'exam' => ['value' => 8.0, 'weight' => 3.0, 'contribution' => 24.0],
				'essay' => ['value' => 4.0, 'weight' => 1.0, 'contribution' => 4.0],
			],
			$breakdown['components']
		);
	}//end testWeightedAverageUsesPlanWeightsAndBreaksDown()

	/**
	 * A per-entry weight overrides the plan weight; an unknown component weighs 1; no period is "unknown".
	 *
	 * @return void
	 */
	public function testEntryWeightOverridesPlanAndDefaultsApply(): void {
		$components = ['exam' => ['weight' => 3]];
		$entries = [
			['componentId' => 'exam', 'value' => 10, 'weight' => 1],
			['componentId' => 'other', 'value' => 5],
		];

		[$value, $breakdown] = $this->engine->applyFormula(formula: 'weighted-average', entries: $entries, components: $components);

		$this->assertSame(7.5, $value);
		$this->assertSame(['unknown' => 7.5], $breakdown['periods']);
		$this->assertSame(1.0, $breakdown['components']['exam']['weight']);
		$this->assertSame(1.0, $breakdown['components']['other']['weight']);
	}//end testEntryWeightOverridesPlanAndDefaultsApply()

	/**
	 * The average is rounded to four decimals.
	 *
	 * @return void
	 */
	public function testAverageIsRoundedToFourDecimals(): void {
		$entries = [
			['componentId' => 'a', 'value' => 1],
			['componentId' => 'b', 'value' => 1],
			['componentId' => 'c', 'value' => 0],
		];

		[$value, $breakdown] = $this->engine->applyFormula(formula: 'all-must-pass', entries: $entries, components: []);

		$this->assertSame(0.6667, $value);
		$this->assertSame(['unknown' => 0.6667], $breakdown['periods']);
	}//end testAverageIsRoundedToFourDecimals()

	/**
	 * An exemption is listed as exempt and does not drag the average down.
	 *
	 * @return void
	 */
	public function testExemptionIsExcludedFromTheAverage(): void {
		$entries = [
			['componentId' => 'a', 'value' => 8],
			['componentId' => 'b', 'sourceKind' => 'exemption', 'value' => null],
		];

		[$value, $breakdown] = $this->engine->applyFormula(formula: 'weighted-average', entries: $entries, components: []);

		$this->assertSame(8.0, $value);
		$this->assertSame(['exempt' => true], $breakdown['components']['b']);
	}//end testExemptionIsExcludedFromTheAverage()

	/**
	 * Only exemptions (or no entries) yields a null value and the bare component breakdown.
	 *
	 * @return void
	 */
	public function testNoWeightYieldsNullValue(): void {
		$entries = [['componentId' => 'a', 'sourceKind' => 'exemption']];

		$this->assertSame([null, ['a' => ['exempt' => true]]], $this->engine->applyFormula(formula: 'weighted-average', entries: $entries, components: []));
		$this->assertSame([null, []], $this->engine->applyFormula(formula: 'weighted-average', entries: [], components: []));
	}//end testNoWeightYieldsNullValue()

	/**
	 * A zero-weight period averages to null while others still count.
	 *
	 * @return void
	 */
	public function testZeroWeightPeriodAveragesToNull(): void {
		$entries = [
			['componentId' => 'a', 'value' => 6, 'weight' => 0, 'period' => 'P0'],
			['componentId' => 'b', 'value' => 9, 'weight' => 2, 'period' => 'P1'],
		];

		[$value, $breakdown] = $this->engine->applyFormula(formula: 'weighted-average', entries: $entries, components: []);

		$this->assertSame(9.0, $value);
		$this->assertSame(['P0' => null, 'P1' => 9.0], $breakdown['periods']);
	}//end testZeroWeightPeriodAveragesToNull()

	/**
	 * The last-attempt formula averages only the latest attempt per component.
	 *
	 * @return void
	 */
	public function testLastAttemptFormulaAveragesLatestOnly(): void {
		$entries = [
			['componentId' => 'a', 'value' => 2, 'gradedAt' => '2026-01-01T00:00:00Z'],
			['componentId' => 'a', 'value' => 6, 'gradedAt' => '2026-02-01T00:00:00Z'],
			['componentId' => 'b', 'value' => 8, 'gradedAt' => '2026-01-01T00:00:00Z'],
		];

		[$value] = $this->engine->applyFormula(formula: 'last-attempt', entries: $entries, components: []);

		$this->assertSame(7.0, $value);
	}//end testLastAttemptFormulaAveragesLatestOnly()

	/**
	 * The best-of-n formula averages only the best attempt per component.
	 *
	 * @return void
	 */
	public function testBestOfNFormulaAveragesBestOnly(): void {
		$entries = [
			['componentId' => 'a', 'value' => 9],
			['componentId' => 'a', 'value' => 3],
			['componentId' => 'b', 'value' => 5],
		];

		[$value] = $this->engine->applyFormula(formula: 'best-of-n', entries: $entries, components: []);

		$this->assertSame(7.0, $value);
	}//end testBestOfNFormulaAveragesBestOnly()
}//end class
