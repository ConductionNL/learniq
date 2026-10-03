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
 * @spec openspec/changes/archive/2026-09-28-school-year-shape/tasks.md#task-1-add-reportperiodholidays-and-reportperiodstudydays
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
	 * The primary school example set's first report period carries a named
	 * holiday and a study day.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-primary-school-set-is-one-consistent-school
	 */
	public function testSeedFixtureCarriesHolidayAndStudyDay(): void {
		$period = self::poObject(schema: 'report-period', field: 'periodCode', value: '1');

		self::assertContains('Herfstvakantie', array_column($period['holidays'], 'name'));
		self::assertContains('2025-11-14', array_column($period['studyDays'], 'date'));

	}//end testSeedFixtureCarriesHolidayAndStudyDay()

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
