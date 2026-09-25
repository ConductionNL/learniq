<?php

/**
 * Unit tests for the `School`/`Location` register-JSON declarations and the
 * additive `Cohort.locationId` relation.
 *
 * IMPORTANT SCOPE NOTE: this register is read by OpenRegister core, which
 * does not live in this repository. What this suite verifies is the
 * declared SHAPE — the right fields, patterns, defaults, and seed fixtures —
 * mirroring the established pattern in GroepsplanRegisterTest.
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
 * @spec openspec/changes/school-and-location-records/tasks.md#task-1-add-the-school-and-location-schemas
 * @spec openspec/changes/school-and-location-records/tasks.md#task-2-add-cohortlocationid
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the School/Location schema shape and the Cohort.locationId
 * addition.
 */
class SchoolAndLocationRegisterTest extends TestCase {

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
	 * `School` is a plain resource-metadata schema (no lifecycle, same
	 * `x-openregister` shape as `Room`) declaring a pattern-validated BRIN
	 * and a pedagogical-concept enum defaulting to `regular`.
	 *
	 * @return void
	 */
	public function testSchoolIsAPlainResourceMetadataSchema(): void {
		$schema = $this->config['components']['schemas']['School'];

		self::assertSame('school', $schema['slug']);
		self::assertArrayNotHasKey('x-openregister-lifecycle', $schema);
		self::assertTrue($schema['x-openregister']['active']);
		self::assertFalse($schema['x-openregister']['hardDelete']);

		self::assertSame(['brin', 'name', 'tenant_id'], $schema['required']);

		$brin = $schema['properties']['brin'];
		self::assertSame('^[0-9]{2}[A-Za-z0-9]{2}$', $brin['pattern']);

		$concept = $schema['properties']['pedagogicalConcept'];
		self::assertSame(
			['regular', 'montessori', 'dalton', 'jenaplan', 'freinet', 'vrijeschool', 'other'],
			$concept['enum']
		);
		self::assertSame('regular', $concept['default']);

	}//end testSchoolIsAPlainResourceMetadataSchema()

	/**
	 * `Vestiging` is the schema key/slug: "location" is already claimed by
	 * shillinq on the shared OpenRegister (gate-106 cross-app-schema-slug),
	 * so user-facing copy still says "Location" but the internal identifier
	 * moved. It declares `schoolId` ($ref School), a required
	 * `vestigingscode`, and an `onderwijslocatiecode` that is independent
	 * and nullable: a vestiging MAY have more than one onderwijslocatie.
	 *
	 * @return void
	 */
	public function testLocationDeclaresIndependentOnderwijslocatiecode(): void {
		$schema = $this->config['components']['schemas']['Vestiging'];

		self::assertSame('vestiging', $schema['slug']);
		self::assertArrayNotHasKey('x-openregister-lifecycle', $schema);
		self::assertSame(['schoolId', 'vestigingscode', 'name', 'tenant_id'], $schema['required']);

		$schoolId = $schema['properties']['schoolId'];
		self::assertSame('School', $schoolId['$ref']);

		$vestigingscode = $schema['properties']['vestigingscode'];
		self::assertArrayNotHasKey('nullable', $vestigingscode);

		$onderwijslocatiecode = $schema['properties']['onderwijslocatiecode'];
		self::assertTrue($onderwijslocatiecode['nullable']);
		self::assertNull($onderwijslocatiecode['default']);
		self::assertNotSame(
			$schema['properties']['vestigingscode']['description'],
			$onderwijslocatiecode['description']
		);

	}//end testLocationDeclaresIndependentOnderwijslocatiecode()

	/**
	 * `Cohort.locationId` is additive, nullable, a SINGLE `$ref Location`
	 * (never an array) — the data-model enforcement of DUO's one-groep-
	 * per-location rule — and the existing `required` list is untouched.
	 *
	 * @return void
	 */
	public function testCohortLocationIdIsAdditiveNullableSingleRef(): void {
		$cohort = $this->config['components']['schemas']['Cohort'];

		$locationId = $cohort['properties']['locationId'];
		self::assertSame('string', $locationId['type']);
		self::assertTrue($locationId['nullable']);
		self::assertSame('Vestiging', $locationId['$ref']);
		self::assertNull($locationId['default']);

		// Purely additive: the existing required list is untouched.
		self::assertSame(['name', 'period', 'academicYear', 'tenant_id'], $cohort['required']);

	}//end testCohortLocationIdIsAdditiveNullableSingleRef()

	/**
	 * Seed fixtures exercise the School→Location relation, the
	 * independent-onderwijslocatiecode scenario, and one Cohort seed
	 * backfilled with `locationId` pointing at a seeded Location.
	 *
	 * @return void
	 */
	public function testSeedFixturesExerciseRelationAndBackfill(): void {
		$schoolSeeds = $this->config['components']['schemas']['School']['x-openregister-seed'];
		self::assertCount(2, $schoolSeeds);

		$locationSeeds = $this->config['components']['schemas']['Vestiging']['x-openregister-seed'];
		self::assertCount(3, $locationSeeds);

		$withOnderwijslocatie = array_values(array_filter(
			$locationSeeds,
			static fn (array $l): bool => $l['onderwijslocatiecode'] !== null
		));
		self::assertCount(1, $withOnderwijslocatie);
		self::assertSame($locationSeeds[0]['schoolId'], $withOnderwijslocatie[0]['schoolId']);
		self::assertNotSame($locationSeeds[0]['vestigingscode'], $withOnderwijslocatie[0]['vestigingscode']);

		$cohortSeeds = $this->config['components']['schemas']['Cohort']['x-openregister-seed'];
		self::assertSame($locationSeeds[0]['id'], $cohortSeeds[0]['locationId']);

	}//end testSeedFixturesExerciseRelationAndBackfill()
}//end class
