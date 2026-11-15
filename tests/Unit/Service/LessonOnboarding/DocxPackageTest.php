<?php

/**
 * Unit tests for DocxPackage: guarded part reads, the core title, heading
 * styles and image relationships.
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

use OCA\Learniq\Service\LessonOnboarding\DocxLessonReader;
use OCA\Learniq\Service\LessonOnboarding\DocxPackage;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * @covers \OCA\Learniq\Service\LessonOnboarding\DocxPackage
 */
class DocxPackageTest extends TestCase {

	private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * Temp files to remove.
	 *
	 * @var list<string>
	 */
	private array $paths = [];

	/**
	 * A package over a zip holding the given parts.
	 *
	 * @param array<string, string> $parts Name => bytes.
	 *
	 * @return DocxPackage
	 */
	private function package(array $parts): DocxPackage {
		$path = (string)tempnam(sys_get_temp_dir(), 'dxp');
		$this->paths[] = $path;
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		$zip->addFromString('[Content_Types].xml', '<Types/>');
		foreach ($parts as $name => $bytes) {
			$zip->addFromString($name, $bytes);
		}

		$zip->close();
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::RDONLY);
		return new DocxPackage($zip);
	}//end package()

	/**
	 * Remove the temp files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->paths as $path) {
			if (is_file($path) === true) {
				unlink($path);
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A well-formed part parses; a missing, empty or malformed one is null and not refused.
	 *
	 * @return void
	 */
	public function testXmlParsesWellFormedPartsOnly(): void {
		$package = $this->package(['a.xml' => '<root><x>1</x></root>', 'empty.xml' => '', 'bad.xml' => '<root><x></root>']);

		self::assertSame('1', $package->xml('a.xml')?->getElementsByTagName('x')->item(0)?->textContent);
		self::assertNull($package->xml('missing.xml'));
		self::assertNull($package->xml('empty.xml'));
		self::assertNull($package->xml('bad.xml'));
		self::assertSame([], $package->refused());
	}//end testXmlParsesWellFormedPartsOnly()

	/**
	 * A part with a DOCTYPE is refused and recorded, in any letter case.
	 *
	 * @return void
	 */
	public function testADoctypeIsRefusedAndRecorded(): void {
		$package = $this->package(
			[
				'd.xml' => '<!doctype root><root/>',
				'e.xml' => '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x "y">]><r>&x;</r>',
			]
		);

		self::assertNull($package->xml('d.xml'));
		self::assertNull($package->xml('e.xml'));
		self::assertSame(['d.xml', 'e.xml'], $package->refused());
	}//end testADoctypeIsRefusedAndRecorded()

	/**
	 * A part over the size limit is refused and recorded.
	 *
	 * @return void
	 */
	public function testAnOversizedPartIsRefused(): void {
		$package = $this->package(['big.xml' => '<r>' . str_repeat('a', DocxLessonReader::MAX_PART_BYTES) . '</r>']);

		self::assertNull($package->xml('big.xml'));
		self::assertSame(['big.xml'], $package->refused());
	}//end testAnOversizedPartIsRefused()

	/**
	 * An image is its bytes; missing or empty is null.
	 *
	 * @return void
	 */
	public function testImageReturnsBytesOrNull(): void {
		$package = $this->package(['word/media/a.png' => 'PNG', 'word/media/e.png' => '']);

		self::assertSame('PNG', $package->image('word/media/a.png'));
		self::assertNull($package->image('word/media/e.png'));
		self::assertNull($package->image('word/media/none.png'));
	}//end testImageReturnsBytesOrNull()

	/**
	 * The core title is the trimmed dc:title, empty without one.
	 *
	 * @return void
	 */
	public function testCoreTitle(): void {
		$core = '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
			. 'xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>  Licht  </dc:title></cp:coreProperties>';
		self::assertSame('Licht', $this->package(['docProps/core.xml' => $core])->coreTitle());

		$noTitle = '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"/>';
		self::assertSame('', $this->package(['docProps/core.xml' => $noTitle])->coreTitle());
		self::assertSame('', $this->package([])->coreTitle());
	}//end testCoreTitle()

	/**
	 * Without styles.xml the built-in Title and Heading1-6 ids are the heading styles.
	 *
	 * @return void
	 */
	public function testDefaultHeadingStyles(): void {
		self::assertSame(
			[
				'Title' => 'title',
				'Heading1' => 'heading',
				'Heading2' => 'heading',
				'Heading3' => 'heading',
				'Heading4' => 'heading',
				'Heading5' => 'heading',
				'Heading6' => 'heading',
			],
			$this->package([])->headingStyles()
		);
	}//end testDefaultHeadingStyles()

	/**
	 * Localised paragraph styles named title or heading 1-6 join the defaults; others do not.
	 *
	 * @return void
	 */
	public function testNamedParagraphStylesAreAdded(): void {
		$styles = '<w:styles xmlns:w="' . self::NS_W . '">'
			. '<w:style w:type="paragraph" w:styleId="Kop1"><w:name w:val="heading 1"/></w:style>'
			. '<w:style w:type="paragraph" w:styleId="Titel"><w:name w:val=" Title "/></w:style>'
			. '<w:style w:type="paragraph" w:styleId="Kop7"><w:name w:val="heading 7"/></w:style>'
			. '<w:style w:type="paragraph" w:styleId="Gewoon"><w:name w:val="Normal"/></w:style>'
			. '<w:style w:type="character" w:styleId="Kop2Char"><w:name w:val="heading 2"/></w:style>'
			. '<w:style w:type="paragraph" w:styleId="Kop3"><w:name w:val="Heading3"/></w:style>'
			. '</w:styles>';

		$result = $this->package(['word/styles.xml' => $styles])->headingStyles();

		self::assertSame('heading', $result['Kop1']);
		self::assertSame('title', $result['Titel']);
		self::assertSame('heading', $result['Kop3']);
		self::assertArrayNotHasKey('Kop7', $result);
		self::assertArrayNotHasKey('Gewoon', $result);
		self::assertArrayNotHasKey('Kop2Char', $result);
		self::assertSame('title', $result['Title']);
		self::assertCount(10, $result);
	}//end testNamedParagraphStylesAreAdded()

	/**
	 * Internal image relationships map to package paths; external, non-image and climbing ones do not.
	 *
	 * @return void
	 */
	public function testImageTargets(): void {
		$base = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
		$rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="' . $base . 'image" Target="media/image1.png"/>'
			. '<Relationship Id="rId2" Type="' . $base . 'image" Target="/word/media/image2.jpg"/>'
			. '<Relationship Id="rId3" Type="' . $base . 'image" Target="https://example.org/x.png" TargetMode="External"/>'
			. '<Relationship Id="rId4" Type="' . $base . 'styles" Target="styles.xml"/>'
			. '<Relationship Id="rId5" Type="' . $base . 'image" Target="../media/up.png"/>'
			. '</Relationships>';

		self::assertSame(
			['rId1' => 'word/media/image1.png', 'rId2' => 'word/media/image2.jpg'],
			$this->package(['word/_rels/document.xml.rels' => $rels])->imageTargets()
		);
		self::assertSame([], $this->package([])->imageTargets());
	}//end testImageTargets()
}//end class
