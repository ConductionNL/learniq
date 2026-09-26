<?php

/**
 * Unit tests for the `uwlr-eduv-basispoort-contract` register-JSON declarations.
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
 * @spec openspec/changes/uwlr-eduv-basispoort-contract/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the nine uwlr/edu-v/basispoort/entree-content DataMappingProfile
 * seeds and the DataExchangeJob/DataMappingProfile target catalogue
 * descriptions.
 */
class UwlrEduvBasispoortRegisterTest extends TestCase {

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
	 * The UWLR pupil and teacher exports both map eckId.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/uwlr-eduv-basispoort-contract/specs/data-exchange/spec.md#scenario-uwlr-pupil-and-teacher-exports-carry-eck-id
	 */
	public function testUwlrPupilAndTeacherExportsCarryEckId(): void {
		$pupil = $this->findSeed('UWLR pupil export');
		$teacher = $this->findSeed('UWLR teacher export');

		self::assertNotNull($pupil);
		self::assertNotNull($teacher);
		self::assertSame('uwlr', $pupil['target']);
		self::assertSame('export', $pupil['direction']);
		self::assertSame('learner-profile', $pupil['sourceSchema']);

		$pupilFields = array_column($pupil['fieldMappings'], 'scholiqField');
		$teacherFields = array_column($teacher['fieldMappings'], 'scholiqField');
		self::assertContains('eckId', $pupilFields);
		self::assertContains('eckId', $teacherFields);

	}//end testUwlrPupilAndTeacherExportsCarryEckId()

	/**
	 * The UWLR group export uses the cohort schema.
	 *
	 * @return void
	 */
	public function testUwlrGroupExportUsesCohort(): void {
		$group = $this->findSeed('UWLR group export');

		self::assertNotNull($group);
		self::assertSame('cohort', $group['sourceSchema']);

	}//end testUwlrGroupExportUsesCohort()

	/**
	 * The UWLR results-import seed deliberately reuses LvsResult.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/uwlr-eduv-basispoort-contract/specs/data-exchange/spec.md#scenario-the-uwlr-results-import-seed-reuses-lvsresult
	 */
	public function testUwlrResultsImportReusesLvsResult(): void {
		$results = $this->findSeed('UWLR results import (generic data services)');

		self::assertNotNull($results);
		self::assertSame('uwlr', $results['target']);
		self::assertSame('import', $results['direction']);
		self::assertSame('lvs-result', $results['sourceSchema']);

	}//end testUwlrResultsImportReusesLvsResult()

	/**
	 * Edu-V ships one export seed per qualified data service, each with its
	 * own distinct targetSchema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/uwlr-eduv-basispoort-contract/specs/data-exchange/spec.md#scenario-edu-v-ships-one-export-seed-per-qualified-data-service
	 */
	public function testEduVSeedsCoverThreeDataServices(): void {
		$deelnemers = $this->findSeed('Edu-V Onderwijsdeelnemers data service export');
		$groepen = $this->findSeed('Edu-V Onderwijsgroepen data service export');
		$medewerkers = $this->findSeed('Edu-V Onderwijsmedewerkers data service export');

		self::assertNotNull($deelnemers);
		self::assertNotNull($groepen);
		self::assertNotNull($medewerkers);

		$targetSchemas = [$deelnemers['targetSchema'], $groepen['targetSchema'], $medewerkers['targetSchema']];
		self::assertSame(
			['EduV:Onderwijsdeelnemers', 'EduV:Onderwijsgroepen', 'EduV:Onderwijsmedewerkers'],
			$targetSchemas
		);

		foreach ([$deelnemers, $groepen, $medewerkers] as $entry) {
			self::assertSame('edu-v', $entry['target']);
			self::assertSame('export', $entry['direction']);
		}

	}//end testEduVSeedsCoverThreeDataServices()

	/**
	 * Basispoort and Entree content seeds are both `direction: sync`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/uwlr-eduv-basispoort-contract/specs/data-exchange/spec.md#scenario-basispoort-and-entree-content-seeds-are-sync-not-one-way-export
	 */
	public function testBasispoortAndEntreeContentAreSync(): void {
		$basispoort = $this->findSeed('Basispoort SSO and pupil/group/staff export (PO)');
		$entreeContent = $this->findSeed('Entree content SSO hand-off (VO)');

		self::assertNotNull($basispoort);
		self::assertNotNull($entreeContent);
		self::assertSame('sync', $basispoort['direction']);
		self::assertSame('sync', $entreeContent['direction']);
		self::assertSame('basispoort', $basispoort['target']);
		self::assertSame('entree-content', $entreeContent['target']);

		$basispoortFields = array_column($basispoort['fieldMappings'], 'scholiqField');
		$entreeFields = array_column($entreeContent['fieldMappings'], 'scholiqField');
		self::assertContains('eckId', $basispoortFields);
		self::assertContains('eckId', $entreeFields);

	}//end testBasispoortAndEntreeContentAreSync()

	/**
	 * DataExchangeJob and DataMappingProfile target descriptions both name
	 * the four new connections.
	 *
	 * @return void
	 */
	public function testTargetDescriptionsNameNewConnections(): void {
		$jobTarget = $this->config['components']['schemas']['DataExchangeJob']['properties']['target']['description'];
		$profileTarget = $this->config['components']['schemas']['DataMappingProfile']['properties']['target']['description'];

		foreach (['uwlr', 'edu-v', 'basispoort', 'entree-content'] as $name) {
			self::assertStringContainsString($name, $jobTarget, "DataExchangeJob.target must name {$name}");
			self::assertStringContainsString($name, $profileTarget, "DataMappingProfile.target must name {$name}");
		}

	}//end testTargetDescriptionsNameNewConnections()

	/**
	 * The existing Zermelo/Untis/Xedule timetable-import seeds are untouched
	 * by this change (nine new seeds appended, none replaced).
	 *
	 * @return void
	 */
	public function testExistingTimetableImportSeedsUnchanged(): void {
		self::assertNotNull($this->findSeed('Zermelo timetable import'));
		self::assertNotNull($this->findSeed('Untis timetable import'));
		self::assertNotNull($this->findSeed('Xedule timetable import'));
		self::assertCount(16, $this->seed);

	}//end testExistingTimetableImportSeedsUnchanged()
}//end class
