<?php

/**
 * Unit tests for DocumentBlockText: paragraph, list and table blocks as section text.
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

use OCA\Learniq\Service\LessonOnboarding\DocumentBlockText;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\LessonOnboarding\DocumentBlockText
 */
class DocumentBlockTextTest extends TestCase {

	/**
	 * A paragraph is its trimmed text.
	 *
	 * @return void
	 */
	public function testAParagraphIsItsTrimmedText(): void {
		self::assertSame('Licht en schaduw', (new DocumentBlockText())->textOf(['type' => 'paragraph', 'text' => "  Licht en schaduw \n"]));
	}//end testAParagraphIsItsTrimmedText()

	/**
	 * An unknown, typeless or empty block is empty text.
	 *
	 * @return void
	 */
	public function testAnUnknownOrEmptyBlockIsEmpty(): void {
		$text = new DocumentBlockText();

		self::assertSame('', $text->textOf(['type' => 'image', 'text' => 'not text']));
		self::assertSame('', $text->textOf([]));
		self::assertSame('', $text->textOf(['type' => 'paragraph']));
		self::assertSame('', $text->textOf(['type' => 'list']));
		self::assertSame('', $text->textOf(['type' => 'table']));
	}//end testAnUnknownOrEmptyBlockIsEmpty()

	/**
	 * A list is one line per item: `- ` or `1. `, indented two spaces per level.
	 *
	 * @return void
	 */
	public function testAListIsIndentedMarkedLines(): void {
		$result = (new DocumentBlockText())->textOf(
			[
				'type' => 'list',
				'items' => [
					['text' => ' Eerste ', 'level' => 0],
					['text' => 'Genummerd', 'level' => 1, 'ordered' => true],
					['text' => 'Diep', 'level' => 2, 'ordered' => 'yes'],
					['text' => 'Negatief', 'level' => -3],
				],
			]
		);

		self::assertSame("- Eerste\n  1. Genummerd\n    - Diep\n- Negatief", $result);
	}//end testAListIsIndentedMarkedLines()

	/**
	 * Empty and non-array list items are dropped.
	 *
	 * @return void
	 */
	public function testEmptyAndNonArrayListItemsAreDropped(): void {
		$result = (new DocumentBlockText())->textOf(
			['type' => 'list', 'items' => [['text' => '   '], 'loose string', ['level' => 1], ['text' => 'Blijft']]]
		);

		self::assertSame('- Blijft', $result);
	}//end testEmptyAndNonArrayListItemsAreDropped()

	/**
	 * A table is Markdown with the first row as its header, padded to the widest row.
	 *
	 * @return void
	 */
	public function testATableIsMarkdownPaddedToTheWidestRow(): void {
		$result = (new DocumentBlockText())->textOf(
			[
				'type' => 'table',
				'rows' => [
					['Begrip', 'Uitleg'],
					['Licht', "Straalt\nuit", 'extra'],
					['Pijp | teken'],
				],
			]
		);

		self::assertSame(
			"| Begrip | Uitleg |  |\n| --- | --- | --- |\n| Licht | Straalt uit | extra |\n| Pijp \\| teken |  |  |",
			$result
		);
	}//end testATableIsMarkdownPaddedToTheWidestRow()

	/**
	 * Rows with only blank cells are dropped; a table of only those is empty.
	 *
	 * @return void
	 */
	public function testBlankRowsAreDropped(): void {
		$text = new DocumentBlockText();

		self::assertSame('', $text->textOf(['type' => 'table', 'rows' => [[' ', ''], []]]));
		self::assertSame("| Kop |\n| --- |\n| Rij |", $text->textOf(['type' => 'table', 'rows' => [['', ' '], ['Kop'], ['  '], ['Rij']]]));
	}//end testBlankRowsAreDropped()
}//end class
