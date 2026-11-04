<?php

/**
 * Learniq Docx Lesson Reader
 *
 * Reads a Word document (docx) into the structure a lesson draft needs
 * (office-file-lesson-onboarding): a title, and sections that each start at a
 * heading and hold the paragraphs, list items, tables and images under it.
 *
 * OpenRegister's WordExtractor returns one flat string for search, so heading
 * levels and images are lost there. This reader opens the package with
 * ZipArchive and DOMDocument, the technique OpenRegister's
 * PresentationExtractor uses, with the same guards: a size cap per part, no
 * DOCTYPE (no entity expansion, no external entities), and zip entries read by
 * name, never extracted to disk. It returns null for anything it cannot read,
 * and never logs document text.
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

use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;
use ZipArchive;

/**
 * Turns a docx package into a lesson structure.
 *
 * @psalm-type LessonImage = array{name: string, bytes: string}
 * @psalm-type LessonSection = array{heading: string, paragraphs: list<string>, images: list<LessonImage>, notes: string}
 * @psalm-type LessonStructure = array{title: string, sections: list<LessonSection>, notes: list<string>}
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One class per format keeps the whole docx walk readable in one place.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
 */
class DocxLessonReader {

	/**
	 * The most bytes read from one XML part (20 MiB), as in PresentationExtractor.
	 *
	 * @var int
	 */
	public const MAX_PART_BYTES = 20971520;

	/**
	 * The most bytes read from one image (10 MiB); a larger image is left out.
	 *
	 * @var int
	 */
	public const MAX_IMAGE_BYTES = 10485760;

	/**
	 * The most images taken from one document.
	 *
	 * @var int
	 */
	public const MAX_IMAGES = 50;

	private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

	/**
	 * Parts larger than the cap, or with a DOCTYPE, seen while reading (names only).
	 *
	 * @var list<string>
	 */
	private array $refused = [];

	/**
	 * Images taken so far from the current document.
	 *
	 * @var int
	 */
	private int $imageCount = 0;

	/**
	 * The open package and what was read from it once: `package`, `xpath`,
	 * `headingStyles` and `images` (relationship id to part name).
	 *
	 * @var array<string, mixed>
	 */
	private array $context = [];

	/**
	 * The lesson read so far: `title`, the finished `sections` and the `current` one.
	 *
	 * @var array<string, mixed>
	 */
	private array $state = [];

	/**
	 * Read a docx from its bytes.
	 *
	 * @param string $content The file content.
	 *
	 * @return array|null The lesson structure, or null when this is not a readable docx.
	 *
	 * @psalm-return LessonStructure|null
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
	 */
	public function read(string $content): ?array {
		$this->refused = [];
		$this->imageCount = 0;
		$tempFile = tmpfile();
		if ($tempFile === false) {
			return null;
		}

		try {
			fwrite($tempFile, $content);
			return $this->readFile(path: stream_get_meta_data($tempFile)['uri']);
		} catch (Throwable $e) {
			return null;
		} finally {
			fclose($tempFile);
		}
	}//end read()

	/**
	 * Names of parts refused during the last read (too large or with a DOCTYPE).
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
	 */
	public function refusedParts(): array {
		return $this->refused;
	}//end refusedParts()

	/**
	 * Open the package at this path and read it; the package is closed again.
	 *
	 * @param string $path The temporary file.
	 *
	 * @return array|null
	 *
	 * @psalm-return LessonStructure|null
	 */
	private function readFile(string $path): ?array {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			return null;
		}

		$package = new DocxPackage(zip: $zip);
		try {
			return $this->readPackage(package: $package);
		} finally {
			$this->refused = $package->refused();
			$zip->close();
			$this->context = [];
			$this->state = [];
		}
	}//end readFile()

	/**
	 * Walk the opened package.
	 *
	 * @param DocxPackage $package The package.
	 *
	 * @return array|null
	 *
	 * @psalm-return LessonStructure|null
	 */
	private function readPackage(DocxPackage $package): ?array {
		$document = $package->xml(name: 'word/document.xml');
		if ($document === null) {
			return null;
		}

		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('w', self::NS_W);
		$xpath->registerNamespace('r', self::NS_R);
		$xpath->registerNamespace('a', self::NS_A);

		$this->context = [
			'package' => $package,
			'xpath' => $xpath,
			'headingStyles' => $package->headingStyles(),
			'images' => $package->imageTargets(),
		];

		$this->state = [
			'title' => $package->coreTitle(),
			'sections' => [],
			'current' => self::section(heading: ''),
		];

		$body = $xpath->query('/w:document/w:body')->item(0);
		if (($body instanceof DOMElement) === false) {
			return null;
		}

		$this->walk(parent: $body);
		$this->pushSection();

		$notes = [];
		if ($this->imageCount >= self::MAX_IMAGES) {
			$notes[] = 'Only the first ' . self::MAX_IMAGES . ' images were taken';
		}

		return ['title' => $this->state['title'], 'sections' => $this->state['sections'], 'notes' => $notes];
	}//end readPackage()

	/**
	 * An empty section under this heading.
	 *
	 * @param string $heading The heading.
	 *
	 * @return array{heading: string, paragraphs: list<string>, images: list<array{name: string, bytes: string}>, notes: string}
	 */
	private static function section(string $heading): array {
		return ['heading' => $heading, 'paragraphs' => [], 'images' => [], 'notes' => ''];
	}//end section()

	/**
	 * Walk the block-level children of a container (body, content control).
	 *
	 * @param DOMElement $parent The container.
	 *
	 * @return void
	 */
	private function walk(DOMElement $parent): void {
		foreach ($parent->childNodes as $child) {
			if (($child instanceof DOMElement) === true && $child->namespaceURI === self::NS_W) {
				$this->block(node: $child);
			}
		}
	}//end walk()

	/**
	 * Read one block: a paragraph, a table, or a content control's content.
	 *
	 * @param DOMElement $node The block.
	 *
	 * @return void
	 */
	private function block(DOMElement $node): void {
		match ($node->localName) {
			'p' => $this->paragraph(node: $node),
			'tbl' => $this->tableParagraph(node: $node),
			'sdt' => $this->contentControl(node: $node),
			default => null,
		};
	}//end block()

	/**
	 * A table becomes one Markdown paragraph of the current section.
	 *
	 * @param DOMElement $node The w:tbl element.
	 *
	 * @return void
	 */
	private function tableParagraph(DOMElement $node): void {
		$table = $this->table(node: $node);
		if ($table !== '') {
			$this->state['current']['paragraphs'][] = $table;
		}
	}//end tableParagraph()

	/**
	 * A content control's blocks are read as if they stood in its place.
	 *
	 * @param DOMElement $node The w:sdt element.
	 *
	 * @return void
	 */
	private function contentControl(DOMElement $node): void {
		$content = $this->context['xpath']->query('w:sdtContent', $node)->item(0);
		if ($content instanceof DOMElement) {
			$this->walk(parent: $content);
		}
	}//end contentControl()

	/**
	 * Handle one paragraph: a heading opens a section, a Title names the
	 * lesson once, a list item becomes a `- ` line, images are collected.
	 *
	 * @param DOMElement $node The w:p element.
	 *
	 * @return void
	 */
	private function paragraph(DOMElement $node): void {
		$text = $this->text(node: $node, xpath: $this->context['xpath']);
		$kind = $this->paragraphKind(node: $node);
		$this->takeImages(node: $node);

		if ($text === '') {
			return;
		}

		if ($this->takesTitle(text: $text, kind: $kind) === true) {
			return;
		}

		if ($kind === 'heading' || $kind === 'title') {
			$this->pushSection();
			$this->state['current'] = self::section(heading: $text);
			return;
		}

		$this->state['current']['paragraphs'][] = self::line(text: $text, kind: $kind);
	}//end paragraph()

	/**
	 * The first Title paragraph names the lesson when nothing named it yet.
	 *
	 * @param string $text The paragraph text.
	 * @param string $kind The paragraph kind.
	 *
	 * @return bool Whether the text became the title.
	 */
	private function takesTitle(string $text, string $kind): bool {
		if ($kind !== 'title' || $this->state['title'] !== '') {
			return false;
		}

		$this->state['title'] = $text;
		return true;
	}//end takesTitle()

	/**
	 * A text paragraph as it is kept: a list item gets a Markdown dash.
	 *
	 * @param string $text The text.
	 * @param string $kind The paragraph kind.
	 *
	 * @return string
	 */
	private static function line(string $text, string $kind): string {
		if ($kind === 'list') {
			return '- ' . $text;
		}

		return $text;
	}//end line()

	/**
	 * Add the open section to the finished ones when it holds anything.
	 *
	 * @return void
	 */
	private function pushSection(): void {
		$section = $this->state['current'];
		if ($section['heading'] !== '' || $section['paragraphs'] !== [] || $section['images'] !== []) {
			$this->state['sections'][] = $section;
		}
	}//end pushSection()

	/**
	 * Whether a paragraph is a title, a heading, a list item or plain text.
	 *
	 * @param DOMElement $node The w:p element.
	 *
	 * @return string `title`, `heading`, `list` or `text`.
	 */
	private function paragraphKind(DOMElement $node): string {
		$xpath = $this->context['xpath'];
		$styleId = (string)$xpath->evaluate('string(w:pPr/w:pStyle/@w:val)', $node);
		if (isset($this->context['headingStyles'][$styleId]) === true) {
			return $this->context['headingStyles'][$styleId];
		}

		$outline = (string)$xpath->evaluate('string(w:pPr/w:outlineLvl/@w:val)', $node);
		if ($outline !== '' && (int)$outline <= 5) {
			return 'heading';
		}

		if ($xpath->query('w:pPr/w:numPr', $node)->length > 0) {
			return 'list';
		}

		return 'text';
	}//end paragraphKind()

	/**
	 * The text of a paragraph or cell: text runs, tabs as spaces, breaks as new lines.
	 *
	 * @param DOMNode  $node  The element.
	 * @param DOMXPath $xpath XPath over the document.
	 *
	 * @return string The trimmed text.
	 */
	private function text(DOMNode $node, DOMXPath $xpath): string {
		$text = '';
		foreach ($xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $node) as $part) {
			if ($part->localName === 't') {
				$text .= $part->textContent;
				continue;
			}

			if ($part->localName === 'tab') {
				$text .= ' ';
				continue;
			}

			$text .= "\n";
		}

		return trim(preg_replace('/[ \t]+/', ' ', $text) ?? $text);
	}//end text()

	/**
	 * A table as a markdown table: the first row as the header.
	 *
	 * @param DOMElement $node The w:tbl element.
	 *
	 * @return string The markdown table, or '' for an empty table.
	 */
	private function table(DOMElement $node): string {
		$rows = [];
		foreach ($this->context['xpath']->query('w:tr', $node) as $row) {
			$cells = $this->cells(row: $row);
			if (implode('', $cells) !== '') {
				$rows[] = $cells;
			}
		}

		return self::markdown(rows: $rows);
	}//end table()

	/**
	 * The cell texts of one table row, escaped for Markdown.
	 *
	 * @param DOMNode $row The w:tr element.
	 *
	 * @return list<string>
	 */
	private function cells(DOMNode $row): array {
		$xpath = $this->context['xpath'];
		$cells = [];
		foreach ($xpath->query('w:tc', $row) as $cell) {
			$parts = [];
			foreach ($xpath->query('w:p', $cell) as $cellParagraph) {
				$parts[] = $this->text(node: $cellParagraph, xpath: $xpath);
			}

			$cellText = trim(implode(' ', array_filter($parts, static fn (string $part): bool => $part !== '')));
			$cells[] = str_replace(["\n", '|'], [' ', '\\|'], $cellText);
		}

		return $cells;
	}//end cells()

	/**
	 * Rows as a Markdown table whose first row is the header; '' for no rows.
	 *
	 * @param list<list<string>> $rows The rows.
	 *
	 * @return string
	 */
	private static function markdown(array $rows): string {
		if ($rows === []) {
			return '';
		}

		$width = max(array_map('count', $rows));
		$lines = [];
		foreach ($rows as $index => $cells) {
			$cells = array_pad($cells, $width, '');
			$lines[] = '| ' . implode(' | ', $cells) . ' |';
			if ($index === 0) {
				$lines[] = '|' . str_repeat(' --- |', $width);
			}
		}

		return implode("\n", $lines);
	}//end markdown()

	/**
	 * Add the images embedded in a paragraph to the current section, within the caps.
	 *
	 * @param DOMElement $node The w:p element.
	 *
	 * @return void
	 */
	private function takeImages(DOMElement $node): void {
		foreach ($this->context['xpath']->query('.//a:blip/@r:embed', $node) as $attribute) {
			if (isset($this->context['images'][$attribute->nodeValue]) === false || $this->imageCount >= self::MAX_IMAGES) {
				continue;
			}

			$target = $this->context['images'][$attribute->nodeValue];
			$bytes = $this->context['package']->image(target: $target);
			if ($bytes === null) {
				continue;
			}

			$this->imageCount++;
			$this->state['current']['images'][] = ['name' => basename($target), 'bytes' => $bytes];
		}
	}//end takeImages()
}//end class
