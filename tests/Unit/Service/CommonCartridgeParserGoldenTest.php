<?php

/**
 * Learniq CommonCartridgeParser golden tests.
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
 *
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-course-lesson-material-hierarchy
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CommonCartridgeParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins CommonCartridgeParser::parseManifest() per manifest against
 * tests/Unit/Service/golden/CommonCartridgeParser.json. With RECORD_GOLDEN=1
 * the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\CommonCartridgeParser
 * @covers \OCA\Learniq\Service\CoursePackage\CommonCartridgeItemTree
 */
class CommonCartridgeParserGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/CommonCartridgeParser.json';

	private const CP = 'http://www.imsglobal.org/xsd/imscp_v1p1';

	/**
	 * Each scenario: the imsmanifest.xml text.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function manifests(): array {
		$ns = self::CP;
		return [
			'namespaced tree over two organizations' => ["<manifest xmlns=\"{$ns}\"><organizations>"
				. '<organization identifier="O1"><item identifier="F1"><title> Folder one </title>'
				. '<item identifier="F2"><title>Inner</title><item identifier="L1" identifierref="R1"><title>Leaf</title></item></item>'
				. '<item identifier="L2" identifierref=""><title>   </title></item></item>'
				. '<item identifier="L3" identifierref="R2"/></organization>'
				. '<organization identifier="O2"><title>skipped</title><item identifier="L4" identifierref="R3"><title>Next org</title></item></organization>'
				. '</organizations><resources>'
				. '<resource identifier="R1" type="webcontent" href="dir/page.html"><file href="other.html"/></resource>'
				. '<resource identifier="R2" type="imsqti_xmlv1p2/imscc_xmlv1p1/assessment"><file href="tests/t.xml"/></resource>'
				. '<resource identifier="R3" type="IMSQTI_XMLV1P2"/>'
				. '<resource identifier="R4" type="imsbasiclti_xmlv1p0" href=""/>'
				. '<resource identifier="R5" type="imswl_xmlv1p1" href="link.xml"/>'
				. '<resource identifier="R6" type="imsdt_xmlv1p1"><file/></resource>'
				. '<resource identifier="R7" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="a/b/c.pdf"/>'
				. '<resource identifier="R8" type="WebContent"/>'
				. '<resource identifier="R9" type="vendor/thing" href="x.bin"/>'
				. '<resource identifier="R10" type="imsdt_webcontent"/>'
				. '<resource identifier="R11" type=""/>'
				. '<resource identifier="R12" type="imsqti_test_xmlv1p2" href="test.xml"/>'
				. '</resources></manifest>'],
			'no namespace falls back to plain tag names' => ['<manifest><organizations><organization>'
				. '<item identifier="A"><title>A</title><item identifier="B" identifierref="X"/></item>'
				. '<item identifier="C"><metadata/><title>C</title></item>'
				. '</organization></organizations><resources>'
				. '<resource identifier="X" type="imsbasiclti_xmlv1p3"><file href="lti.xml"/></resource>'
				. '</resources></manifest>'],
			'namespaced resources, organizations outside the namespace' => ["<manifest xmlns:cp=\"{$ns}\"><cp:resources>"
				. '<cp:resource identifier="R1" type="webcontent" href="p.html"/></cp:resources>'
				. '<organizations><organization><item identifier="I1" identifierref="R1"><title>Only</title></item></organization></organizations>'
				. '</manifest>'],
			'empty manifest' => ["<manifest xmlns=\"{$ns}\"/>"],
			'organization with text and comments between items' => ["<manifest xmlns=\"{$ns}\"><organizations><organization>"
				. 'text<!-- c --><item identifier="I1"><!-- c -->x<title>T</title></item><other identifier="Z"/>'
				. '<item identifier="I2"><title/><item identifier="I3"/></item>'
				. '</organization></organizations></manifest>'],
		];
	}//end manifests()

	/**
	 * The parsed manifest equals the recorded one.
	 *
	 * @param string $manifest The imsmanifest.xml text.
	 *
	 * @return void
	 */
	#[DataProvider('manifests')]
	public function testParseManifestMatchesGolden(string $manifest): void {
		$name = (string)$this->dataName();
		$dir  = $this->packageDir(manifest: $manifest);
		try {
			$actual = (new CommonCartridgeParser())->parseManifest($dir);
		} finally {
			unlink($dir . '/imsmanifest.xml');
			rmdir($dir);
		}

		$golden = [];
		if (file_exists(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		self::assertArrayHasKey($name, $golden);
		self::assertSame($golden[$name], $actual);
	}//end testParseManifestMatchesGolden()

	/**
	 * A manifest that is not XML throws.
	 *
	 * @return void
	 */
	public function testUnparseableManifestThrows(): void {
		$dir = $this->packageDir(manifest: '<manifest><unclosed></manifest>');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('as XML');
		try {
			(new CommonCartridgeParser())->parseManifest($dir);
		} finally {
			unlink($dir . '/imsmanifest.xml');
			rmdir($dir);
		}
	}//end testUnparseableManifestThrows()

	/**
	 * A missing manifest names the directory in its message.
	 *
	 * @return void
	 */
	public function testMissingManifestNamesTheDirectory(): void {
		$dir = $this->packageDir(manifest: null);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("No imsmanifest.xml found in '{$dir}'");
		try {
			(new CommonCartridgeParser())->parseManifest($dir);
		} finally {
			rmdir($dir);
		}
	}//end testMissingManifestNamesTheDirectory()

	/**
	 * Make a temporary package directory, holding the manifest when one is given.
	 *
	 * @param string|null $manifest The imsmanifest.xml text, or null for none.
	 *
	 * @return string The directory.
	 */
	private function packageDir(?string $manifest): string {
		$dir = sys_get_temp_dir() . '/learniq_cc_golden_' . bin2hex(random_bytes(6));
		mkdir($dir, 0700, true);
		if ($manifest !== null) {
			file_put_contents($dir . '/imsmanifest.xml', $manifest);
		}

		return $dir;
	}//end packageDir()
}//end class
