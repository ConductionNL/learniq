<?php

/**
 * AiTranslatedCatalogue tests (ai-translated-catalogue-review).
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-marking-a-key-reviewed-removes-it-from-the-sidecar
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\AiTranslatedCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * The sidecar reads with source and value, and a reviewed key leaves it.
 */
class AiTranslatedCatalogueTest extends TestCase {
	/**
	 * A scratch l10n directory for one test.
	 *
	 * @var string
	 */
	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/learniq-ai-l10n-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		$this->write('en.json', ['translations' => ['Publish marks' => 'Publish marks']]);
		$this->write('nl.json', ['translations' => [
			'Publish marks' => 'Cijfers publiceren',
			'Everyone has handed in.' => 'Iedereen heeft ingeleverd.',
			'_%n mark published._::_%n marks published._' => ['%n cijfer gepubliceerd.', '%n cijfers gepubliceerd.'],
		]]);
		$this->write('ai-translated.json', [
			'$comment' => 'Keys an AI wrote.',
			'language' => 'nl',
			'keys' => ['Everyone has handed in.', 'Publish marks', '_%n mark published._::_%n marks published._'],
		]);
	}//end setUp()

	protected function tearDown(): void {
		@chmod($this->dir, 0755);
		foreach ((array)glob($this->dir . '/*') as $file) {
			@chmod((string)$file, 0644);
			@unlink((string)$file);
		}

		@rmdir($this->dir);
	}//end tearDown()

	/**
	 * Write a JSON file into the scratch directory.
	 *
	 * @param string $name The file name.
	 * @param array<string, mixed> $data The content.
	 *
	 * @return void
	 */
	private function write(string $name, array $data): void {
		file_put_contents($this->dir . '/' . $name, json_encode($data, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end write()

	/**
	 * The sidecar as it now stands on disk.
	 *
	 * @return array<string, mixed>
	 */
	private function sidecar(): array {
		return json_decode((string)file_get_contents($this->dir . '/ai-translated.json'), true);
	}//end sidecar()

	public function testTheListingCarriesTheSourceAndTheDutchValue(): void {
		$listing = (new AiTranslatedCatalogue($this->dir))->listing();

		$this->assertSame('nl', $listing['language']);
		$this->assertSame(3, $listing['total']);
		$this->assertSame(['key' => 'Publish marks', 'source' => 'Publish marks', 'value' => 'Cijfers publiceren'], $listing['items'][1]);
		$this->assertSame('Everyone has handed in.', $listing['items'][0]['source'], 'No English entry: the key is the source.');
		$this->assertSame(['%n cijfer gepubliceerd.', '%n cijfers gepubliceerd.'], $listing['items'][2]['value'], 'A plural value keeps both forms.');
	}

	public function testAReviewedKeyLeavesTheListAndTheRestStaysInOrder(): void {
		$outcome = (new AiTranslatedCatalogue($this->dir))->markReviewed('Publish marks');

		$this->assertSame(AiTranslatedCatalogue::REVIEWED, $outcome);
		$sidecar = $this->sidecar();
		$this->assertSame(['Everyone has handed in.', '_%n mark published._::_%n marks published._'], $sidecar['keys']);
		$this->assertSame('nl', $sidecar['language']);
		$this->assertSame('Keys an AI wrote.', $sidecar['$comment'], 'Every other member is kept.');
		$this->assertSame([], glob($this->dir . '/*.tmp'), 'No temporary file is left behind.');
	}

	public function testTheRewriteKeepsTheFileFormat(): void {
		(new AiTranslatedCatalogue($this->dir))->markReviewed('Publish marks');

		$raw = (string)file_get_contents($this->dir . '/ai-translated.json');
		$this->assertStringEndsWith("}\n", $raw);
		$this->assertStringContainsString("\n    \"language\": \"nl\"", $raw, 'Four-space indentation, as committed.');
		$this->assertStringContainsString('_%n mark published._::_%n marks published._', $raw, 'Slashes and unicode stay unescaped.');
	}

	public function testAKeyThatIsNotListedChangesNothing(): void {
		$before = (string)file_get_contents($this->dir . '/ai-translated.json');

		$this->assertSame(AiTranslatedCatalogue::NOT_LISTED, (new AiTranslatedCatalogue($this->dir))->markReviewed('Status message'));
		$this->assertSame(AiTranslatedCatalogue::NOT_LISTED, (new AiTranslatedCatalogue($this->dir))->markReviewed(''));
		$this->assertSame($before, (string)file_get_contents($this->dir . '/ai-translated.json'));
	}

	public function testAReadOnlyDirectoryIsRefusedNotWorkedAround(): void {
		if (function_exists('posix_geteuid') === true && posix_geteuid() === 0) {
			$this->markTestSkipped('root writes through file permissions.');
		}

		chmod($this->dir . '/ai-translated.json', 0444);
		chmod($this->dir, 0555);
		$before = (string)file_get_contents($this->dir . '/ai-translated.json');

		$this->assertSame(AiTranslatedCatalogue::READ_ONLY, (new AiTranslatedCatalogue($this->dir))->markReviewed('Publish marks'));
		$this->assertSame($before, (string)file_get_contents($this->dir . '/ai-translated.json'));
	}

	public function testAMissingOrBrokenSidecarListsNothing(): void {
		unlink($this->dir . '/ai-translated.json');
		$this->assertSame(0, (new AiTranslatedCatalogue($this->dir))->listing()['total']);
		$this->assertSame(AiTranslatedCatalogue::NOT_LISTED, (new AiTranslatedCatalogue($this->dir))->markReviewed('Publish marks'));

		file_put_contents($this->dir . '/ai-translated.json', '{broken');
		$this->assertSame(0, (new AiTranslatedCatalogue($this->dir))->listing()['total']);
	}

	public function testTheShippedSidecarIsReadFromTheAppByDefault(): void {
		$listing = (new AiTranslatedCatalogue())->listing();

		$this->assertSame('nl', $listing['language']);
		$this->assertGreaterThan(1000, $listing['total'], 'The round 1 and round 2 seed is shipped.');
		$keys = array_column($listing['items'], 'key');
		$this->assertContains('Everyone has handed in.', $keys);
	}
}
