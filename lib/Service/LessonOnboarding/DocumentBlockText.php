<?php

/**
 * Learniq Document Block Text
 *
 * Turns one text block of OpenRegister's DocumentExtractor into the paragraph
 * string a lesson section holds, the way DocxLessonReader writes it: a
 * paragraph as its text, a list as `- ` lines indented two spaces per level
 * (`1. ` for a numbered list), a table as a Markdown table.
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

/**
 * Text of a paragraph, list or table block.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
 */
class DocumentBlockText {

	/**
	 * The paragraph text of a block, '' for an empty or unknown block.
	 *
	 * @param array<string, mixed> $block The block.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
	 */
	public function textOf(array $block): string {
		$type = (string)($block['type'] ?? '');
		if ($type === 'paragraph') {
			return trim((string)($block['text'] ?? ''));
		}

		if ($type === 'list') {
			return $this->listText(items: (array)($block['items'] ?? []));
		}

		if ($type === 'table') {
			return $this->tableText(rows: (array)($block['rows'] ?? []));
		}

		return '';
	}//end textOf()

	/**
	 * A list as indented lines.
	 *
	 * @param array<int, mixed> $items The list items.
	 *
	 * @return string
	 */
	private function listText(array $items): string {
		$lines = [];
		foreach ($items as $item) {
			$text = trim((string)($item['text'] ?? ''));
			if (is_array($item) === false || $text === '') {
				continue;
			}

			$marker = '-';
			if (($item['ordered'] ?? false) === true) {
				$marker = '1.';
			}

			$lines[] = str_repeat('  ', max(0, (int)($item['level'] ?? 0))) . $marker . ' ' . $text;
		}

		return implode("\n", $lines);
	}//end listText()

	/**
	 * A table as Markdown, the first row as its header.
	 *
	 * @param array<int, mixed> $rows The rows of cell strings.
	 *
	 * @return string
	 */
	private function tableText(array $rows): string {
		$clean = [];
		foreach ($rows as $row) {
			$cells = array_map(static fn ($cell): string => str_replace(["\n", '|'], [' ', '\\|'], trim((string)$cell)), (array)$row);
			if (implode('', $cells) !== '') {
				$clean[] = $cells;
			}
		}

		if ($clean === []) {
			return '';
		}

		$width = max(array_map('count', $clean));
		$lines = [];
		foreach ($clean as $index => $cells) {
			$lines[] = '| ' . implode(' | ', array_pad($cells, $width, '')) . ' |';
			if ($index === 0) {
				$lines[] = '|' . str_repeat(' --- |', $width);
			}
		}

		return implode("\n", $lines);
	}//end tableText()
}//end class
