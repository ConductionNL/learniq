<?php

/**
 * Learniq Regulation coverage rounding tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/specs/compliance-rule-coverage/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;

/**
 * A regulation's materialised coverage fits a stored number.
 *
 * A stored number keeps at most six decimals; OpenRegister refuses a save
 * whose value needs more. 12 of 17 completed is 70.588235...%, so four of the
 * corporate set's regulations (VCA, NEN 3140, Heftruck, F-gassen) were refused
 * on a PostgreSQL instance before the calculation rounded.
 */
class RegulationCoverageRoundingTest extends TestCase {
	use RegisterSchemaPayloads;

	/**
	 * The coverage rounds the completed share of the enrolled to two decimals.
	 *
	 * @return void
	 */
	public function testCoverageIsRoundedToTwoDecimals(): void {
		$calculation = self::shippedSchema(slug: 'regulation')['x-openregister-calculations']['coveragePercent'];

		self::assertTrue(condition: $calculation['materialise']);
		self::assertSame(expected: ['round'], actual: array_keys($calculation['expression']));
		self::assertSame(expected: 2, actual: $calculation['expression']['round'][1]);
		self::assertSame(expected: ['if'], actual: array_keys($calculation['expression']['round'][0]));
		self::assertSame(expected: 70.59, actual: round(12 / 17 * 100, 2));
	}//end testCoverageIsRoundedToTwoDecimals()
}//end class
