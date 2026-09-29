<?php

/**
 * Unit tests for the Enrolment statutory-fields register-JSON declarations
 * (inschrijving date/volgnummer/vestiging, uitschrijving destination school,
 * per-pupil leerjaar) and the seed fixtures exercising a combination group.
 *
 * IMPORTANT SCOPE NOTE: this register is read by OpenRegister core, which
 * does not live in this repository. What this suite verifies is the
 * declared SHAPE, mirroring GroepsplanRegisterTest/SchoolAndLocationRegisterTest.
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
 * @spec openspec/changes/archive/2026-09-28-enrolment-statutory-fields/tasks.md#task-1-add-inschrijvinguitschrijvingleerjaar-properties-to-enrolment
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Enrolment statutory-fields addition and its seed fixtures.
 */
class EnrolmentStatutoryFieldsRegisterTest extends TestCase {

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
	 * The three inschrijving properties are additive, nullable, and none is
	 * added to the existing `required` list.
	 *
	 * @return void
	 */
	public function testInschrijvingPropertiesAreAdditiveAndNullable(): void {
		$schema = $this->config['components']['schemas']['Enrolment'];

		$inschrijvingDate = $schema['properties']['inschrijvingDate'];
		self::assertSame('date', $inschrijvingDate['format']);
		self::assertTrue($inschrijvingDate['nullable']);
		self::assertNull($inschrijvingDate['default']);

		$volgnummer = $schema['properties']['volgnummer'];
		self::assertSame('integer', $volgnummer['type']);
		self::assertTrue($volgnummer['nullable']);

		$locationId = $schema['properties']['locationId'];
		self::assertSame('Vestiging', $locationId['$ref']);
		self::assertTrue($locationId['nullable']);

		// Purely additive: the existing required list is untouched.
		self::assertSame(['learnerId', 'courseId', 'tenant_id', 'source'], $schema['required']);

	}//end testInschrijvingPropertiesAreAdditiveAndNullable()

	/**
	 * `destinationSchoolId` references School, is nullable, and is additive
	 * alongside the existing free-text `reason` field.
	 *
	 * @return void
	 */
	public function testDestinationSchoolIdIsAdditiveAlongsideReason(): void {
		$schema = $this->config['components']['schemas']['Enrolment'];

		$destination = $schema['properties']['destinationSchoolId'];
		self::assertSame('School', $destination['$ref']);
		self::assertTrue($destination['nullable']);
		self::assertNull($destination['default']);

		self::assertArrayHasKey('reason', $schema['properties']);

	}//end testDestinationSchoolIdIsAdditiveAlongsideReason()

	/**
	 * `leerjaar` is a bounded integer (1 to 8), covering both PO and VO,
	 * and is not derived from `Cohort.name`.
	 *
	 * @return void
	 */
	public function testLeerjaarIsABoundedIntegerNotParsedFromCohortName(): void {
		$schema = $this->config['components']['schemas']['Enrolment'];

		$leerjaar = $schema['properties']['leerjaar'];
		self::assertSame('integer', $leerjaar['type']);
		self::assertTrue($leerjaar['nullable']);
		self::assertSame(1, $leerjaar['minimum']);
		self::assertSame(8, $leerjaar['maximum']);

	}//end testLeerjaarIsABoundedIntegerNotParsedFromCohortName()

	/**
	 * The primary school example set puts pupils in leerjaar 5 and 6 on the
	 * same "Groep 5/6" Cohort, proving the per-pupil (not per-cohort-name)
	 * leerjaar shape, each with an inschrijving date and a volgnummer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-primary-school-set-is-one-consistent-school
	 */
	public function testSeedFixturesExerciseCombinationGroupLeerjaarSplit(): void {
		$cohortId = self::poObject(schema: 'cohort', field: 'name', value: 'Groep 5/6')['uuid'];

		$onThisCohort = array_values(array_filter(
			self::poObjects(schema: 'enrolment'),
			static fn (array $e): bool => $e['cohortId'] === $cohortId
		));
		self::assertGreaterThanOrEqual(2, count($onThisCohort));

		$leerjaren = array_values(array_unique(array_column($onThisCohort, 'leerjaar')));
		sort($leerjaren);
		self::assertSame([5, 6], $leerjaren);

		foreach ($onThisCohort as $enrolment) {
			self::assertNotNull($enrolment['inschrijvingDate']);
			self::assertIsInt($enrolment['volgnummer']);
		}

	}//end testSeedFixturesExerciseCombinationGroupLeerjaarSplit()

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
