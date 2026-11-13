<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use OCA\Learniq\Service\CoursePackage\PackageXmlValueReader;
use PHPUnit\Framework\TestCase;

/**
 * Reads the first attribute or text value of a tag from a package XML file,
 * and answers null for a missing file, unparsable XML, a missing tag or an
 * empty value.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\PackageXmlValueReader
 */
class PackageXmlValueReaderTest extends TestCase {

	/**
	 * Temporary files to remove.
	 *
	 * @var list<string>
	 */
	private array $temp = [];

	/**
	 * Remove the files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->temp as $path) {
			@unlink($path);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A file holding the given XML.
	 *
	 * @param string $xml The content.
	 *
	 * @return string The path.
	 */
	private function file(string $xml): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'learniq-xml-');
		file_put_contents($path, $xml);
		$this->temp[] = $path;
		return $path;
	}//end file()

	/**
	 * The first matching element's attribute, or null when it is absent or empty.
	 *
	 * @return void
	 */
	public function testReadsTheFirstElementsAttribute(): void {
		$reader = new PackageXmlValueReader();
		$path = $this->file('<activity><file href="a.pdf" empty=""/><file href="b.pdf"/><note>x</note></activity>');

		self::assertSame('a.pdf', $reader->readAttribute(path: $path, tagName: 'file', attribute: 'href'));
		self::assertNull($reader->readAttribute(path: $path, tagName: 'file', attribute: 'empty'));
		self::assertNull($reader->readAttribute(path: $path, tagName: 'file', attribute: 'missing'));
		self::assertNull($reader->readAttribute(path: $path, tagName: 'resource', attribute: 'href'));
	}//end testReadsTheFirstElementsAttribute()

	/**
	 * The first matching element's trimmed text, or null when it is absent or blank.
	 *
	 * @return void
	 */
	public function testReadsTheFirstElementsTrimmedText(): void {
		$reader = new PackageXmlValueReader();
		$path = $this->file("<url><externalurl>\n  https://example.org/les \n</externalurl><blank>   </blank></url>");

		self::assertSame('https://example.org/les', $reader->readTextContent(path: $path, tagName: 'externalurl'));
		self::assertNull($reader->readTextContent(path: $path, tagName: 'blank'));
		self::assertNull($reader->readTextContent(path: $path, tagName: 'name'));
	}//end testReadsTheFirstElementsTrimmedText()

	/**
	 * A missing file or a file that is not XML reads as nothing, without a warning.
	 *
	 * @return void
	 */
	public function testAMissingOrBrokenFileReadsAsNothing(): void {
		$reader = new PackageXmlValueReader();
		$missing = sys_get_temp_dir() . '/learniq-xml-does-not-exist-' . getmypid() . '.xml';
		$broken = $this->file('<activity><file href="a.pdf">');

		self::assertNull($reader->readAttribute(path: $missing, tagName: 'file', attribute: 'href'));
		self::assertNull($reader->readTextContent(path: $missing, tagName: 'file'));
		self::assertNull($reader->readAttribute(path: $broken, tagName: 'file', attribute: 'href'));
		self::assertNull($reader->readTextContent(path: $broken, tagName: 'file'));
		self::assertSame([], libxml_get_errors());
	}//end testAMissingOrBrokenFileReadsAsNothing()
}//end class
