<?php

/**
 * Learniq Teacher Note Splitter
 *
 * Separates teacher notes from a lesson's blocks (teacher-notes-protection).
 * A lesson is readable by every signed-in user, so it may carry only
 * learner-facing blocks; a note goes to the staff-only LessonTeacherNote
 * schema instead, anchored to the block it followed.
 *
 * The input is a block list in the shape the draft builder produces and older
 * lessons still hold: notes as `teacherNote` blocks between the others. The
 * output keeps every other block untouched, in order, and turns each note into
 * `{blockId, afterBlockId, position, text}`:
 *
 *   - `afterBlockId` is the nearest preceding learner block, or '' when the
 *     note comes before the first one;
 *   - `position` counts the notes after that same block, from 0.
 *
 * Pure: no dependencies, so the onboarding importer and the upgrade step share
 * one tested rule.
 *
 * @category Service
 * @package  OCA\Learniq\Service\LessonOnboarding
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
 * @spec openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-imported-slide-notes-land-in-the-staff-store
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

/**
 * Splits a block list into learner blocks and anchored teacher notes.
 */
class TeacherNoteSplitter {

	/**
	 * The block type that marks a teacher note.
	 */
	public const NOTE_TYPE = 'teacherNote';

	/**
	 * Whether a block list holds at least one teacher note.
	 *
	 * @param array<int, mixed> $blocks The blocks.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-an-upgrade-moves-the-notes-lessons-already-hold
	 */
	public function hasNotes(array $blocks): bool {
		foreach ($blocks as $block) {
			if (is_array($block) === true && ($block['type'] ?? null) === self::NOTE_TYPE) {
				return true;
			}
		}

		return false;

	}//end hasNotes()

	/**
	 * Split blocks into the learner blocks and the notes.
	 *
	 * Blocks are read in their `order` (then list order); the learner blocks
	 * come back in list order with their own fields unchanged. A note without
	 * a `blockId` gets one derived from its lesson position, so a rerun of the
	 * upgrade step recognises it.
	 *
	 * @param array<int, mixed> $blocks The blocks, notes included.
	 *
	 * @return array{blocks: list<array<string, mixed>>, notes: list<array{blockId: string, afterBlockId: string, position: int, text: string}>}
	 *
	 * @spec openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-imported-slide-notes-land-in-the-staff-store
	 */
	public function split(array $blocks): array {
		$ordered = $this->ordered(blocks: $blocks);

		$learnerBlocks = [];
		$notes         = [];
		$anchor        = '';
		$positions     = [];
		foreach ($ordered as $index => $block) {
			if (($block['type'] ?? null) !== self::NOTE_TYPE) {
				$learnerBlocks[] = $block;
				$anchor          = (string)($block['blockId'] ?? '');
				continue;
			}

			$position           = ($positions[$anchor] ?? 0);
			$positions[$anchor] = ($position + 1);
			$notes[]            = [
				'blockId'      => $this->noteId(block: $block, index: $index),
				'afterBlockId' => $anchor,
				'position'     => $position,
				'text'         => (string)($block['text'] ?? ''),
			];
		}

		return ['blocks' => $learnerBlocks, 'notes' => $notes];

	}//end split()

	/**
	 * Only array blocks, stably sorted by `order` (missing order keeps list place).
	 *
	 * @param array<int, mixed> $blocks The blocks.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function ordered(array $blocks): array {
		$indexed = [];
		foreach (array_values($blocks) as $index => $block) {
			if (is_array($block) === true) {
				$indexed[] = ['order' => (int)($block['order'] ?? ($index + 1)), 'index' => $index, 'block' => $block];
			}
		}

		usort(
			$indexed,
			static fn (array $left, array $right): int => [$left['order'], $left['index']] <=> [$right['order'], $right['index']]
		);

		return array_column($indexed, 'block');

	}//end ordered()

	/**
	 * The note's stable id: its block id, or one derived from its place.
	 *
	 * @param array<string, mixed> $block The note block.
	 * @param int                  $index Its place in the ordered list.
	 *
	 * @return string
	 */
	private function noteId(array $block, int $index): string {
		$blockId = trim((string)($block['blockId'] ?? ''));
		if ($blockId !== '') {
			return mb_substr($blockId, 0, 64);
		}

		return 'note-' . ($index + 1);

	}//end noteId()
}//end class
