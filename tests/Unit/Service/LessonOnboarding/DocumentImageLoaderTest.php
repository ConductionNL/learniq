<?php

/**
 * Unit tests for DocumentImageLoader: images read from a Word package by
 * target, within the reader's limits.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\LessonOnboarding
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\LessonOnboarding;

use OCA\Learniq\Service\LessonOnboarding\DocumentImageLoader;
use OCA\Learniq\Service\LessonOnboarding\DocxLessonReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * @covers \OCA\Learniq\Service\LessonOnboarding\DocumentImageLoader
 */
class DocumentImageLoaderTest extends TestCase {

	/**
	 * Zip bytes holding the given parts.
	 *
	 * @param array<string, string> $parts Name => bytes.
	 *
	 * @return string
	 */
	private static function zipBytes(array $parts): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'dil');
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($parts as $name => $bytes) {
			$zip->addFromString($name, $bytes);
		}

		$zip->close();
		$bytes = (string)file_get_contents($path);
		unlink($path);
		return $bytes;
	}//end zipBytes()

	/**
	 * An image part loads as its base name and bytes.
	 *
	 * @return void
	 */
	public function testAnImagePartLoadsAsNameAndBytes(): void {
		$loader = new DocumentImageLoader(self::zipBytes(['word/media/image1.png' => 'PNGBYTES']));

		self::assertSame(['name' => 'image1.png', 'bytes' => 'PNGBYTES'], $loader->load(['target' => 'word/media/image1.png']));
		$loader->close();
	}//end testAnImagePartLoadsAsNameAndBytes()

	/**
	 * External, targetless, missing and empty images load as null.
	 *
	 * @return void
	 */
	public function testUnloadableImagesAreNull(): void {
		$loader = new DocumentImageLoader(self::zipBytes(['word/media/a.png' => 'A', 'word/media/empty.png' => '']));

		self::assertNull($loader->load(['target' => 'word/media/a.png', 'external' => true]));
		self::assertNull($loader->load([]));
		self::assertNull($loader->load(['target' => '']));
		self::assertNull($loader->load(['target' => 'word/media/missing.png']));
		self::assertNull($loader->load(['target' => 'word/media/empty.png']));
		self::assertSame(
			['name' => 'a.png', 'bytes' => 'A'],
			$loader->load(['target' => 'word/media/a.png', 'external' => 'true']),
			'only a boolean true external flag refuses'
		);
		$loader->close();
	}//end testUnloadableImagesAreNull()

	/**
	 * Empty or non-zip content loads nothing.
	 *
	 * @return void
	 */
	public function testEmptyOrNonZipContentLoadsNothing(): void {
		$empty = new DocumentImageLoader('');
		self::assertNull($empty->load(['target' => 'word/media/a.png']));
		$empty->close();

		$garbage = new DocumentImageLoader('not a zip at all');
		self::assertNull($garbage->load(['target' => 'word/media/a.png']));
		$garbage->close();
	}//end testEmptyOrNonZipContentLoadsNothing()

	/**
	 * Only loaded images count toward the limit; at the limit nothing more loads.
	 *
	 * @return void
	 */
	public function testTheImageLimitStopsLoading(): void {
		$loader = new DocumentImageLoader(self::zipBytes(['word/media/a.png' => 'A']));

		self::assertNull($loader->load(['target' => 'word/media/missing.png']));
		for ($i = 1; $i < DocxLessonReader::MAX_IMAGES; $i++) {
			self::assertNotNull($loader->load(['target' => 'word/media/a.png']));
			self::assertFalse($loader->limitReached());
		}

		self::assertNotNull($loader->load(['target' => 'word/media/a.png']));
		self::assertTrue($loader->limitReached());
		self::assertNull($loader->load(['target' => 'word/media/a.png']));
		$loader->close();
	}//end testTheImageLimitStopsLoading()

	/**
	 * After close nothing loads, and closing twice is harmless.
	 *
	 * @return void
	 */
	public function testAfterCloseNothingLoads(): void {
		$loader = new DocumentImageLoader(self::zipBytes(['word/media/a.png' => 'A']));
		$loader->close();
		$loader->close();

		self::assertNull($loader->load(['target' => 'word/media/a.png']));
	}//end testAfterCloseNothingLoads()
}//end class
