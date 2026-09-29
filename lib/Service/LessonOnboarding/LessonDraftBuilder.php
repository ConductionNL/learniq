<?php

/**
 * Learniq Lesson Draft Builder
 *
 * Turns a lesson structure (sections read from a Word or PowerPoint file)
 * into `Lesson.blocks` (office-file-lesson-onboarding): one `richText` block
 * per section that starts with the heading, a `media` block per image
 * Material after its section, and a `teacherNote` block for a slide's
 * speaker notes. Pure: no I/O, so the mapping is tested on its own.
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

/**
 * Maps sections to lesson blocks.
 */
class LessonDraftBuilder {

	/**
	 * The blocks for a lesson.
	 *
	 * @param list<array{heading: string, paragraphs: list<string>, notes?: string}> $sections The sections, in order.
	 * @param array<int, list<string>> $materialIds Per section index, the Material ids of its images.
	 *
	 * @return list<array<string, mixed>> Blocks with `blockId`, `type`, `order` and one payload each.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
	 */
	public function build(array $sections, array $materialIds = []): array {
		$blocks = [];
		foreach ($sections as $index => $section) {
			$text = $this->sectionMarkdown(heading: $section['heading'], paragraphs: $section['paragraphs']);
			if ($text !== '') {
				$blocks[] = ['type' => 'richText', 'text' => $text];
			}

			foreach (($materialIds[$index] ?? []) as $materialId) {
				$blocks[] = ['type' => 'media', 'materialId' => $materialId];
			}

			$notes = trim((string)($section['notes'] ?? ''));
			if ($notes !== '') {
				$blocks[] = ['type' => 'teacherNote', 'text' => $notes];
			}
		}

		$ordered = [];
		foreach ($blocks as $position => $block) {
			$ordered[] = array_merge(['blockId' => self::uuid(), 'order' => ($position + 1)], $block);
		}

		return $ordered;
	}//end build()

	/**
	 * A section as markdown: the heading as `## heading`, then its paragraphs.
	 * Consecutive list items stay one list; everything else is its own paragraph.
	 *
	 * @param string $heading The heading, or '' for untitled lead text.
	 * @param list<string> $paragraphs Paragraphs, `- ` list items and markdown tables.
	 *
	 * @return string The markdown, or '' when the section holds no text.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
	 */
	public function sectionMarkdown(string $heading, array $paragraphs): string {
		$markdown = '';
		$previousWasListItem = false;
		foreach ($paragraphs as $paragraph) {
			$isListItem = str_starts_with($paragraph, '- ');
			if ($markdown !== '' && $isListItem === true && $previousWasListItem === true) {
				$markdown .= "\n";
			} elseif ($markdown !== '') {
				$markdown .= "\n\n";
			}

			$markdown .= $paragraph;
			$previousWasListItem = $isListItem;
		}

		$heading = trim($heading);
		if ($heading === '') {
			return $markdown;
		}

		if ($markdown === '') {
			return '## ' . $heading;
		}

		return '## ' . $heading . "\n\n" . $markdown;
	}//end sectionMarkdown()

	/**
	 * A random UUID v4 for a block id.
	 *
	 * @return string
	 */
	private static function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end uuid()
}//end class
