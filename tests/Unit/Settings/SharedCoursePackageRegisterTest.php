<?php

/**
 * Unit tests for the SharedCoursePackage schema: what a learniq instance that
 * acts as the course registry stores.
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
 * @spec openspec/changes/archive/2026-09-28-lesson-sharing-via-store-plane/tasks.md#task-1-store-plane-descriptor-registry-object-and-sharedcoursepackage-schema
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CourseStore\CourseStoreDescriptor;
use OCA\Learniq\Service\CourseStore\CourseStoreRegistryObject;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the SharedCoursePackage schema against what the store reads and writes.
 */
class SharedCoursePackageRegisterTest extends TestCase {

	/**
	 * The schema definition.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		return $register['components']['schemas']['SharedCoursePackage'];
	}//end schema()

	/**
	 * The slug is the descriptor's schema, and every property the publisher
	 * writes is declared, card fields as strings.
	 *
	 * @return void
	 */
	public function testTheSchemaHoldsWhatThePublisherWrites(): void {
		$schema = $this->schema();
		$object = (new CourseStoreRegistryObject())->build(['sharing' => ['title' => 'Betoog']]);

		self::assertSame(CourseStoreDescriptor::SCHEMA, $schema['slug']);
		foreach (array_keys($object) as $property) {
			self::assertArrayHasKey($property, $schema['properties'], "$property is written but not declared.");
		}

		foreach (CourseStoreDescriptor::CARD_FIELDS as $property) {
			self::assertSame('string', $schema['properties'][$property]['type'], "$property must be a string.");
		}

		// The publisher cannot know the registry's tenant, so nothing it
		// does not write may be required, or the registry refuses the POST.
		foreach ($schema['required'] as $field) {
			self::assertArrayHasKey($field, $object, "$field is required but the publisher does not write it.");
		}

		self::assertSame('object', $schema['properties']['package']['type']);
		self::assertSame(['course-package'], $schema['properties']['kind']['enum']);
	}//end testTheSchemaHoldsWhatThePublisherWrites()

	/**
	 * Any account reads, the authoring groups publish, administration managers moderate.
	 *
	 * @return void
	 */
	public function testAccessOnTheRegistry(): void {
		self::assertSame(
			[
				'read'   => ['authenticated'],
				'create' => ['instructors', 'team-leads', 'coordinators', 'administration-managers'],
				'update' => ['administration-managers'],
			],
			$this->schema()['authorization']
		);
	}//end testAccessOnTheRegistry()

	/**
	 * The seed row is complete and its package is importable learniq JSON.
	 *
	 * @return void
	 */
	public function testTheSeedRowIsAnImportablePackage(): void {
		$schema = $this->schema();
		// Found by id, not by position: another change may seed this schema too.
		$seed = $this->seedById(schema: $schema, id: '00000000-0000-0000-0000-0000000f0201');

		foreach ($schema['required'] as $field) {
			self::assertArrayHasKey($field, $seed);
		}

		self::assertTrue((new CourseStoreDescriptor($this->createMock(ActionAuthService::class)))->isCourseSlug($seed['slug']));
		self::assertArrayHasKey('course', $seed['package']);
		self::assertSame('CC-BY-SA-4.0', $seed['package']['course']['license']);
	}//end testTheSeedRowIsAnImportablePackage()

	/**
	 * The seed row with the given id, failing the test when there is none.
	 *
	 * @param array<string, mixed> $schema The schema.
	 * @param string $id The seed row's id.
	 *
	 * @return array<string, mixed>
	 */
	private function seedById(array $schema, string $id): array {
		foreach (($schema['x-openregister-seed'] ?? []) as $seed) {
			if (($seed['id'] ?? null) === $id) {
				return $seed;
			}
		}

		self::fail('No seed row with id ' . $id);
	}//end seedById()
}//end class
