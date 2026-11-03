<?php

/**
 * Learniq Docx Package
 *
 * The parts of an opened Word package that DocxLessonReader reads besides the
 * body: XML parts within the size cap and without a DOCTYPE, the core title,
 * the heading styles, the image relationships and the image bytes. The parts
 * it refuses are kept by name.
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

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Reads the parts of one opened docx package, with the reader's guards.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
 */
final class DocxPackage {

	private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	private const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
	private const NS_DC = 'http://purl.org/dc/elements/1.1/';

	/**
	 * Parts larger than the cap, or with a DOCTYPE, seen so far (names only).
	 *
	 * @var list<string>
	 */
	private array $refused = [];

	/**
	 * Constructor.
	 *
	 * @param ZipArchive $zip The opened package.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ZipArchive $zip,
	) {
	}//end __construct()

	/**
	 * Names of the parts refused so far (too large or with a DOCTYPE).
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
	 */
	public function refused(): array {
		return $this->refused;
	}//end refused()

	/**
	 * Load one XML part, refusing an oversized part or one with a DOCTYPE.
	 *
	 * @param string $name The part name.
	 *
	 * @return DOMDocument|null The document, or null when absent or refused.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
	 */
	public function xml(string $name): ?DOMDocument {
		$xml = $this->partBytes(name: $name);
		if ($xml === null) {
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
	}//end xml()

	/**
	 * The bytes of an image part, or null when it is missing, empty or over the image cap.
	 *
	 * @param string $target The part name.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
	 */
	public function image(string $target): ?string {
		$stat = $this->zip->statName($target);
		if ($stat === false || $stat['size'] > DocxLessonReader::MAX_IMAGE_BYTES) {
			return null;
		}

		return self::nonEmpty(bytes: $this->zip->getFromName($target));
	}//end image()

	/**
	 * The document title from docProps/core.xml, or ''.
	 *
	 * @return string The title.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
	 */
	public function coreTitle(): string {
		$document = $this->xml(name: 'docProps/core.xml');
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
	 * Style ids that mark a title or a heading, from word/styles.xml: by style
	 * name (`Title`, `heading 1` to `heading 6`, stable across languages) and by
	 * the English style ids Word writes when the styles part is missing.
	 *
	 * @return array<string, string> Style id to `title` or `heading`.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
	 */
	public function headingStyles(): array {
		$styles = ['Title' => 'title'];
		for ($level = 1; $level <= 6; $level++) {
			$styles['Heading' . $level] = 'heading';
		}

		$document = $this->xml(name: 'word/styles.xml');
		if ($document === null) {
			return $styles;
		}

		return array_replace($styles, self::namedStyles(document: $document));
	}//end headingStyles()

	/**
	 * Relationship id to image part name, for the package's own image parts.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
	 */
	public function imageTargets(): array {
		$document = $this->xml(name: 'word/_rels/document.xml.rels');
		if ($document === null) {
			return [];
		}

		$targets = [];
		foreach ($document->getElementsByTagNameNS(self::NS_REL, 'Relationship') as $relationship) {
			$target = self::imageTarget(relationship: $relationship);
			if ($target !== null) {
				$targets[$relationship->getAttribute('Id')] = $target;
			}
		}

		return $targets;
	}//end imageTargets()

	/**
	 * The bytes of one part, or null when it is missing or empty; a part over
	 * the cap is recorded as refused and not read.
	 *
	 * @param string $name The part name.
	 *
	 * @return string|null
	 */
	private function partBytes(string $name): ?string {
		$stat = $this->zip->statName($name);
		if ($stat === false) {
			return null;
		}

		if ($stat['size'] > DocxLessonReader::MAX_PART_BYTES) {
			$this->refused[] = $name;
			return null;
		}

		return self::nonEmpty(bytes: $this->zip->getFromName($name));
	}//end partBytes()

	/**
	 * The bytes, or null when they could not be read or are empty.
	 *
	 * @param string|false $bytes What ZipArchive returned.
	 *
	 * @return string|null
	 */
	private static function nonEmpty(string|false $bytes): ?string {
		if ($bytes === false || $bytes === '') {
			return null;
		}

		return $bytes;
	}//end nonEmpty()

	/**
	 * The paragraph styles named `Title` or `heading 1` to `heading 6`, by id.
	 *
	 * @param DOMDocument $document The styles part.
	 *
	 * @return array<string, string>
	 */
	private static function namedStyles(DOMDocument $document): array {
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('w', self::NS_W);
		$styles = [];
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
	}//end namedStyles()

	/**
	 * The part name of an internal image relationship, or null for any other.
	 *
	 * @param DOMElement $relationship The Relationship element.
	 *
	 * @return string|null
	 */
	private static function imageTarget(DOMElement $relationship): ?string {
		if (str_ends_with($relationship->getAttribute('Type'), '/image') === false
			|| $relationship->getAttribute('TargetMode') === 'External'
		) {
			return null;
		}

		return self::packagePath(target: $relationship->getAttribute('Target'));
	}//end imageTarget()

	/**
	 * A relationship target as a part name under word/, or null when it climbs out of the package.
	 *
	 * @param string $target The Target attribute.
	 *
	 * @return string|null
	 */
	private static function packagePath(string $target): ?string {
		$target = ltrim($target, '/');
		if (str_starts_with($target, 'word/') === false) {
			$target = 'word/' . $target;
		}

		// A target that climbs out of the package is not an image part.
		if (str_contains($target, '..') === true) {
			return null;
		}

		return $target;
	}//end packagePath()
}//end class
