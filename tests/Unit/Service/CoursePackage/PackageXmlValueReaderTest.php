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
 * PackageXmlValueReader reads one attribute or text value from a side-car XML
 * file, answering null for anything it cannot resolve.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\PackageXmlValueReader
 */
class PackageXmlValueReaderTest extends TestCase {

	/**
	 * Fixture directory.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * The reader under test.
	 *
	 * @var PackageXmlValueReader
	 */
	private PackageXmlValueReader $reader;

	/**
	 * Create the fixture directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/learniq-pxvr-' . uniqid('', true);
		mkdir($this->dir, 0777, true);
		$this->reader = new PackageXmlValueReader();
	}//end setUp()

	/**
	 * Remove the fixture directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((array)glob($this->dir . '/*') as $file) {
			unlink((string)$file);
		}

		rmdir($this->dir);
	}//end tearDown()

	/**
	 * Write a fixture file.
	 *
	 * @param string $name    File name.
	 * @param string $content File content.
	 *
	 * @return string The absolute path.
	 */
	private function fixture(string $name, string $content): string {
		$path = $this->dir . '/' . $name;
		file_put_contents($path, $content);
		return $path;
	}//end fixture()

	/**
	 * The attribute of the first matching element is returned.
	 *
	 * @return void
	 */
	public function testReadAttributeReturnsTheFirstElementsValue(): void {
		$path = $this->fixture(
			name: 'weblink.xml',
			content: '<webLink xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imswl_v1p1">'
			. '<title>Docs</title><url href="https://example.org/a"/><url href="https://example.org/b"/></webLink>'
		);

		$this->assertSame('https://example.org/a', $this->reader->readAttribute(path: $path, tagName: 'url', attribute: 'href'));
	}//end testReadAttributeReturnsTheFirstElementsValue()

	/**
	 * A missing file resolves to null.
	 *
	 * @return void
	 */
	public function testReadAttributeOfMissingFileIsNull(): void {
		$this->assertNull($this->reader->readAttribute(path: $this->dir . '/absent.xml', tagName: 'url', attribute: 'href'));
	}//end testReadAttributeOfMissingFileIsNull()

	/**
	 * Malformed XML resolves to null and leaves no libxml errors behind.
	 *
	 * @return void
	 */
	public function testReadAttributeOfMalformedXmlIsNullAndClearsErrors(): void {
		$path = $this->fixture(name: 'broken.xml', content: '<webLink><url href="x"></webLink');

		$this->assertNull($this->reader->readAttribute(path: $path, tagName: 'url', attribute: 'href'));
		$this->assertSame([], libxml_get_errors());
	}//end testReadAttributeOfMalformedXmlIsNullAndClearsErrors()

	/**
	 * A document without the element resolves to null.
	 *
	 * @return void
	 */
	public function testReadAttributeWithoutTheElementIsNull(): void {
		$path = $this->fixture(name: 'other.xml', content: '<webLink><title>Docs</title></webLink>');

		$this->assertNull($this->reader->readAttribute(path: $path, tagName: 'url', attribute: 'href'));
	}//end testReadAttributeWithoutTheElementIsNull()

	/**
	 * An absent or empty attribute resolves to null.
	 *
	 * @return void
	 */
	public function testReadAttributeThatIsEmptyOrAbsentIsNull(): void {
		$path = $this->fixture(name: 'empty.xml', content: '<webLink><url href="" target="_blank"/></webLink>');

		$this->assertNull($this->reader->readAttribute(path: $path, tagName: 'url', attribute: 'href'));
		$this->assertNull($this->reader->readAttribute(path: $path, tagName: 'url', attribute: 'rel'));
		$this->assertSame('_blank', $this->reader->readAttribute(path: $path, tagName: 'url', attribute: 'target'));
	}//end testReadAttributeThatIsEmptyOrAbsentIsNull()

	/**
	 * Text content is trimmed and taken from the first matching element.
	 *
	 * @return void
	 */
	public function testReadTextContentIsTrimmed(): void {
		$path = $this->fixture(
			name: 'url.xml',
			content: "<activity><url><externalurl>\n  https://moodle.example/x  \n</externalurl>"
			. '<externalurl>https://second.example</externalurl></url></activity>'
		);

		$this->assertSame('https://moodle.example/x', $this->reader->readTextContent(path: $path, tagName: 'externalurl'));
	}//end testReadTextContentIsTrimmed()

	/**
	 * A missing file, malformed XML and a missing element all resolve to null.
	 *
	 * @return void
	 */
	public function testReadTextContentUnresolvableIsNull(): void {
		$broken = $this->fixture(name: 'broken.xml', content: '<activity><externalurl>x</activity>');
		$other = $this->fixture(name: 'other.xml', content: '<activity><name>N</name></activity>');

		$this->assertNull($this->reader->readTextContent(path: $this->dir . '/absent.xml', tagName: 'externalurl'));
		$this->assertNull($this->reader->readTextContent(path: $broken, tagName: 'externalurl'));
		$this->assertNull($this->reader->readTextContent(path: $other, tagName: 'externalurl'));
	}//end testReadTextContentUnresolvableIsNull()

	/**
	 * Whitespace-only text resolves to null.
	 *
	 * @return void
	 */
	public function testReadTextContentOfBlankElementIsNull(): void {
		$path = $this->fixture(name: 'blank.xml', content: "<activity><externalurl>  \n\t </externalurl></activity>");

		$this->assertNull($this->reader->readTextContent(path: $path, tagName: 'externalurl'));
	}//end testReadTextContentOfBlankElementIsNull()

	/**
	 * An external entity is not expanded into the value (XXE-safe load).
	 *
	 * @return void
	 */
	public function testExternalEntityIsNotExpanded(): void {
		$secret = $this->fixture(name: 'secret.txt', content: 'top-secret');
		$path = $this->fixture(
			name: 'xxe.xml',
			content: '<!DOCTYPE activity [<!ENTITY e SYSTEM "file://' . $secret . '">]>'
			. '<activity><externalurl>&e;</externalurl></activity>'
		);

		$this->assertNull($this->reader->readTextContent(path: $path, tagName: 'externalurl'));
	}//end testExternalEntityIsNotExpanded()
}//end class
