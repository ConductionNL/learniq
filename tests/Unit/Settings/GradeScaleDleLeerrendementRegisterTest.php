<?php

/**
 * Unit test for GradeScale.kind's dle/leerrendement enum addition
 * (trend-and-export-reporting change).
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/trend-and-export-reporting/specs/grading/spec.md#requirement-gradescale-declares-dle-and-leerrendement-as-scale-kinds-for-later-lvs-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies GradeScale.kind's widened enum.
 */
class GradeScaleDleLeerrendementRegisterTest extends TestCase {

	/**
	 * Decoded register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Load the register configuration once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);

	}//end setUp()

	/**
	 * GradeScale.kind's enum includes both new values alongside the six
	 * pre-existing ones, unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/trend-and-export-reporting/specs/grading/spec.md#scenario-a-gradescale-can-be-declared-with-the-new-kind-values
	 */
	public function testGradeScaleKindIncludesDleAndLeerrendement(): void {
		$kindEnum = $this->config['components']['schemas']['GradeScale']['properties']['kind']['enum'];

		self::assertSame(
			['numeric', 'letter', 'ects', 'pass-fail', 'percentage', 'band', 'dle', 'leerrendement'],
			$kindEnum
		);

	}//end testGradeScaleKindIncludesDleAndLeerrendement()

	/**
	 * The register's info.version was bumped for this change (at least
	 * 0.22.0), following the established "at least" pattern.
	 *
	 * @return void
	 */
	public function testRegisterVersionBumped(): void {
		self::assertTrue(
			version_compare($this->config['info']['version'], '0.22.0', '>='),
			'info.version MUST be at least 0.22.0 (trend-and-export-reporting\'s own bump) — got ' . $this->config['info']['version']
		);

	}//end testRegisterVersionBumped()
}//end class
