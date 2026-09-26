<?php

/**
 * Unit tests for the `data-mapping-profile-presets` register-JSON declarations.
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
 * @spec openspec/changes/data-mapping-profile-presets/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the TimeEdit rostering-import preset and the four
 * ParnasSys/ESIS/Magister/SOMtoday migration-import presets.
 */
class DataMappingProfilePresetsRegisterTest extends TestCase {

	/**
	 * Decoded register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * All DataMappingProfile seed entries.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $seed;

	/**
	 * Load the register configuration once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);
		$this->seed = $this->config['components']['schemas']['DataMappingProfile']['x-openregister-seed'];

	}//end setUp()

	/**
	 * Find a seed entry by name, or null.
	 *
	 * @param string $name Seed's `name` field.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findSeed(string $name): ?array {
		foreach ($this->seed as $entry) {
			if ($entry['name'] === $name) {
				return $entry;
			}
		}

		return null;

	}//end findSeed()

	/**
	 * TimeEdit matches the existing Zermelo/Untis/Xedule rostering-import
	 * seed shape.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-mapping-profile-presets/specs/data-exchange/spec.md#scenario-timeedit-matches-the-existing-rostering-import-seed-shape
	 */
	public function testTimeEditMatchesExistingRosteringSeedShape(): void {
		$timeEdit = $this->findSeed('TimeEdit timetable import');
		$zermelo = $this->findSeed('Zermelo timetable import');

		self::assertNotNull($timeEdit);
		self::assertNotNull($zermelo);
		self::assertSame('timetable-import', $timeEdit['target']);
		self::assertSame('import', $timeEdit['direction']);
		self::assertSame('session', $timeEdit['sourceSchema']);

		$timeEditFields = array_column($timeEdit['fieldMappings'], 'scholiqField');
		$zermeloFields = array_column($zermelo['fieldMappings'], 'scholiqField');
		sort($timeEditFields);
		sort($zermeloFields);
		self::assertSame($zermeloFields, $timeEditFields);

	}//end testTimeEditMatchesExistingRosteringSeedShape()

	/**
	 * Each of the four migration sources ships its own preset, all
	 * target: migration-import, direction: import, sourceSchema:
	 * learner-profile, carrying eckId.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-mapping-profile-presets/specs/data-exchange/spec.md#scenario-each-migration-source-ships-its-own-preset-carrying-eck-id
	 */
	public function testEachMigrationSourceCarriesEckId(): void {
		$names = ['ParnasSys migration import', 'ESIS migration import', 'Magister migration import', 'SOMtoday migration import'];

		foreach ($names as $name) {
			$entry = $this->findSeed($name);
			self::assertNotNull($entry, "{$name} must exist");
			self::assertSame('migration-import', $entry['target']);
			self::assertSame('import', $entry['direction']);
			self::assertSame('learner-profile', $entry['sourceSchema']);

			$fields = array_column($entry['fieldMappings'], 'scholiqField');
			self::assertContains('eckId', $fields, "{$name} must map eckId");
		}

	}//end testEachMigrationSourceCarriesEckId()

	/**
	 * Every migration-import preset targets a distinct external schema.
	 *
	 * @return void
	 */
	public function testMigrationPresetsTargetDistinctSchemas(): void {
		$targetSchemas = [];
		foreach (['ParnasSys migration import', 'ESIS migration import', 'Magister migration import', 'SOMtoday migration import'] as $name) {
			$targetSchemas[] = $this->findSeed($name)['targetSchema'];
		}

		self::assertSame(
			['ParnasSys:Leerling', 'ESIS:Leerling', 'Magister:Leerling', 'Somtoday:Leerling'],
			$targetSchemas
		);
		self::assertCount(4, array_unique($targetSchemas));

	}//end testMigrationPresetsTargetDistinctSchemas()

	/**
	 * DataExchangeJob and DataMappingProfile target descriptions both name
	 * migration-import.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-mapping-profile-presets/specs/data-exchange/spec.md#scenario-dataexchangejobtarget-documents-the-migration-import-connection
	 */
	public function testDataExchangeJobTargetDescribesMigrationImport(): void {
		$jobTarget = $this->config['components']['schemas']['DataExchangeJob']['properties']['target']['description'];
		$profileTarget = $this->config['components']['schemas']['DataMappingProfile']['properties']['target']['description'];

		self::assertStringContainsString('migration-import', $jobTarget);
		self::assertStringContainsString('migration-import', $profileTarget);

	}//end testDataExchangeJobTargetDescribesMigrationImport()

	/**
	 * The existing Zermelo/Untis/Xedule seeds and all prior seeds are
	 * untouched — five new seeds appended, none replaced.
	 *
	 * @return void
	 */
	public function testExistingSeedsUnchangedAndFiveAdded(): void {
		self::assertNotNull($this->findSeed('Zermelo timetable import'));
		self::assertNotNull($this->findSeed('Untis timetable import'));
		self::assertNotNull($this->findSeed('Xedule timetable import'));
		self::assertNotNull($this->findSeed('BRON/ROD learner export'));
		self::assertNotNull($this->findSeed('OSO transfer dossier'));
		self::assertCount(12, $this->seed);

	}//end testExistingSeedsUnchangedAndFiveAdded()
}//end class
