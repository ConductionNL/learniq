<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\QtiManifestScanner;
use PHPUnit\Framework\TestCase;

/**
 * Pins detectPackageType() and collectItemPaths() for package folders that
 * reach every branch, against golden/QtiManifestScan.json, case by case.
 * Paths are recorded relative to the package folder. With RECORD_GOLDEN=1
 * the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\QtiManifestScanner
 */
class QtiManifestScannerGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/QtiManifestScan.json';

	/**
	 * The package folders made by this test.
	 *
	 * @var list<string>
	 */
	private array $dirs = [];

	/**
	 * Every folder scans as recorded.
	 *
	 * @return void
	 */
	public function testScanMatchesGolden(): void {
		$scanner = new QtiManifestScanner();
		// The broken manifest is expected to fail to parse; keep libxml's warning out of the run.
		$previous = libxml_use_internal_errors(true);
		$actual = [];
		foreach (self::packages() as $name => $files) {
			$dir = $this->folder(files: $files);
			$type = $scanner->detectPackageType(dir: $dir);
			$paths = [];
			foreach (['qti3', 'qti2', 'cc', 'unknown'] as $asType) {
				$paths[$asType] = array_map(
					static fn (string $path): string => str_replace((string)realpath($dir), '<real>', str_replace($dir, '<dir>', $path)),
					$scanner->collectItemPaths(dir: $dir, packageType: $asType)
				);
			}

			$actual[$name] = ['type' => $type, 'paths' => $paths];
		}

		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		self::assertSame(expected: array_keys($golden), actual: array_keys($actual));
		foreach ($golden as $name => $expected) {
			self::assertSame(expected: $expected, actual: $actual[$name], message: $name);
		}
	}//end testScanMatchesGolden()

	/**
	 * Remove the folders.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->dirs as $dir) {
			self::remove(path: $dir);
		}

		$this->dirs = [];
	}//end tearDown()

	/**
	 * The packages by name: relative path to content.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function packages(): array {
		$ns = 'xmlns="http://www.imsglobal.org/xsd/imscp_v1p1"';
		$item = '<assessmentItem identifier="i"/>';
		return [
			'no manifest' => ['a.xml' => $item, 'sub/b.xml' => $item, 'sub/deep/c.xml' => $item],
			'no manifest, nothing nested' => ['a.xml' => $item],
			'qti3 by asi' => ['imsmanifest.xml' => '<manifest ' . $ns . ' x="imsqtiasi_v3p0"><resources><resource type="imsqti_item_xmlv3p0" href="i1.xml"/></resources></manifest>', 'i1.xml' => $item],
			'qti3 by qti' => ['imsmanifest.xml' => '<manifest x="imsqti_v3p0"><resource type="imsqti_test_xmlv3p0"><file href="t.xml"/></resource></manifest>', 't.xml' => $item],
			'qti2' => ['imsmanifest.xml' => '<manifest x="imsqti_v2p2"><resource type="imsqti_item_xmlv2p2" href="i.xml"/></manifest>', 'i.xml' => '<qti-item/>'],
			'qti3 and qti2' => ['imsmanifest.xml' => '<manifest x="imsqti_v2p2 imsqti_v3p0"/>'],
			'untyped namespaced and plain typed' => [
				'imsmanifest.xml' => '<manifest ' . $ns . '><resources><resource href="u.xml"/><resource xmlns="" type="imsqti_item_x" href="p.xml"/></resources></manifest>',
				'u.xml' => '<qti-item/>',
				'p.xml' => '<qti-item/>',
			],
			'qti21' => ['imsmanifest.xml' => '<manifest x="imsqti_v2p1"/>'],
			'cc by xmlv1' => [
				'imsmanifest.xml' => '<manifest ' . $ns . ' x="imscc_xmlv1p1"><resources>'
					. '<resource type="imsqti_xmlv1p2/imscc_xmlv1p1/assessment" href="q.xml"/>'
					. '<resource type="webcontent" href="w.xml"/>'
					. '<resource type="imsqti_item_xmlv2p1" href="missing.xml"/>'
					. '<resource type="imsqti_item_xmlv2p1" href="../outside.xml"/>'
					. '<resource type="imsqti_item_xmlv2p1"/>'
					. '<resource type="imsqti_item_xmlv2p1"><file href="f.xml"/></resource>'
					. '<resource type="imsqti_item_xmlv2p1" href=""><file href="g.xml"/></resource>'
					. '</resources></manifest>',
				'q.xml' => $item,
				'w.xml' => $item,
				'f.xml' => $item,
				'g.xml' => $item,
			],
			'cc by ccv1' => ['imsmanifest.xml' => '<manifest x="imsccv1p3"><resource type="imsqti_x" href="q.xml"/></manifest>', 'q.xml' => $item],
			'plain manifest, scan finds items' => [
				'imsmanifest.xml' => '<manifest><resource type="webcontent" href="w.html"/></manifest>',
				'one.xml' => $item,
				'two.xml' => '<other/>',
				'sub/three.xml' => $item,
			],
			'broken manifest' => ['imsmanifest.xml' => '<manifest', 'one.xml' => $item],
			'untyped namespaced resource' => ['imsmanifest.xml' => '<manifest ' . $ns . '><resources><resource href="u.xml"/></resources></manifest>', 'u.xml' => $item],
			'namespaced typed and plain' => [
				'imsmanifest.xml' => '<manifest ' . $ns . ' xmlns:o="urn:o"><resources><resource type="imsqti_item_x" href="n.xml"/></resources><o:resource type="imsqti_item_x" href="o.xml"/></manifest>',
				'n.xml' => $item,
				'o.xml' => $item,
			],
		];
	}//end packages()

	/**
	 * Write the files into a new folder.
	 *
	 * @param array<string, string> $files Relative path to content.
	 *
	 * @return string The folder.
	 */
	private function folder(array $files): string {
		$base = sys_get_temp_dir() . '/qtiscan-' . bin2hex(random_bytes(6));
		$dir = $base . '/pkg';
		mkdir($dir, 0777, true);
		$this->dirs[] = $base;
		foreach ($files as $path => $content) {
			if (is_dir(dirname($dir . '/' . $path)) === false) {
				mkdir(dirname($dir . '/' . $path), 0777, true);
			}

			file_put_contents($dir . '/' . $path, $content);
		}

		file_put_contents($base . '/outside.xml', '<assessmentItem/>');
		return $dir;
	}//end folder()

	/**
	 * Remove a folder and everything in it.
	 *
	 * @param string $path The folder.
	 *
	 * @return void
	 */
	private static function remove(string $path): void {
		foreach ((array)glob($path . '/{,.}[!.,!..]*', GLOB_BRACE) as $entry) {
			if (is_dir((string)$entry) === true) {
				self::remove(path: (string)$entry);
				continue;
			}

			unlink((string)$entry);
		}

		rmdir($path);
	}//end remove()
}//end class
