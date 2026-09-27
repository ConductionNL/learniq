<?php

/**
 * Unit tests for the extended `Staff.roles` vocabulary.
 *
 * `Staff.roles` names the functions a person holds (decaan, examensecretaris,
 * vertrouwenspersoon). It is descriptive metadata: access comes from group
 * membership, never from a tag (decision D18). This suite pins the enum floor
 * and order, the label map, and the rule that no authorization block or
 * manifest gate reads a tag-only value.
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
 * @spec openspec/changes/staff-role-vocabulary-extension/tasks.md#task-3-register-shape-tests
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Staff role tags, their labels, and that a tag grants no access.
 */
class StaffRoleVocabularyRegisterTest extends TestCase {

	/**
	 * The values `Staff.roles` shipped with in #929, in their original order.
	 */
	private const ORIGINAL_TAGS = [
		'teacher',
		'mentor',
		'coordinator',
		'teaching-assistant',
		'support-staff',
		'administrator',
		'other',
	];

	/**
	 * The function tags this change adds.
	 */
	private const FUNCTION_TAGS = [
		'career-counsellor',
		'study-adviser',
		'remedial-teacher',
		'care-coordinator',
		'exam-secretary',
		'placement-coordinator',
		'confidential-counsellor',
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
	 * The `Staff.roles` items definition.
	 *
	 * @return array<string, mixed>
	 */
	private function roleItems(): array {
		return $this->config['components']['schemas']['Staff']['properties']['roles']['items'];

	}//end roleItems()

	/**
	 * The original seven tags keep their position; the function tags follow.
	 *
	 * @return void
	 */
	public function testOriginalTagsKeepTheirPositionAndFunctionTagsFollow(): void {
		$enum = $this->roleItems()['enum'];

		self::assertSame(self::ORIGINAL_TAGS, array_slice($enum, 0, count(self::ORIGINAL_TAGS)));
		foreach (self::FUNCTION_TAGS as $tag) {
			self::assertContains($tag, $enum, "Staff.roles is missing the '$tag' tag.");
		}

		self::assertSame(count($enum), count(array_unique($enum)), 'Staff.roles lists a value twice.');

	}//end testOriginalTagsKeepTheirPositionAndFunctionTagsFollow()

	/**
	 * Every enum value has exactly one non-empty label, and every label is a
	 * catalogue key with a Dutch value.
	 *
	 * @return void
	 */
	public function testEveryTagHasATranslatedLabel(): void {
		$items  = $this->roleItems();
		$labels = $items['x-enum-labels'];

		self::assertSame($items['enum'], array_keys($labels));

		$en = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/en.json'), true)['translations'];
		$nl = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true)['translations'];

		foreach ($labels as $value => $label) {
			self::assertNotSame('', trim($label), "Staff.roles '$value' has an empty label.");
			self::assertArrayHasKey($label, $en, "Label '$label' has no l10n/en.json key.");
			self::assertArrayHasKey($label, $nl, "Label '$label' has no l10n/nl.json value.");
		}

		self::assertSame('Examensecretaris', $nl['Exam secretary']);
		self::assertSame('Vertrouwenspersoon', $nl['Confidential counsellor']);

	}//end testEveryTagHasATranslatedLabel()

	/**
	 * The property description says a tag grants no access.
	 *
	 * @return void
	 */
	public function testDescriptionSaysATagGrantsNoAccess(): void {
		$description = $this->config['components']['schemas']['Staff']['properties']['roles']['description'];

		self::assertStringContainsString('grants no access', $description);

	}//end testDescriptionSaysATagGrantsNoAccess()

	/**
	 * No schema authorization block names a function tag, so a tag can never
	 * turn into a permission by accident.
	 *
	 * @return void
	 */
	public function testNoAuthorizationBlockNamesAFunctionTag(): void {
		foreach ($this->config['components']['schemas'] as $name => $schema) {
			$strings = $this->collectStrings(($schema['authorization'] ?? []));
			$leaked  = array_values(array_intersect(self::FUNCTION_TAGS, $strings));
			self::assertSame([], $leaked, "$name.authorization names a Staff role tag.");
		}

	}//end testNoAuthorizationBlockNamesAFunctionTag()

	/**
	 * No manifest `visibleIf` names a function tag.
	 *
	 * @return void
	 */
	public function testNoManifestGateNamesAFunctionTag(): void {
		$files = [__DIR__ . '/../../../src/manifest.json', ...glob(__DIR__ . '/../../../src/manifest.d/*.json')];
		self::assertGreaterThan(1, count($files));

		foreach ($files as $file) {
			$manifest = json_decode((string)file_get_contents($file), true);
			$gates    = $this->collectVisibleIf($manifest);
			$leaked   = array_values(array_intersect(self::FUNCTION_TAGS, $this->collectStrings($gates)));
			self::assertSame([], $leaked, basename($file) . ' gates a menu on a Staff role tag.');
		}

	}//end testNoManifestGateNamesAFunctionTag()

	/**
	 * Every seed row uses only allowed tags, and one seed row exercises the
	 * new function tags.
	 *
	 * @return void
	 */
	public function testSeedRowsUseAllowedTags(): void {
		$schema = $this->config['components']['schemas']['Staff'];
		$enum   = $schema['properties']['roles']['items']['enum'];
		$seen   = [];

		foreach ($schema['x-openregister-seed'] as $row) {
			foreach ($row['roles'] as $role) {
				self::assertContains($role, $enum, "Seed row {$row['ncUserId']} uses unknown tag '$role'.");
				$seen[] = $role;
			}
		}

		self::assertNotSame([], array_intersect(self::FUNCTION_TAGS, $seen), 'No Staff seed row uses a function tag.');
		self::assertSame('0.2.0', $schema['version']);

	}//end testSeedRowsUseAllowedTags()

	/**
	 * Collect every `visibleIf` value in a manifest tree.
	 *
	 * @param mixed $node Manifest node.
	 *
	 * @return array<int, mixed>
	 */
	private function collectVisibleIf(mixed $node): array {
		if (is_array($node) === false) {
			return [];
		}

		$found = [];
		foreach ($node as $key => $value) {
			if ($key === 'visibleIf') {
				$found[] = $value;
				continue;
			}

			$found = [...$found, ...$this->collectVisibleIf($value)];
		}

		return $found;

	}//end collectVisibleIf()

	/**
	 * Collect every string leaf in a tree.
	 *
	 * @param mixed $node Tree node.
	 *
	 * @return array<int, string>
	 */
	private function collectStrings(mixed $node): array {
		if (is_string($node) === true) {
			return [$node];
		}

		if (is_array($node) === false) {
			return [];
		}

		$found = [];
		foreach ($node as $value) {
			$found = [...$found, ...$this->collectStrings($value)];
		}

		return $found;

	}//end collectStrings()
}//end class
