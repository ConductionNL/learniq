<?php

/**
 * Learniq register seed objects test.
 *
 * The register file is imported on every install, before the setup wizard
 * asks which example set to load. Any object in `components.objects` therefore
 * lands in every school, company and training institute alike. Found on a
 * clean install that loaded only the primary school set: three compliance
 * courses, an "All Employees 2026" cohort and three learners sat beside the
 * primary school. Only rows the app itself relies on belong in the register;
 * example rows belong in a set under lib/Settings/profiles/.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/register-ships-no-example-rows/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the register seeds reference rows only, never example rows.
 */
class RegisterSeedObjectsTest extends TestCase {

	/**
	 * The schemas whose rows the register may seed: shared reference codes the
	 * example sets point at by code (example-sets spec, "A set does not
	 * re-ship a row the register seeds").
	 */
	private const REFERENCE_SCHEMAS = ['regulation'];

	/**
	 * The register's seed objects as shipped.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function seedObjects(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);

		return ($register['components']['objects'] ?? []);
	}//end seedObjects()

	/**
	 * Every seed object is a reference row, so a clean install holds no
	 * learners, courses, cohorts, programmes or enrolments of any segment.
	 *
	 * @return void
	 */
	public function testTheRegisterSeedsOnlyReferenceRows(): void {
		$schemas = array_map(
			static fn (array $object): string => (string)($object['@self']['schema'] ?? ''),
			$this->seedObjects()
		);

		$this->assertSame([], array_values(array_diff($schemas, self::REFERENCE_SCHEMAS)));
	}//end testTheRegisterSeedsOnlyReferenceRows()

	/**
	 * The AVG regulation the example sets reference by code stays seeded.
	 *
	 * @return void
	 */
	public function testTheAvgRegulationStaysSeeded(): void {
		$slugs = array_map(
			static fn (array $object): string => (string)($object['@self']['slug'] ?? ''),
			$this->seedObjects()
		);

		$this->assertContains('AVG', $slugs);
	}//end testTheAvgRegulationStaysSeeded()
}//end class
