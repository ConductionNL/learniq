<?php

/**
 * Learniq GradeScaleRule unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Grading
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/grading/spec.md#requirement-the-scale-decides-how-a-final-grade-is-rounded-and-whether-it-passes
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Grading;

use OCA\Learniq\Grading\GradeScaleRule;
use PHPUnit\Framework\TestCase;

/**
 * Tests the rounding rules and the pass verdict of a grade scale.
 */
class GradeScaleRuleTest extends TestCase {

	/**
	 * Each rounding rule rounds as its name says.
	 *
	 * @return void
	 */
	public function testEachRoundingRule(): void {
		$rule = static fn (string $name): GradeScaleRule => new GradeScaleRule(kind: 'numeric', passThreshold: null, roundingRule: $name);

		self::assertSame(5.45, $rule('none')->round(value: 5.45));
		self::assertSame(5.5, $rule('half-up-1dp')->round(value: 5.45));
		self::assertSame(5.4, $rule('half-up-1dp')->round(value: 5.44));
		self::assertSame(6.0, $rule('half-up-int')->round(value: 5.5));
		self::assertSame(5.0, $rule('half-up-int')->round(value: 5.49));
		self::assertSame(5.5, $rule('nearest-half')->round(value: 5.3));
		self::assertSame(5.0, $rule('nearest-half')->round(value: 5.2));
		self::assertSame(6.0, $rule('nearest-half')->round(value: 5.75));
		self::assertSame(5.45, $rule('banker')->round(value: 5.45), 'an unknown rule rounds nothing');
	}//end testEachRoundingRule()

	/**
	 * A numeric scale passes at or above its limit; without a limit everything passes.
	 *
	 * @return void
	 */
	public function testANumericScalePassesAtItsLimit(): void {
		$scale = GradeScaleRule::fromScale(scale: ['kind' => 'numeric', 'passThreshold' => '5.5']);

		self::assertTrue($scale->passes(value: 5.5));
		self::assertFalse($scale->passes(value: 5.4));
		self::assertTrue(GradeScaleRule::fromScale(scale: ['kind' => 'percentage'])->passes(value: 0.0));
		self::assertSame('none', $scale->roundingRule);
	}//end testANumericScalePassesAtItsLimit()

	/**
	 * A band scale passes on the band's flag, and a value in no band does not pass.
	 *
	 * @return void
	 */
	public function testABandScalePassesOnTheBandFlag(): void {
		$scale = GradeScaleRule::fromScale(
			scale: [
				'kind' => 'ects',
				'passThreshold' => 1.0,
				'bands' => [
					['bandId' => 'A', 'minValue' => 8.0, 'maxValue' => 10.0, 'pass' => true],
					['bandId' => 'F', 'minValue' => 1.0, 'maxValue' => 5.4, 'pass' => false],
					'not a band',
				],
			]
		);

		self::assertTrue($scale->passesByBand());
		self::assertTrue($scale->passes(value: 9.0));
		self::assertFalse($scale->passes(value: 3.0));
		self::assertFalse($scale->passes(value: 7.0), 'a value in no band does not pass');
	}//end testABandScalePassesOnTheBandFlag()
}//end class
