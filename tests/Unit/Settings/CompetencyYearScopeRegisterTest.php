<?php

/**
 * Unit tests for the additive `Competency.applicableYears` and
 * `Competency.subjectId` register declarations.
 *
 * IMPORTANT SCOPE NOTE: this register is read by OpenRegister core, which
 * does not live in this repository. What this suite verifies is the
 * declared SHAPE, and that the shape matches the contract lane r2-slo
 * (integriq slo-kerndoelen-import) builds against.
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
 * @spec openspec/changes/competency-year-scope/tasks.md#task-1-add-applicableyears-and-subjectid-to-competency
 * @spec openspec/changes/competency-year-scope/tasks.md#task-2-demo-data-and-translation-keys
 * @spec openspec/changes/competency-year-scope/tasks.md#task-3-register-unit-test
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Competency year and subject properties, their back-compat
 * defaults, and the demo rows that carry them.
 */
class CompetencyYearScopeRegisterTest extends TestCase {

	/**
	 * Decoded `Competency` schema from the register.
	 *
	 * @var array<string, mixed>
	 */
	private array $schema;

	/**
	 * Load the Competency schema once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path         = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$config       = json_decode((string) file_get_contents($path), true);
		$this->schema = $config['components']['schemas']['Competency'];

	}//end setUp()

	/**
	 * `applicableYears` is an optional array of free labels: no enum, unique
	 * items, bounded length, empty by default.
	 *
	 * @return void
	 */
	public function testApplicableYearsIsAnOptionalFreeLabelArray(): void {
		$property = $this->schema['properties']['applicableYears'];

		self::assertSame('array', $property['type']);
		self::assertSame([], $property['default']);
		self::assertTrue($property['uniqueItems']);
		self::assertSame('string', $property['items']['type']);
		self::assertSame(1, $property['items']['minLength']);
		self::assertSame(64, $property['items']['maxLength']);
		self::assertArrayNotHasKey('enum', $property['items']);
		self::assertArrayNotHasKey('enum', $property);

	}//end testApplicableYearsIsAnOptionalFreeLabelArray()

	/**
	 * `subjectId` is a nullable UUID reference to Course, the schema that
	 * stands for a subject in learniq.
	 *
	 * @return void
	 */
	public function testSubjectIdReferencesCourse(): void {
		$property = $this->schema['properties']['subjectId'];

		self::assertSame('string', $property['type']);
		self::assertTrue($property['nullable']);
		self::assertNull($property['default']);
		self::assertSame('uuid', $property['format']);
		self::assertSame('Course', $property['$ref']);

	}//end testSubjectIdReferencesCourse()

	/**
	 * Both properties are additive: the required list and the existing
	 * properties are untouched, and the schema version moved to 0.2.0.
	 *
	 * @return void
	 */
	public function testNewPropertiesAreAdditiveAndOptional(): void {
		self::assertSame(['frameworkId', 'code', 'title', 'tenant_id'], $this->schema['required']);
		self::assertNotContains('applicableYears', $this->schema['required']);
		self::assertNotContains('subjectId', $this->schema['required']);
		self::assertSame('0.2.0', $this->schema['version']);

		foreach (['frameworkId', 'parentId', 'code', 'title', 'description', 'order', 'requiredForRoles', 'lifecycle'] as $existing) {
			self::assertArrayHasKey($existing, $this->schema['properties']);
		}

	}//end testNewPropertiesAreAdditiveAndOptional()

	/**
	 * The engineering notes carry the label scheme and the read rule, so the
	 * importer and the coverage rollup read one source.
	 *
	 * @return void
	 */
	public function testDescriptionsStateTheInheritanceRule(): void {
		$years   = $this->schema['properties']['applicableYears'];
		$subject = $this->schema['properties']['subjectId'];

		self::assertStringContainsString('groep 5', $years['description']);
		self::assertStringContainsString('2026-2027', $years['description']);
		self::assertStringContainsString('parent goal', $years['description']);
		self::assertStringContainsString('nearest ancestor', $years['x-notes']);
		self::assertStringContainsString('nearest ancestor', $subject['x-notes']);

	}//end testDescriptionsStateTheInheritanceRule()

	/**
	 * The demo rows show each state: year levels, an academic year, and the
	 * framework-wide default. Found by slug, never by index.
	 *
	 * @return void
	 */
	public function testDemoRowsCarryTheNewFields(): void {
		$path  = __DIR__ . '/../../../lib/Settings/learniq_mock_register.json';
		$mock  = json_decode((string) file_get_contents($path), true);
		$rows  = [];
		foreach ($mock['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') === 'Competency') {
				$rows[$object['@self']['slug']] = $object;
			}
		}

		self::assertGreaterThanOrEqual(3, count($rows));
		self::assertSame(['groep 5', 'groep 6'], $rows['competency-voorbeeld-title-1-1']['applicableYears']);
		self::assertSame(['2026-2027'], $rows['competency-voorbeeld-title-2-2']['applicableYears']);
		self::assertNull($rows['competency-voorbeeld-title-2-2']['subjectId']);
		self::assertSame([], $rows['competency-voorbeeld-title-3-3']['applicableYears']);

	}//end testDemoRowsCarryTheNewFields()
}//end class
