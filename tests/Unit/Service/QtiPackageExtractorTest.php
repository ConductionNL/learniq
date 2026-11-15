<?php

/**
 * Characterisation tests for QtiPackageExtractor.
 *
 * Pins what a ZIP package extracts to (files, nested folders, directory
 * entries), the zip-slip refusal, and the recursive directory removal.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\QtiPackageExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Tests for the ZIP extraction behind the QTI import.
 */
class QtiPackageExtractorTest extends TestCase {

	/**
	 * Scratch directory for this test.
	 *
	 * @var string
	 */
	private string $work = '';

	/**
	 * Create the scratch directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->work = sys_get_temp_dir() . '/qti-extract-' . bin2hex(random_bytes(6));
		mkdir($this->work);
	}//end setUp()

	/**
	 * Remove the scratch directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		(new QtiPackageExtractor())->removeDirectory(dir: $this->work);
		parent::tearDown();
	}//end tearDown()

	/**
	 * Build a ZIP with the given entries (name => content, null for a directory entry).
	 *
	 * @param array<string,string|null> $entries The entries.
	 *
	 * @return string The ZIP path.
	 */
	private function zip(array $entries): string {
		$path = $this->work . '/package.zip';
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			if ($content === null) {
				$zip->addEmptyDir(rtrim($name, '/'));
				continue;
			}

			$zip->addFromString($name, $content);
		}

		$zip->close();

		return $path;
	}//end zip()

	/**
	 * Every file below a directory, relative, with its content.
	 *
	 * @param string $dir The directory.
	 *
	 * @return array<string,string|null> Files with content, directories with null.
	 */
	private static function tree(string $dir): array {
		$out = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
		foreach ($iterator as $info) {
			$relative = substr($info->getPathname(), strlen($dir) + 1);
			$out[$relative] = $info->isDir() === true ? null : (string)file_get_contents($info->getPathname());
		}

		ksort($out);

		return $out;
	}//end tree()

	/**
	 * Files, nested folders and directory entries all land inside the target, which is created.
	 *
	 * @return void
	 */
	public function testExtractsTheWholePackage(): void {
		$zip = $this->zip(['imsmanifest.xml' => '<manifest/>', 'items/q1.xml' => '<q1/>', 'media/' => null, 'deep/a/b/c.txt' => 'c']);
		$target = $this->work . '/out/nested';

		(new QtiPackageExtractor())->extractZip(zipPath: $zip, targetDir: $target);

		self::assertSame(
			[
				'deep' => null,
				'deep/a' => null,
				'deep/a/b' => null,
				'deep/a/b/c.txt' => 'c',
				'imsmanifest.xml' => '<manifest/>',
				'items' => null,
				'items/q1.xml' => '<q1/>',
				'media' => null,
			],
			self::tree($target)
		);
	}//end testExtractsTheWholePackage()

	/**
	 * An entry that climbs out of the target is refused.
	 *
	 * @return void
	 */
	public function testZipSlipIsRefused(): void {
		$zip = $this->zip(['ok.xml' => 'ok', '../../escaped.txt' => 'x']);
		$target = $this->work . '/out';

		try {
			(new QtiPackageExtractor())->extractZip(zipPath: $zip, targetDir: $target);
			self::fail('a zip-slip entry must be refused');
		} catch (RuntimeException $e) {
			self::assertSame("ZIP entry '../../escaped.txt' would extract outside the target directory (zip-slip attack).", $e->getMessage());
		}

		self::assertFileDoesNotExist($this->work . '/escaped.txt');
	}//end testZipSlipIsRefused()

	/**
	 * Removing a directory removes its whole tree; a missing directory is a no-op.
	 *
	 * @return void
	 */
	public function testRemoveDirectory(): void {
		$dir = $this->work . '/gone';
		mkdir($dir . '/a/b', 0700, true);
		file_put_contents($dir . '/a/b/f.txt', 'f');
		file_put_contents($dir . '/top.txt', 't');
		file_put_contents($dir . '/.hidden', 'h');

		$extractor = new QtiPackageExtractor();
		$extractor->removeDirectory(dir: $dir);
		self::assertDirectoryDoesNotExist($dir);

		$extractor->removeDirectory(dir: $dir);
		self::assertDirectoryDoesNotExist($dir);
	}//end testRemoveDirectory()
}//end class
