<?php

/**
 * Unit tests for LessonDraftBuilder.
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
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\LessonOnboarding;

use OCA\Learniq\Service\LessonOnboarding\LessonDraftBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Sections to blocks.
 */
class LessonDraftBuilderTest extends TestCase {

	/**
	 * One text block per section, media after its section, notes as a teacherNote.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
	 */
	public function testSectionsBecomeOrderedBlocks(): void {
		$blocks = (new LessonDraftBuilder())->build(
			sections: [
				['heading' => 'Start', 'paragraphs' => ['Wat weten we al?']],
				['heading' => 'Instructie', 'paragraphs' => ['Uitleg.'], 'notes' => 'Wijs op de noemer.'],
				['heading' => '', 'paragraphs' => []],
			],
			materialIds: [1 => ['mat-1', 'mat-2']]
		);

		$this->assertSame(['richText', 'richText', 'media', 'media', 'teacherNote'], array_column($blocks, 'type'));
		$this->assertSame([1, 2, 3, 4, 5], array_column($blocks, 'order'));
		$this->assertSame("## Start\n\nWat weten we al?", $blocks[0]['text']);
		$this->assertSame('mat-1', $blocks[2]['materialId']);
		$this->assertSame('Wijs op de noemer.', $blocks[4]['text']);
		foreach ($blocks as $block) {
			$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $block['blockId']);
			$this->assertCount(4, $block, 'the required trio plus exactly one payload');
		}

		$this->assertCount(5, array_unique(array_column($blocks, 'blockId')));

	}//end testSectionsBecomeOrderedBlocks()

	/**
	 * List items stay one list; other paragraphs are separated by a blank line.
	 *
	 * @return void
	 */
	public function testListItemsStayTogether(): void {
		$markdown = (new LessonDraftBuilder())->sectionMarkdown(
			heading: '',
			paragraphs: ['Voorbeelden:', '- 3/8', '- 5/8', 'Klaar.']
		);

		$this->assertSame("Voorbeelden:\n\n- 3/8\n- 5/8\n\nKlaar.", $markdown);

	}//end testListItemsStayTogether()

	/**
	 * A heading without text is a heading block on its own; nothing at all is no block.
	 *
	 * @return void
	 */
	public function testHeadingOnlyAndEmptySections(): void {
		$builder = new LessonDraftBuilder();
		$this->assertSame('## Afsluiting', $builder->sectionMarkdown(heading: ' Afsluiting ', paragraphs: []));
		$this->assertSame('', $builder->sectionMarkdown(heading: '', paragraphs: []));
		$this->assertSame([], $builder->build(sections: [['heading' => '', 'paragraphs' => []]]));

	}//end testHeadingOnlyAndEmptySections()
}//end class
