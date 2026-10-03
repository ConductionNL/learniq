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
 * @spec openspec/changes/archive/2026-09-28-staff-role-vocabulary-extension/tasks.md#task-3-register-shape-tests
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
	 * Every Staff row in the register seed and in every example set uses only
	 * allowed tags, and the secondary school example set's vwo decaan exercises
	 * the new function tags. The register carries no Staff seed rows since
	 * segment-example-datasets-po; the demo row lives in the example set.
	 *
	 * @return void
	 */
	public function testSeedRowsUseAllowedTags(): void {
		$schema = $this->config['components']['schemas']['Staff'];
		$enum   = $schema['properties']['roles']['items']['enum'];

		$rows = [];
		foreach (($schema['x-openregister-seed'] ?? []) as $row) {
			$rows['register ' . $row['ncUserId']] = $row;
		}

		$sets = glob(__DIR__ . '/../../../lib/Settings/profiles/*.json');
		self::assertNotEmpty($sets);
		foreach ($sets as $file) {
			$set = json_decode((string)file_get_contents($file), true);
			foreach (($set['x-openregister']['seedData']['objects']['staff'] ?? []) as $row) {
				$rows[basename($file) . ' ' . $row['slug']] = $row;
			}
		}

		foreach ($rows as $where => $row) {
			foreach ($row['roles'] as $role) {
				self::assertContains($role, $enum, "Staff row $where uses unknown tag '$role'.");
			}
		}

		$voStaff = $this->voStaff();
		$bySlug  = array_column($voStaff, null, 'slug');
		$decaan  = ($bySlug['vo-staff-033'] ?? null);
		self::assertNotNull($decaan, 'The secondary school example set has no vo-staff-033 row.');
		self::assertSame('vo-decaan-02', $decaan['ncUserId']);
		self::assertContains('career-counsellor', $decaan['roles']);
		self::assertContains('exam-secretary', $decaan['roles']);

		$tagged = array_filter(
			$voStaff,
			static fn (array $row): bool => array_intersect(self::FUNCTION_TAGS, $row['roles']) !== []
		);
		self::assertGreaterThanOrEqual(1, count($tagged), 'No Staff row in the secondary school example set uses a function tag.');

		self::assertTrue(version_compare($schema['version'], '0.2.0', '>='), 'Staff.version must be at least 0.2.0.');

	}//end testSeedRowsUseAllowedTags()

	/**
	 * The Staff objects of the secondary school example set.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function voStaff(): array {
		$path = __DIR__ . '/../../../lib/Settings/profiles/vo.json';
		$set  = json_decode((string)file_get_contents($path), true);

		return ($set['x-openregister']['seedData']['objects']['staff'] ?? []);

	}//end voStaff()

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
