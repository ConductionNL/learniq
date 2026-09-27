<?php

/**
 * Unit tests for the `CurriculumCoverage` register declaration
 * (curriculum-coverage-rollup).
 *
 * IMPORTANT SCOPE NOTE: this register is read by OpenRegister core, which
 * does not live in this repository. What this suite verifies is the
 * declared SHAPE, the access rules and the demo rows.
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
 * @spec openspec/changes/curriculum-coverage-rollup/tasks.md#task-1-declare-the-curriculumcoverage-schema-demo-rows-and-catalogue-keys
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the CurriculumCoverage schema and its demo rows.
 */
class CurriculumCoverageRegisterTest extends TestCase {

	/**
	 * Decoded CurriculumCoverage schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $schema;

	/**
	 * Load the schema once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path         = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$config       = json_decode((string) file_get_contents($path), true);
		$this->schema = $config['components']['schemas']['CurriculumCoverage'];

	}//end setUp()

	/**
	 * Read-only, no lifecycle, staff-only read, cascade writers, and a
	 * description that keeps coverage apart from attainment.
	 *
	 * @return void
	 */
	public function testCoverageSchemaIsReadOnlyStaffOnlyAndLifecycleFree(): void {
		self::assertSame('curriculum-coverage', $this->schema['slug']);
		self::assertTrue($this->schema['x-openregister']['readOnly']);
		self::assertArrayNotHasKey('x-openregister-lifecycle', $this->schema);
		self::assertArrayNotHasKey('lifecycle', $this->schema['properties']);
		self::assertNotTrue($this->schema['appendOnly'] ?? false);

		$auth = $this->schema['authorization'];
		self::assertSame(['instructors', 'team-leads', 'coordinators', 'administration-managers', 'compliance-officers'], $auth['read']);
		self::assertSame(['instructors', 'hr', 'compliance-officers', 'team-leads'], $auth['create']);
		self::assertSame(['instructors', 'hr', 'compliance-officers', 'team-leads'], $auth['update']);
		self::assertArrayNotHasKey('x-property-rbac', $this->schema);

		self::assertStringContainsString('not evidence of learning', $this->schema['description']);
		self::assertSame('OCA\\Learniq\\Listener\\CurriculumCoverageRollupHandler', $this->schema['x-openregister-triggers']['calculatedChange']['handler']);

	}//end testCoverageSchemaIsReadOnlyStaffOnlyAndLifecycleFree()

	/**
	 * The bucket key and the count fields exist with the declared types.
	 *
	 * @return void
	 */
	public function testBucketAndCountPropertiesAreDeclared(): void {
		$properties = $this->schema['properties'];

		self::assertSame(['frameworkId', 'subjectScope', 'tenant_id'], $this->schema['required']);
		self::assertSame('CompetencyFramework', $properties['frameworkId']['$ref']);
		self::assertTrue($properties['year']['nullable']);
		self::assertSame(['all', 'subject', 'none'], $properties['subjectScope']['enum']);
		self::assertSame('Course', $properties['subjectId']['$ref']);

		foreach (['goalCount', 'plannedCount', 'assessedCount', 'plannedNotAssessedCount', 'uncoveredCount'] as $count) {
			self::assertSame('integer', $properties[$count]['type'], $count);
		}

		foreach (['uncoveredIds', 'plannedNotAssessedIds'] as $list) {
			self::assertSame('Competency', $properties[$list]['items']['$ref'], $list);
		}

		self::assertSame(
			['competencyId', 'planned', 'assessed', 'plannedDepth', 'assessedDepth', 'plannedRefs', 'assessedRefs'],
			array_keys($properties['goals']['items']['properties'])
		);

	}//end testBucketAndCountPropertiesAreDeclared()

	/**
	 * The three demo rows show the three subject scopes of one year, with
	 * counts that add up. Found by slug, never by index.
	 *
	 * @return void
	 */
	public function testDemoRowsValidateTheShape(): void {
		$path = __DIR__ . '/../../../lib/Settings/learniq_mock_register.json';
		$mock = json_decode((string) file_get_contents($path), true);
		$rows = [];
		foreach ($mock['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') === 'CurriculumCoverage') {
				$rows[$object['@self']['slug']] = $object;
			}
		}

		self::assertGreaterThanOrEqual(3, count($rows));
		$all     = $rows['curriculum-coverage-groep-5-all'];
		$subject = $rows['curriculum-coverage-groep-5-rekenen'];
		$none    = $rows['curriculum-coverage-groep-5-none'];

		self::assertSame('all', $all['subjectScope']);
		self::assertSame('subject', $subject['subjectScope']);
		self::assertSame('none', $none['subjectScope']);
		self::assertSame($all['goalCount'], ($subject['goalCount'] + $none['goalCount']));
		self::assertSame($all['uncoveredIds'], $none['uncoveredIds']);
		self::assertCount($all['goalCount'], $all['goals']);

	}//end testDemoRowsValidateTheShape()
}//end class
