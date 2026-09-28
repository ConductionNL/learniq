<?php

/**
 * Learniq speaks of support needs and differentiation, never of pupils having
 * fixed ways of learning that lessons should be matched to (assumption A8).
 *
 * Research finds no benefit in that matching (Pashler, McDaniel, Rohrer and
 * Bjork 2008, Psychological Science in the Public Interest 9(3); NRO
 * Kennisrotonde, "Differentiatie in de klas: wat werkt?"), and profiling minors
 * on it is a privacy risk. Learniq has no trace of it today; this suite keeps
 * it that way on every product surface, and pins the plain copy of the four
 * differentiation forms (differentiation-not-styles-copy).
 *
 * `openspec/changes/` is not scanned: a proposal may need to discuss the
 * research. Everything a user reads, and the canonical specs, is.
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
 * @spec openspec/changes/archive/2026-09-28-differentiation-not-styles-copy/tasks.md#task-2-wording-guard-and-settings-note
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Scans product surfaces for style-matching wording and pins the forms' copy.
 */
class SupportNeedsVocabularyTest extends TestCase {

	/**
	 * English (with camelCase) and Dutch forms of the wording.
	 */
	private const PATTERNS = [
		'/learn(?:ing)?[\s_-]*styles?/i',
		'/leer[\s-]*stijl(?:en)?/i',
	];

	/**
	 * Product surfaces, relative to the app root.
	 */
	private const ROOTS = ['lib', 'src', 'templates', 'appinfo', 'docs', 'openspec/specs'];

	/**
	 * Catalogues scanned: the English source and the Dutch translation.
	 */
	private const CATALOGUES = ['l10n/en.json', 'l10n/nl.json'];

	/**
	 * Directories never scanned: dependencies and build output.
	 */
	private const SKIP_DIRS = ['node_modules', 'vendor', 'build', '.docusaurus', 'dist'];

	/**
	 * File types scanned.
	 */
	private const EXTENSIONS = ['php', 'js', 'mjs', 'ts', 'vue', 'json', 'md', 'mdx', 'html', 'xml', 'yaml', 'yml'];

	/**
	 * The app root.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * Every "file:line: text" where a pattern matches.
	 *
	 * @param string $path Absolute file path.
	 *
	 * @return array<int, string>
	 */
	private static function hitsIn(string $path): array {
		$hits = [];
		foreach ((array)file($path) as $number => $line) {
			if (self::carriesWording(text: (string)$line) === true) {
				$hits[] = substr($path, strlen(self::root()) + 1) . ':' . ($number + 1) . ': ' . trim((string)$line);
			}
		}

		return $hits;
	}//end hitsIn()

	/**
	 * Whether a text carries the wording.
	 *
	 * @param string $text The text.
	 *
	 * @return bool
	 */
	private static function carriesWording(string $text): bool {
		foreach (self::PATTERNS as $pattern) {
			if (preg_match($pattern, $text) === 1) {
				return true;
			}
		}

		return false;
	}//end carriesWording()

	/**
	 * The files to scan.
	 *
	 * @return array<int, string>
	 */
	private static function files(): array {
		$files = array_map(static fn (string $catalogue): string => self::root() . '/' . $catalogue, self::CATALOGUES);

		foreach (self::ROOTS as $root) {
			$dir = self::root() . '/' . $root;
			if (is_dir($dir) === false) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
			foreach ($iterator as $file) {
				$path = $file->getPathname();
				if (in_array(strtolower($file->getExtension()), self::EXTENSIONS, true) === false) {
					continue;
				}

				foreach (self::SKIP_DIRS as $skip) {
					if (str_contains($path, '/' . $skip . '/') === true) {
						continue 2;
					}
				}

				$files[] = $path;
			}
		}

		return $files;
	}//end files()

	/**
	 * No product surface carries the wording.
	 *
	 * @return void
	 */
	public function testNoProductSurfaceCarriesStyleMatchingWording(): void {
		$files = self::files();
		self::assertGreaterThan(100, count($files), 'The scan found suspiciously few files; the roots moved.');

		$hits = [];
		foreach ($files as $path) {
			$hits = [...$hits, ...self::hitsIn(path: $path)];
		}

		self::assertSame([], $hits, "Say support needs or differentiation instead:\n" . implode("\n", $hits));
	}//end testNoProductSurfaceCarriesStyleMatchingWording()

	/**
	 * The matcher catches the forms it exists for and leaves ordinary words alone,
	 * so the scan above cannot pass by matching nothing.
	 *
	 * @return void
	 */
	public function testTheMatcherCatchesTheWordingAndNothingElse(): void {
		// Assembled from parts so a plain `git grep -i` for the wording over
		// the whole repository stays empty, which is how its absence is audited.
		$learn = 'learn';
		$style = 'style';
		$leer  = 'leer';
		$stijl = 'stijl';
		$samples = [
			"Adapt to the pupil's {$learn}ing {$style}.",
			"{$learn}ing-{$style}s",
			"{$learn}ing" . ucfirst($style),
			ucfirst($leer) . $stijl,
			"{$leer}{$stijl}en",
			strtoupper("{$learn} {$style}"),
		];
		foreach ($samples as $text) {
			self::assertTrue(self::carriesWording(text: $text), "Should match: $text");
		}

		foreach (['differentiation by level', 'learning plan', 'style guide', 'leerlingvolgsysteem', 'learner stylesheet'] as $text) {
			self::assertFalse(self::carriesWording(text: $text), "Should not match: $text");
		}
	}//end testTheMatcherCatchesTheWordingAndNothingElse()

	/**
	 * The four forms read as plain differentiation and support-needs copy, the
	 * old rationale kept in x-notes, every string translated.
	 *
	 * @return void
	 */
	public function testTheDifferentiationFormsReadPlainly(): void {
		$register = json_decode((string)file_get_contents(self::root() . '/lib/Settings/learniq_register.json'), true);
		$schemas  = $register['components']['schemas'];
		$en       = json_decode((string)file_get_contents(self::root() . '/l10n/en.json'), true)['translations'];
		$nl       = json_decode((string)file_get_contents(self::root() . '/l10n/nl.json'), true)['translations'];

		self::assertSame('Group plan', $schemas['GroupPlan']['title']);
		self::assertSame('Instruction group', $schemas['GroupPlanSubgroup']['title']);
		self::assertSame('Support request', $schemas['SupportRequest']['title']);
		self::assertSame('Exam accommodation', $schemas['ExamAccommodation']['title']);

		$approach = $schemas['GroupPlanSubgroup']['properties']['approach']['description'];
		self::assertStringContainsString('instruction time', $approach);
		self::assertStringContainsString('material', $approach);
		self::assertStringContainsString('version-chain', $schemas['GroupPlan']['properties']['supersedesId']['x-notes']);
		self::assertStringContainsString('ExamAccommodationApprovalGuard', $schemas['ExamAccommodation']['properties']['approvedBy']['x-notes']);

		foreach (['GroupPlan', 'GroupPlanSubgroup', 'SupportRequest', 'ExamAccommodation'] as $name) {
			$strings = [$schemas[$name]['title']];
			foreach ($schemas[$name]['properties'] as $property) {
				if (isset($property['x-notes']) === false) {
					continue;
				}

				$strings[] = $property['title'];
				$strings[] = $property['description'];
				foreach (($property['x-enum-labels'] ?? []) as $label) {
					$strings[] = $label;
				}
			}

			foreach ($strings as $string) {
				self::assertArrayHasKey($string, $en, "$name: '$string' has no l10n/en.json key.");
				self::assertArrayHasKey($string, $nl, "$name: '$string' has no l10n/nl.json value.");
				self::assertFalse(self::carriesWording(text: $string));
			}
		}
	}//end testTheDifferentiationFormsReadPlainly()

	/**
	 * Settings carry the evidence note under AI features.
	 *
	 * @return void
	 */
	public function testSettingsCarryTheEvidenceNote(): void {
		$view = (string)file_get_contents(self::root() . '/src/views/LearniqSettings.vue');

		self::assertStringContainsString('data-testid="learniq-differentiation-note"', $view);
		self::assertStringContainsString('NRO Kennisrotonde', $view);
	}//end testSettingsCarryTheEvidenceNote()
}//end class
