<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DOMDocument;
use DOMElement;
use OCA\Learniq\Service\MoodleBackupParser;
use OCA\Learniq\Service\MoodleQuizQuestionMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins childText() of MoodleBackupParser and MoodleQuizQuestionMapper: the
 * trimmed text of the first direct child with the tag, null when that child
 * is blank or there is none; later children and grandchildren are not read.
 */
class MoodleChildTextTest extends TestCase {

	/**
	 * Both classes.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function readers(): array {
		return [
			'backup parser' => [MoodleBackupParser::class],
			'quiz question mapper' => [MoodleQuizQuestionMapper::class],
		];
	}//end readers()

	/**
	 * Read childText() of the root of $xml.
	 *
	 * @param class-string $class The class.
	 * @param string $xml The XML.
	 * @param string $tag The tag asked for.
	 *
	 * @return string|null
	 */
	private function childText(string $class, string $xml, string $tag): ?string {
		$document = new DOMDocument();
		$document->loadXML($xml);
		$root = $document->documentElement;
		self::assertInstanceOf(DOMElement::class, $root);
		$instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		return (new ReflectionMethod($class, 'childText'))->invoke($instance, $root, $tag);
	}//end childText()

	/**
	 * The first direct child with the tag gives its trimmed text.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 *
	 * @dataProvider readers
	 */
	public function testTheFirstChildGivesItsTrimmedText(string $class): void {
		self::assertSame('Week 1', $this->childText(class: $class, xml: '<a>text<!-- c --><name>  Week 1 </name><name>Week 2</name></a>', tag: 'name'));
	}//end testTheFirstChildGivesItsTrimmedText()

	/**
	 * A blank first child is null, even when a later one has text.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 *
	 * @dataProvider readers
	 */
	public function testABlankFirstChildIsNull(string $class): void {
		self::assertNull($this->childText(class: $class, xml: '<a><name>   </name><name>Week 2</name></a>', tag: 'name'));
	}//end testABlankFirstChildIsNull()

	/**
	 * No direct child with the tag is null; a grandchild does not count.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 *
	 * @dataProvider readers
	 */
	public function testOnlyDirectChildrenCount(string $class): void {
		self::assertNull($this->childText(class: $class, xml: '<a><b><name>Deep</name></b></a>', tag: 'name'));
		self::assertNull($this->childText(class: $class, xml: '<a/>', tag: 'name'));
	}//end testOnlyDirectChildrenCount()
}//end class
