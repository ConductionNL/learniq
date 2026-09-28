<?php

/**
 * Unit tests for the `competencyAlignments` register declarations on
 * Lesson, Course, Assignment and Assessment (goal-alignment-depth).
 *
 * IMPORTANT SCOPE NOTE: this register is read by OpenRegister core, which
 * does not live in this repository. What this suite verifies is the
 * declared SHAPE and the demo rows; the save-time rules live in
 * CompetencyAlignmentListenerTest.
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
 * @spec openspec/changes/archive/2026-09-28-goal-alignment-depth/tasks.md#task-1-declare-competencyalignments-on-the-four-schemas
 * @spec openspec/changes/archive/2026-09-28-goal-alignment-depth/tasks.md#task-4-register-unit-test
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the alignment property on the four schemas and their demo rows.
 */
class GoalAlignmentDepthRegisterTest extends TestCase {

	/**
	 * Schema versions this change raised them to. A floor, not an exact value:
	 * a later change to the same schema raises its version again.
	 */
	private const VERSIONS = [
		'Lesson'     => '0.4.0',
		'Course'     => '0.4.0',
		'Assignment' => '0.4.0',
		'Assessment' => '0.3.0',
	];

	/**
	 * Decoded register schemas.
	 *
	 * @var array<string, mixed>
	 */
	private array $schemas;

	/**
	 * Load the register once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path          = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$config        = json_decode((string) file_get_contents($path), true);
		$this->schemas = $config['components']['schemas'];

	}//end setUp()

	/**
	 * Each of the four schemas declares the same alignment shape, with a
	 * nullable depth and no depth enum, next to an unchanged competencyIds.
	 *
	 * @return void
	 */
	public function testFourSchemasDeclareTheAlignmentShape(): void {
		foreach (self::VERSIONS as $name => $version) {
			$schema   = $this->schemas[$name];
			$property = $schema['properties']['competencyAlignments'];

			self::assertTrue(version_compare($schema['version'], $version, '>='), $name . ' version ' . $schema['version'] . ' is at least ' . $version);
			self::assertSame('array', $property['type'], $name);
			self::assertSame([], $property['default'], $name);
			self::assertSame('json', $property['widget'], $name);
			self::assertSame(['competencyId'], $property['items']['required'], $name);

			$goal = $property['items']['properties']['competencyId'];
			self::assertSame('uuid', $goal['format'], $name);
			self::assertSame('Competency', $goal['$ref'], $name);

			$depth = $property['items']['properties']['depth'];
			self::assertTrue($depth['nullable'], $name);
			self::assertSame(64, $depth['maxLength'], $name);
			self::assertArrayNotHasKey('enum', $depth, $name);

			self::assertSame('Competency', $schema['properties']['competencyIds']['items']['$ref'], $name);
			self::assertNotContains('competencyAlignments', $schema['required'] ?? [], $name);
		}

	}//end testFourSchemasDeclareTheAlignmentShape()

	/**
	 * Item stays authoring metadata: no alignments.
	 *
	 * @return void
	 */
	public function testItemGainsNoAlignments(): void {
		self::assertArrayNotHasKey('competencyAlignments', $this->schemas['Item']['properties']);
		self::assertArrayHasKey('competencyIds', $this->schemas['Item']['properties']);

	}//end testItemGainsNoAlignments()

	/**
	 * Each schema's first demo row carries a depth and an open depth, and
	 * its flat list equals the alignments' goals.
	 *
	 * @return void
	 */
	public function testDemoRowsCarryConsistentAlignments(): void {
		$path  = __DIR__ . '/../../../lib/Settings/learniq_mock_register.json';
		$mock  = json_decode((string) file_get_contents($path), true);
		$found = [];
		foreach ($mock['components']['objects'] as $object) {
			$schema = ($object['@self']['schema'] ?? '');
			if (isset(self::VERSIONS[$schema]) === false || empty($object['competencyAlignments']) === true) {
				continue;
			}

			$found[$schema] = true;
			$ids            = array_column($object['competencyAlignments'], 'competencyId');
			self::assertSame($ids, $object['competencyIds'], $schema);
			self::assertContains(null, array_column($object['competencyAlignments'], 'depth'), $schema);
		}

		self::assertSame(array_keys(self::VERSIONS), array_keys(array_intersect_key(self::VERSIONS, $found)));

	}//end testDemoRowsCarryConsistentAlignments()
}//end class
