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
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

use DOMDocument;
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
	private const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
	private const NS_DC = 'http://purl.org/dc/elements/1.1/';

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
	 * Read a docx from its bytes.
	 *
	 * @param string $content The file content.
	 *
	 * @return array|null The lesson structure, or null when this is not a readable docx.
	 *
	 * @psalm-return LessonStructure|null
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
	 */
	public function read(string $content): ?array {
		$this->refused = [];
		$this->imageCount = 0;
		$tempFile = tmpfile();
		if ($tempFile === false) {
			return null;
		}

		$zip = new ZipArchive();
		$opened = false;
		try {
			fwrite($tempFile, $content);
			$opened = ($zip->open(stream_get_meta_data($tempFile)['uri'], ZipArchive::RDONLY) === true);
			if ($opened === false) {
				return null;
			}

			return $this->readPackage(zip: $zip);
		} catch (Throwable $e) {
			return null;
		} finally {
			if ($opened === true) {
				$zip->close();
			}

			fclose($tempFile);
		}//end try
	}//end read()

	/**
	 * Names of parts refused during the last read (too large or with a DOCTYPE).
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
	 */
	public function refusedParts(): array {
		return $this->refused;
	}//end refusedParts()

	/**
	 * Walk the opened package.
	 *
	 * @param ZipArchive $zip The package.
	 *
	 * @return array|null
	 *
	 * @psalm-return LessonStructure|null
	 */
	private function readPackage(ZipArchive $zip): ?array {
		$document = $this->readXml(zip: $zip, name: 'word/document.xml');
		if ($document === null) {
			return null;
		}

		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('w', self::NS_W);
		$xpath->registerNamespace('r', self::NS_R);
		$xpath->registerNamespace('a', self::NS_A);

		$context = [
			'zip' => $zip,
			'xpath' => $xpath,
			'headingStyles' => $this->headingStyles(zip: $zip),
			'images' => $this->imageTargets(zip: $zip),
		];

		$state = [
			'title' => $this->coreTitle(zip: $zip),
			'sections' => [],
			'current' => ['heading' => '', 'paragraphs' => [], 'images' => [], 'notes' => ''],
		];

		$body = $xpath->query('/w:document/w:body')->item(0);
		if (($body instanceof DOMElement) === false) {
			return null;
		}

		$state = $this->walk(parent: $body, context: $context, state: $state);
		$state['sections'] = $this->pushSection(sections: $state['sections'], section: $state['current']);

		$notes = [];
		if ($this->imageCount >= self::MAX_IMAGES) {
			$notes[] = 'Only the first ' . self::MAX_IMAGES . ' images were taken';
		}

		return ['title' => $state['title'], 'sections' => $state['sections'], 'notes' => $notes];
	}//end readPackage()

	/**
	 * Walk the block-level children of a container (body, content control).
	 *
	 * @param DOMElement $parent The container.
	 * @param array<string, mixed> $context Package, XPath, heading styles, image targets.
	 * @param array<string, mixed> $state The title, finished sections and the open section.
	 *
	 * @return array<string, mixed> The updated state.
	 */
	private function walk(DOMElement $parent, array $context, array $state): array {
		foreach ($parent->childNodes as $child) {
			if (($child instanceof DOMElement) === false || $child->namespaceURI !== self::NS_W) {
				continue;
			}

			if ($child->localName === 'p') {
				$state = $this->paragraph(node: $child, context: $context, state: $state);
				continue;
			}

			if ($child->localName === 'tbl') {
				$table = $this->table(node: $child, xpath: $context['xpath']);
				if ($table !== '') {
					$state['current']['paragraphs'][] = $table;
				}

				continue;
			}

			if ($child->localName === 'sdt') {
				$content = $context['xpath']->query('w:sdtContent', $child)->item(0);
				if ($content instanceof DOMElement) {
					$state = $this->walk(parent: $content, context: $context, state: $state);
				}
			}
		}//end foreach

		return $state;
	}//end walk()

	/**
	 * Handle one paragraph: a heading opens a section, a Title names the
	 * lesson once, a list item becomes a `- ` line, images are collected.
	 *
	 * @param DOMElement $node The w:p element.
	 * @param array<string, mixed> $context Package, XPath, heading styles, image targets.
	 * @param array<string, mixed> $state The reading state.
	 *
	 * @return array<string, mixed> The updated state.
	 */
	private function paragraph(DOMElement $node, array $context, array $state): array {
		$xpath = $context['xpath'];
		$text = $this->text(node: $node, xpath: $xpath);
		$kind = $this->paragraphKind(node: $node, xpath: $xpath, headingStyles: $context['headingStyles']);

		foreach ($this->images(node: $node, context: $context) as $image) {
			$state['current']['images'][] = $image;
		}

		if ($text === '') {
			return $state;
		}

		if ($kind === 'title' && $state['title'] === '') {
			$state['title'] = $text;
			return $state;
		}

		if ($kind === 'heading' || $kind === 'title') {
			$state['sections'] = $this->pushSection(sections: $state['sections'], section: $state['current']);
			$state['current'] = ['heading' => $text, 'paragraphs' => [], 'images' => [], 'notes' => ''];
			return $state;
		}

		if ($kind === 'list') {
			$text = '- ' . $text;
		}

		$state['current']['paragraphs'][] = $text;
		return $state;
	}//end paragraph()

	/**
	 * Add a section to the list when it holds anything.
	 *
	 * @param list<array<string, mixed>> $sections Finished sections.
	 * @param array<string, mixed> $section The section to close.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function pushSection(array $sections, array $section): array {
		if ($section['heading'] !== '' || $section['paragraphs'] !== [] || $section['images'] !== []) {
			$sections[] = $section;
		}

		return $sections;
	}//end pushSection()

	/**
	 * Whether a paragraph is a title, a heading, a list item or plain text.
	 *
	 * @param DOMElement $node The w:p element.
	 * @param DOMXPath $xpath XPath over the document.
	 * @param array<string, string> $headingStyles Style id to `title` or `heading`.
	 *
	 * @return string `title`, `heading`, `list` or `text`.
	 */
	private function paragraphKind(DOMElement $node, DOMXPath $xpath, array $headingStyles): string {
		$styleId = (string)$xpath->evaluate('string(w:pPr/w:pStyle/@w:val)', $node);
		if (isset($headingStyles[$styleId]) === true) {
			return $headingStyles[$styleId];
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
	 * @param DOMNode $node The element.
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
	 * @param DOMXPath $xpath XPath over the document.
	 *
	 * @return string The markdown table, or '' for an empty table.
	 */
	private function table(DOMElement $node, DOMXPath $xpath): string {
		$rows = [];
		foreach ($xpath->query('w:tr', $node) as $row) {
			$cells = [];
			foreach ($xpath->query('w:tc', $row) as $cell) {
				$parts = [];
				foreach ($xpath->query('w:p', $cell) as $cellParagraph) {
					$parts[] = $this->text(node: $cellParagraph, xpath: $xpath);
				}

				$cellText = trim(implode(' ', array_filter($parts, static fn (string $part): bool => $part !== '')));
				$cells[] = str_replace(["\n", '|'], [' ', '\\|'], $cellText);
			}

			if (implode('', $cells) !== '') {
				$rows[] = $cells;
			}
		}

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
	}//end table()

	/**
	 * The images a paragraph embeds, with their bytes, up to the caps.
	 *
	 * @param DOMElement $node The w:p element.
	 * @param array<string, mixed> $context Package, XPath and image targets.
	 *
	 * @return list<array{name: string, bytes: string}>
	 */
	private function images(DOMElement $node, array $context): array {
		$images = [];
		foreach ($context['xpath']->query('.//a:blip/@r:embed', $node) as $attribute) {
			$target = ($context['images'][$attribute->nodeValue] ?? null);
			if ($target === null || $this->imageCount >= self::MAX_IMAGES) {
				continue;
			}

			$stat = $context['zip']->statName($target);
			if ($stat === false || $stat['size'] > self::MAX_IMAGE_BYTES) {
				continue;
			}

			$bytes = $context['zip']->getFromName($target);
			if ($bytes === false || $bytes === '') {
				continue;
			}

			$this->imageCount++;
			$images[] = ['name' => basename($target), 'bytes' => $bytes];
		}

		return $images;
	}//end images()

	/**
	 * Style ids that mark a title or a heading, from word/styles.xml: by style
	 * name (`Title`, `heading 1` to `heading 6`, stable across languages) and by
	 * the English style ids Word writes when the styles part is missing.
	 *
	 * @param ZipArchive $zip The package.
	 *
	 * @return array<string, string> Style id to `title` or `heading`.
	 */
	private function headingStyles(ZipArchive $zip): array {
		$styles = ['Title' => 'title'];
		for ($level = 1; $level <= 6; $level++) {
			$styles['Heading' . $level] = 'heading';
		}

		$document = $this->readXml(zip: $zip, name: 'word/styles.xml');
		if ($document === null) {
			return $styles;
		}

		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('w', self::NS_W);
		foreach ($xpath->query('/w:styles/w:style[@w:type="paragraph"]') as $style) {
			$styleId = (string)$xpath->evaluate('string(@w:styleId)', $style);
			$name = strtolower(trim((string)$xpath->evaluate('string(w:name/@w:val)', $style)));
			if ($name === 'title') {
				$styles[$styleId] = 'title';
				continue;
			}

			if (preg_match('/^heading\s*[1-6]$/', $name) === 1) {
				$styles[$styleId] = 'heading';
			}
		}

		return $styles;
	}//end headingStyles()

	/**
	 * Image relationship ids of the main document, mapped to their part names.
	 *
	 * @param ZipArchive $zip The package.
	 *
	 * @return array<string, string> Relationship id to part name (`word/media/image1.png`).
	 */
	private function imageTargets(ZipArchive $zip): array {
		$document = $this->readXml(zip: $zip, name: 'word/_rels/document.xml.rels');
		if ($document === null) {
			return [];
		}

		$targets = [];
		foreach ($document->getElementsByTagNameNS(self::NS_REL, 'Relationship') as $relationship) {
			if (str_ends_with($relationship->getAttribute('Type'), '/image') === false
				|| $relationship->getAttribute('TargetMode') === 'External'
			) {
				continue;
			}

			$target = ltrim($relationship->getAttribute('Target'), '/');
			if (str_starts_with($target, 'word/') === false) {
				$target = 'word/' . $target;
			}

			// A target that climbs out of the package is not an image part.
			if (str_contains($target, '..') === false) {
				$targets[$relationship->getAttribute('Id')] = $target;
			}
		}

		return $targets;
	}//end imageTargets()

	/**
	 * The document title from docProps/core.xml, or ''.
	 *
	 * @param ZipArchive $zip The package.
	 *
	 * @return string The title.
	 */
	private function coreTitle(ZipArchive $zip): string {
		$document = $this->readXml(zip: $zip, name: 'docProps/core.xml');
		if ($document === null) {
			return '';
		}

		$titles = $document->getElementsByTagNameNS(self::NS_DC, 'title');
		if ($titles->length === 0) {
			return '';
		}

		return trim((string)$titles->item(0)?->textContent);
	}//end coreTitle()

	/**
	 * Load one XML part, refusing an oversized part or one with a DOCTYPE.
	 *
	 * @param ZipArchive $zip The package.
	 * @param string $name The part name.
	 *
	 * @return DOMDocument|null The document, or null when absent or refused.
	 */
	private function readXml(ZipArchive $zip, string $name): ?DOMDocument {
		$stat = $zip->statName($name);
		if ($stat === false) {
			return null;
		}

		if ($stat['size'] > self::MAX_PART_BYTES) {
			$this->refused[] = $name;
			return null;
		}

		$xml = $zip->getFromName($name);
		if ($xml === false || $xml === '') {
			return null;
		}

		if (stripos($xml, '<!DOCTYPE') !== false) {
			$this->refused[] = $name;
			return null;
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $document->loadXML($xml, (LIBXML_NONET | LIBXML_COMPACT));
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($loaded === false) {
			return null;
		}

		return $document;
	}//end readXml()
}//end class
