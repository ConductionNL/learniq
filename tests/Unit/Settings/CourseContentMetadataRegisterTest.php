<?php

/**
 * Unit tests for the NL-LOM aligned sharing metadata on Course and Lesson.
 *
 * The consent gate and the store read `license`, `author`, `subject` and
 * `educationalLevels`; these tests pin their shape on both schemas, the
 * labels and their Dutch catalogue values, and that no licence is chosen
 * on the school's behalf.
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
 * @spec openspec/changes/archive/2026-09-28-course-content-metadata/tasks.md#task-3-register-test
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the sharing metadata fields on Course and Lesson.
 */
class CourseContentMetadataRegisterTest extends TestCase {

	private const LICENSES = [
		'CC0-1.0',
		'CC-BY-4.0',
		'CC-BY-SA-4.0',
		'CC-BY-NC-4.0',
		'CC-BY-NC-SA-4.0',
		'CC-BY-ND-4.0',
		'CC-BY-NC-ND-4.0',
		'all-rights-reserved',
	];

	private const LEVELS = [
		'po',
		'so',
		'vmbo',
		'havo',
		'vwo',
		'mbo-1',
		'mbo-2',
		'mbo-3',
		'mbo-4',
		'hbo',
		'wo',
		'adult-education',
		'professional-training',
	];

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
		$path         = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);

	}//end setUp()

	/**
	 * Both schemas, keyed by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		return [
			'Course' => $this->config['components']['schemas']['Course'],
			'Lesson' => $this->config['components']['schemas']['Lesson'],
		];

	}//end schemas()

	/**
	 * Both schemas declare the four fields, none required, and the licence
	 * has no default.
	 *
	 * @return void
	 */
	public function testBothSchemasDeclareTheFourOptionalFields(): void {
		foreach ($this->schemas() as $name => $schema) {
			$properties = $schema['properties'];
			foreach (['license', 'author', 'subject', 'educationalLevels'] as $field) {
				self::assertArrayHasKey($field, $properties, "$name.$field is missing.");
				self::assertNotContains($field, ($schema['required'] ?? []), "$name.$field must stay optional.");
			}

			self::assertSame(self::LICENSES, $properties['license']['enum'], "$name.license enum");
			self::assertArrayNotHasKey('default', $properties['license'], "$name.license must not pick a licence.");
			self::assertSame('array', $properties['educationalLevels']['type']);
			self::assertSame(self::LEVELS, $properties['educationalLevels']['items']['enum'], "$name.educationalLevels enum");
			// A floor, not an exact value: goal-alignment-depth also raised both
			// schemas to 0.4.0, so after both landed they moved above it.
			self::assertTrue(version_compare($schema['version'], '0.4.0', '>='), "$name version " . $schema['version'] . ' is at least 0.4.0');
		}

	}//end testBothSchemasDeclareTheFourOptionalFields()

	/**
	 * Every licence and level value has a label with an English key and a
	 * Dutch value in the catalogue, and so has every new title.
	 *
	 * @return void
	 */
	public function testLabelsAndTitlesAreTranslated(): void {
		$en = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/en.json'), true)['translations'];
		$nl = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true)['translations'];

		foreach ($this->schemas() as $name => $schema) {
			$properties = $schema['properties'];
			$labels     = [
				...array_values($properties['license']['x-enum-labels']),
				...array_values($properties['educationalLevels']['items']['x-enum-labels']),
			];
			self::assertSame(self::LICENSES, array_keys($properties['license']['x-enum-labels']), "$name licence labels");
			self::assertSame(self::LEVELS, array_keys($properties['educationalLevels']['items']['x-enum-labels']), "$name level labels");

			foreach (['license', 'author', 'subject', 'educationalLevels'] as $field) {
				$labels[] = $properties[$field]['title'];
				$labels[] = $properties[$field]['description'];
			}

			foreach ($labels as $label) {
				self::assertArrayHasKey($label, $en, "'$label' has no l10n/en.json key.");
				self::assertArrayHasKey($label, $nl, "'$label' has no l10n/nl.json value.");
			}
		}

		self::assertSame('Alle rechten voorbehouden', $nl['All rights reserved']);
		self::assertSame('Licentie', $nl['Licence']);

	}//end testLabelsAndTitlesAreTranslated()

	/**
	 * The lesson licence says it falls back to the course licence.
	 *
	 * @return void
	 */
	public function testLessonLicenceFallsBackToTheCourse(): void {
		$description = $this->config['components']['schemas']['Lesson']['properties']['license']['description'];

		self::assertStringContainsString('Leave empty to use the course licence', $description);

	}//end testLessonLicenceFallsBackToTheCourse()
}//end class
