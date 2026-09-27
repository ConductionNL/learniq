<?php

/**
 * Unit tests for the `Cohort.notes` register-JSON declaration.
 *
 * IMPORTANT SCOPE NOTE: this register is read by OpenRegister core, which
 * does not live in this repository. What this suite verifies is the
 * declared SHAPE, mirroring SchoolAndLocationRegisterTest.
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
 * @spec openspec/changes/cohort-group-page-polish/tasks.md#task-1-add-cohortnotes
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Cohort.notes addition and its seed fixture.
 */
class CohortGroupPagePolishRegisterTest extends TestCase {

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
	 * `Cohort.notes` is additive, nullable, and does not change the
	 * existing `required` list.
	 *
	 * @return void
	 */
	public function testCohortNotesIsAdditiveAndNullable(): void {
		$cohort = $this->config['components']['schemas']['Cohort'];

		$notes = $cohort['properties']['notes'];
		self::assertSame('string', $notes['type']);
		self::assertTrue($notes['nullable']);
		self::assertNull($notes['default']);

		self::assertSame(['name', 'period', 'academicYear', 'tenant_id'], $cohort['required']);

	}//end testCohortNotesIsAdditiveAndNullable()

	/**
	 * The seed fixture ("Groep 7") carries a non-null notes value.
	 *
	 * @return void
	 */
	public function testSeedFixtureCarriesNotes(): void {
		$seeds = $this->config['components']['schemas']['Cohort']['x-openregister-seed'];
		self::assertSame('Groep 7', $seeds[0]['name']);
		self::assertNotNull($seeds[0]['notes']);
		self::assertNotSame('', $seeds[0]['notes']);

	}//end testSeedFixtureCarriesNotes()
}//end class
