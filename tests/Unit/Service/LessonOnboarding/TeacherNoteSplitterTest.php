<?php

/**
 * Unit tests for TeacherNoteSplitter: notes leave the lesson blocks with the
 * anchor and position the composer needs to put them back in place.
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
 * @spec openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-imported-slide-notes-land-in-the-staff-store
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\LessonOnboarding;

use OCA\Learniq\Service\LessonOnboarding\LessonDraftBuilder;
use OCA\Learniq\Service\LessonOnboarding\TeacherNoteSplitter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\LessonOnboarding\TeacherNoteSplitter
 * @uses   \OCA\Learniq\Service\LessonOnboarding\LessonDraftBuilder
 */
class TeacherNoteSplitterTest extends TestCase {

	/**
	 * Notes leave the blocks, each anchored to the learner block before it,
	 * numbered per anchor.
	 *
	 * @return void
	 */
	public function testNotesLeaveTheBlocksWithAnchorAndPosition(): void {
		$result = (new TeacherNoteSplitter())->split(
			[
				['blockId' => 'a', 'type' => 'richText', 'order' => 1, 'text' => 'Intro'],
				['blockId' => 'n1', 'type' => 'teacherNote', 'order' => 2, 'text' => 'Vraag naar de rol van licht.'],
				['blockId' => 'b', 'type' => 'media', 'order' => 3, 'materialId' => 'm-1'],
				['blockId' => 'n2', 'type' => 'teacherNote', 'order' => 4, 'text' => 'Laat de foto groot zien.'],
				['blockId' => 'n3', 'type' => 'teacherNote', 'order' => 5, 'text' => 'Sem heeft hier extra uitleg nodig.'],
			]
		);

		self::assertSame(['a', 'b'], array_column($result['blocks'], 'blockId'));
		self::assertSame(['blockId' => 'b', 'type' => 'media', 'order' => 3, 'materialId' => 'm-1'], $result['blocks'][1], 'learner blocks are untouched');
		self::assertSame(
			[
				['blockId' => 'n1', 'afterBlockId' => 'a', 'position' => 0, 'text' => 'Vraag naar de rol van licht.'],
				['blockId' => 'n2', 'afterBlockId' => 'b', 'position' => 0, 'text' => 'Laat de foto groot zien.'],
				['blockId' => 'n3', 'afterBlockId' => 'b', 'position' => 1, 'text' => 'Sem heeft hier extra uitleg nodig.'],
			],
			$result['notes']
		);
	}//end testNotesLeaveTheBlocksWithAnchorAndPosition()

	/**
	 * A note before the first block has no anchor; order decides, not list place.
	 *
	 * @return void
	 */
	public function testANoteFirstHasNoAnchorAndOrderDecides(): void {
		$result = (new TeacherNoteSplitter())->split(
			[
				['blockId' => 'a', 'type' => 'richText', 'order' => 2],
				['blockId' => 'n', 'type' => 'teacherNote', 'order' => 1, 'text' => 'Eerst de vraag stellen.'],
			]
		);

		self::assertSame('', $result['notes'][0]['afterBlockId']);
		self::assertSame(['a'], array_column($result['blocks'], 'blockId'));
	}//end testANoteFirstHasNoAnchorAndOrderDecides()

	/**
	 * A lesson without notes comes back as it was, and hasNotes() says so.
	 *
	 * @return void
	 */
	public function testALessonWithoutNotesIsUnchanged(): void {
		$splitter = new TeacherNoteSplitter();
		$blocks   = [['blockId' => 'a', 'type' => 'richText', 'order' => 1, 'text' => 'Intro']];

		self::assertFalse($splitter->hasNotes($blocks));
		self::assertSame(['blocks' => $blocks, 'notes' => []], $splitter->split($blocks));
		self::assertTrue($splitter->hasNotes([['type' => 'teacherNote']]));
		self::assertSame(['blocks' => [], 'notes' => []], $splitter->split(['not a block', null]));
	}//end testALessonWithoutNotesIsUnchanged()

	/**
	 * A note without a block id gets a stable one from its place.
	 *
	 * @return void
	 */
	public function testANoteWithoutAnIdGetsAStableOne(): void {
		$blocks = [
			['blockId' => 'a', 'type' => 'richText', 'order' => 1],
			['type' => 'teacherNote', 'order' => 2, 'text' => 'Zonder id.'],
		];
		$first  = (new TeacherNoteSplitter())->split($blocks);
		$second = (new TeacherNoteSplitter())->split($blocks);

		self::assertSame('note-2', $first['notes'][0]['blockId']);
		self::assertSame($first, $second);
	}//end testANoteWithoutAnIdGetsAStableOne()

	/**
	 * The draft builder's output for a slide with speaker notes splits into
	 * the slide block and one note after it.
	 *
	 * @return void
	 */
	public function testTheDraftBuildersSlideNotesSplitOut(): void {
		$blocks = (new LessonDraftBuilder())->build(
			[
				['heading' => 'Dia 1', 'paragraphs' => ['Licht en schaduw']],
				['heading' => 'Dia 3', 'paragraphs' => ['Proefje'], 'notes' => 'Vraag naar de rol van licht.'],
			]
		);

		$result = (new TeacherNoteSplitter())->split($blocks);

		self::assertSame(['richText', 'richText'], array_column($result['blocks'], 'type'));
		self::assertCount(1, $result['notes']);
		self::assertSame($result['blocks'][1]['blockId'], $result['notes'][0]['afterBlockId']);
		self::assertSame('Vraag naar de rol van licht.', $result['notes'][0]['text']);
	}//end testTheDraftBuildersSlideNotesSplitOut()
}//end class
