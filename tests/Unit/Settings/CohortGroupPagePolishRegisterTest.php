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
 * @spec openspec/changes/archive/2026-09-28-cohort-group-page-polish/tasks.md#task-1-add-cohortnotes
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
	 * The primary school example set's "Groep 7" carries a non-empty notes value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-primary-school-set-is-one-consistent-school
	 */
	public function testSeedFixtureCarriesNotes(): void {
		$cohort = self::poObject(schema: 'cohort', field: 'name', value: 'Groep 7');
		self::assertNotNull($cohort['notes']);
		self::assertNotSame('', $cohort['notes']);

	}//end testSeedFixtureCarriesNotes()

	/**
	 * The objects of one schema in the primary school example set, where the
	 * curated primary school seeds moved to (segment-example-datasets-po).
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function poObjects(string $schema): array {
		$path = __DIR__ . '/../../../lib/Settings/profiles/po.json';
		$set  = json_decode((string)file_get_contents($path), true);

		return ($set['x-openregister']['seedData']['objects'][$schema] ?? []);
	}//end poObjects()

	/**
	 * The first object of a schema in the example set whose field equals a value.
	 *
	 * @param string $schema The schema slug.
	 * @param string $field  The field to match.
	 * @param mixed  $value  The value it must hold.
	 *
	 * @return array<string, mixed>
	 */
	private static function poObject(string $schema, string $field, mixed $value): array {
		foreach (self::poObjects(schema: $schema) as $object) {
			if (($object[$field] ?? null) === $value) {
				return $object;
			}
		}

		self::fail('No ' . $schema . ' with ' . $field . ' = ' . json_encode($value) . ' in the primary school example set.');
	}//end poObject()
}//end class
