<?php

/**
 * Unit tests for the `ReportPeriod.holidays`/`.studyDays` register-JSON
 * declarations.
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
 * @spec openspec/changes/school-year-shape/tasks.md#task-1-add-reportperiodholidays-and-reportperiodstudydays
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the ReportPeriod holidays/studyDays addition and its seed
 * fixture.
 */
class SchoolYearShapeRegisterTest extends TestCase {

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
	 * `holidays`/`studyDays` are additive arrays, default empty, and do not
	 * change ReportPeriod's existing `required` list.
	 *
	 * @return void
	 */
	public function testHolidaysAndStudyDaysAreAdditiveArrays(): void {
		$schema = $this->config['components']['schemas']['ReportPeriod'];

		$holidays = $schema['properties']['holidays'];
		self::assertSame('array', $holidays['type']);
		self::assertSame([], $holidays['default']);
		self::assertSame(['name', 'startDate', 'endDate'], $holidays['items']['required']);

		$studyDays = $schema['properties']['studyDays'];
		self::assertSame('array', $studyDays['type']);
		self::assertSame([], $studyDays['default']);
		self::assertSame(['date'], $studyDays['items']['required']);

		self::assertSame(
			['name', 'academicYear', 'periodCode', 'startDate', 'endDate', 'curriculumPlanIds', 'cohortIds', 'tenant_id'],
			$schema['required']
		);

	}//end testHolidaysAndStudyDaysAreAdditiveArrays()

	/**
	 * The seed fixture carries a named holiday and a study day.
	 *
	 * @return void
	 */
	public function testSeedFixtureCarriesHolidayAndStudyDay(): void {
		$seed = $this->config['components']['schemas']['ReportPeriod']['x-openregister-seed'][0];

		self::assertNotEmpty($seed['holidays']);
		self::assertSame('Herfstvakantie', $seed['holidays'][0]['name']);

		self::assertNotEmpty($seed['studyDays']);
		self::assertSame('2025-11-14', $seed['studyDays'][0]['date']);

	}//end testSeedFixtureCarriesHolidayAndStudyDay()
}//end class
