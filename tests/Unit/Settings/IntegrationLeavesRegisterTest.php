<?php

/**
 * Register and manifest tests for leaf-integrations.
 *
 * A leaf is declared in two places only: a `linkedTypes` entry on the schema
 * and an integration widget on a manifest page. The change's static
 * scenarios (the surface is enumerable, catalogue definitions carry no leaf,
 * learniq declares no polls leaf) were checked once by a grep in the task
 * acceptance criteria. This test keeps them checked, so a leaf added,
 * dropped or moved without a spec change fails here.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-leaves/spec.md#requirement-leaves-are-declared-not-coded-req-001
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Pins the leaf surface: which schema declares which leaf, and which page draws it.
 */
class IntegrationLeavesRegisterTest extends TestCase {

	/**
	 * Every schema that declares `linkedTypes`, and exactly what it declares.
	 *
	 * `talk` on Cohort and Session predates this change (talk-classroom-spaces).
	 */
	private const LINKED_TYPES = [
		'Assignment'       => ['calendar', 'forms'],
		'BpvPlacement'     => ['deck'],
		'Cohort'           => ['talk'],
		'Credential'       => ['calendar'],
		'LearnerProfile'   => ['contacts'],
		'Praktijkopleider' => ['contacts'],
		'Session'          => ['talk', 'calendar'],
	];

	/**
	 * Every page's leaf widgets other than `files`, by integration id.
	 */
	private const PAGE_LEAVES = [
		'AssignmentDetail'       => ['calendar', 'forms'],
		'BpvPlacementDetail'     => ['deck'],
		'CohortDetail'           => ['talk'],
		'CredentialDetail'       => ['calendar'],
		'LearnerProfileDetail'   => ['contacts'],
		'PraktijkopleiderDetail' => ['contacts'],
		'SessionDetail'          => ['talk', 'calendar'],
	];

	/**
	 * Catalogue definitions: no leaf beyond `files` (decision 3).
	 */
	private const CATALOGUE = ['Course', 'Programme', 'CurriculumPlan', 'CourseTemplate', 'Regulation'];

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The shipped schemas, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		$register = json_decode((string)file_get_contents(self::root() . '/lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas'];
	}//end schemas()

	/**
	 * Every manifest page across the fragments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function pages(): array {
		$pages = [];
		foreach (glob(self::root() . '/src/manifest.d/*.json') as $fragment) {
			$manifest = json_decode((string)file_get_contents($fragment), true);
			foreach (($manifest['pages'] ?? []) as $page) {
				$pages[] = $page;
			}
		}

		return $pages;
	}//end pages()

	/**
	 * A page's integration widgets.
	 *
	 * @param array<string, mixed> $page The page.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function integrationWidgets(array $page): array {
		return array_values(
			array_filter(
				($page['config']['widgets'] ?? []),
				static fn (array $widget): bool => ($widget['type'] ?? null) === 'integration'
			)
		);
	}//end integrationWidgets()

	/**
	 * The schema name a page reads, whether the page names it by key or by slug.
	 *
	 * @param array<string, array<string, mixed>> $schemas The schemas.
	 * @param string                              $ref     The page's `config.schema`.
	 *
	 * @return string|null The schema name.
	 */
	private static function schemaName(array $schemas, string $ref): ?string {
		if (isset($schemas[$ref]) === true) {
			return $ref;
		}

		foreach ($schemas as $name => $schema) {
			if (($schema['slug'] ?? null) === $ref) {
				return $name;
			}
		}

		return null;
	}//end schemaName()

	/**
	 * The register declares exactly the agreed leaves, and no polls leaf.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-leaves/spec.md#requirement-learniq-declares-no-polls-leaf-req-006
	 */
	public function testTheRegisterDeclaresExactlyTheAgreedLeaves(): void {
		$declared = [];
		foreach (self::schemas() as $name => $schema) {
			if (array_key_exists('linkedTypes', $schema) === true) {
				$declared[$name] = $schema['linkedTypes'];
			}
		}

		ksort($declared);
		self::assertSame(self::LINKED_TYPES, $declared);

		foreach ($declared as $name => $types) {
			self::assertNotContains('polls', $types, "$name declares a polls leaf (decision D1).");
		}
	}//end testTheRegisterDeclaresExactlyTheAgreedLeaves()

	/**
	 * The manifest draws exactly the agreed leaves, each on a schema that declares it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-leaves/spec.md#requirement-calendar-leaves-on-session-assignment-and-credential-req-002
	 */
	public function testTheManifestDrawsExactlyTheAgreedLeaves(): void {
		$schemas = self::schemas();
		$drawn   = [];
		foreach (self::pages() as $page) {
			foreach (self::integrationWidgets(page: $page) as $widget) {
				$leaf = (string)($widget['integrationId'] ?? '');
				self::assertNotSame('polls', $leaf, $page['id'] . ' draws a polls widget (decision D1).');
				if ($leaf === 'files') {
					continue;
				}

				$drawn[$page['id']][] = $leaf;

				$schema = self::schemaName(schemas: $schemas, ref: (string)($page['config']['schema'] ?? ''));
				self::assertNotNull($schema, $page['id'] . ' names no known schema.');
				self::assertContains($leaf, ($schemas[$schema]['linkedTypes'] ?? []), $page['id'] . " draws a $leaf widget its schema $schema does not declare.");
			}
		}

		ksort($drawn);
		self::assertSame(self::PAGE_LEAVES, $drawn);
	}//end testTheManifestDrawsExactlyTheAgreedLeaves()

	/**
	 * Each leaf widget from this change names its app, so an absent app shows the set-up state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-leaves/spec.md#requirement-leaves-are-declared-not-coded-req-001
	 */
	public function testEveryNewLeafWidgetDeclaresItsRequiredApp(): void {
		foreach (self::pages() as $page) {
			foreach (self::integrationWidgets(page: $page) as $widget) {
				$leaf = (string)($widget['integrationId'] ?? '');
				if (in_array($leaf, ['calendar', 'contacts', 'forms', 'deck'], true) === false) {
					continue;
				}

				self::assertSame($leaf, ($widget['requiredApp'] ?? null), $page['id'] . ' ' . ($widget['id'] ?? '?') . ' requiredApp');
			}
		}
	}//end testEveryNewLeafWidgetDeclaresItsRequiredApp()

	/**
	 * Catalogue definitions carry no leaf in the register and none beyond files on their pages.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-leaves/spec.md#requirement-calendar-leaves-on-session-assignment-and-credential-req-002
	 */
	public function testCatalogueDefinitionsCarryNoLeaf(): void {
		$schemas = self::schemas();
		foreach (self::CATALOGUE as $name) {
			self::assertArrayHasKey($name, $schemas);
			self::assertArrayNotHasKey('linkedTypes', $schemas[$name], "$name is a catalogue definition.");
		}

		foreach (self::pages() as $page) {
			$schema = self::schemaName(schemas: $schemas, ref: (string)($page['config']['schema'] ?? ''));
			if (in_array($schema, self::CATALOGUE, true) === false) {
				continue;
			}

			foreach (self::integrationWidgets(page: $page) as $widget) {
				self::assertSame('files', ($widget['integrationId'] ?? null), $page['id'] . ' carries a leaf beyond files.');
			}
		}
	}//end testCatalogueDefinitionsCarryNoLeaf()

	/**
	 * Learniq ships no leaf provider code.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-leaves/spec.md#requirement-leaves-are-declared-not-coded-req-001
	 */
	public function testLibShipsNoIntegrationProvider(): void {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . '/lib', FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			self::assertDoesNotMatchRegularExpression('/implements[^{]*\bIntegrationProvider\b/', $source, $file->getPathname());
			self::assertStringNotContainsString('addProvider(', $source, $file->getPathname());
		}
	}//end testLibShipsNoIntegrationProvider()
}//end class
