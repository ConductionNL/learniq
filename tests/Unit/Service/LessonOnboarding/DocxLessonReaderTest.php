<?php

/**
 * Unit tests for DocxLessonReader, on docx packages built in the test.
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

use OCA\Learniq\Service\LessonOnboarding\DocxLessonReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Word structure: headings, paragraphs, lists, tables, images, title.
 */
class DocxLessonReaderTest extends TestCase {

	private const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
		. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
		. 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';

	/**
	 * Build a docx from parts and return its bytes.
	 *
	 * @param array<string, string> $parts Part name to content.
	 *
	 * @return string The package bytes.
	 */
	private static function docx(array $parts): string {
		$path = tempnam(sys_get_temp_dir(), 'docx');
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$bytes = (string)file_get_contents($path);
		unlink($path);
		return $bytes;

	}//end docx()

	/**
	 * A paragraph with an optional style, list marker and image.
	 *
	 * @param string $text The text.
	 * @param string|null $style The style id.
	 * @param bool $list Whether it is a list item.
	 * @param string|null $imageRel A relationship id for an embedded image.
	 *
	 * @return string The w:p XML.
	 */
	private static function p(string $text, ?string $style = null, bool $list = false, ?string $imageRel = null): string {
		$pPr = '';
		if ($style !== null || $list === true) {
			$pPr = '<w:pPr>'
				. ($style !== null ? '<w:pStyle w:val="' . $style . '"/>' : '')
				. ($list === true ? '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>' : '')
				. '</w:pPr>';
		}

		$image = '';
		if ($imageRel !== null) {
			$image = '<w:r><w:drawing><a:graphic><a:graphicData><a:blip r:embed="' . $imageRel . '"/></a:graphicData></a:graphic></w:drawing></w:r>';
		}

		return '<w:p>' . $pPr . '<w:r><w:t xml:space="preserve">' . htmlspecialchars($text) . '</w:t></w:r>' . $image . '</w:p>';

	}//end p()

	/**
	 * The parts of a lesson plan: a core title, lead text, two headings (one by a
	 * localised style whose name is `heading 2`), a list, a table and one image.
	 *
	 * @return array<string, string>
	 */
	private static function lessonPlan(): array {
		$body = self::p('Doel: breuken vergelijken.')
			. self::p('Start', 'Heading1')
			. self::p('Wat weten we al?')
			. self::p('Instructie', 'Kop2')
			. self::p('Leg uit met een getallenlijn.', null, false, 'rIdImg')
			. self::p('3/8', null, true)
			. self::p('5/8', null, true)
			. '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Breuk</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Groter?</w:t></w:r></w:p></w:tc></w:tr>'
			. '<w:tr><w:tc><w:p><w:r><w:t>5/8</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>ja</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
			. self::p('');

		return [
			'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document ' . self::W . '><w:body>' . $body . '</w:body></w:document>',
			'word/styles.xml' => '<?xml version="1.0"?><w:styles ' . self::W . '>'
				. '<w:style w:type="paragraph" w:styleId="Kop2"><w:name w:val="heading 2"/></w:style>'
				. '<w:style w:type="paragraph" w:styleId="Standaard"><w:name w:val="Normal"/></w:style></w:styles>',
			'word/_rels/document.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				. '<Relationship Id="rIdImg" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
				. '<Relationship Id="rIdExt" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="https://example.invalid/x.png" TargetMode="External"/>'
				. '</Relationships>',
			'docProps/core.xml' => '<?xml version="1.0"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Breuken vergelijken</dc:title></cp:coreProperties>',
			'word/media/image1.png' => 'PNGBYTES',
		];

	}//end lessonPlan()

	/**
	 * Headings split sections; lists, tables and images land in their section.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
	 */
	public function testHeadingsSplitSectionsAndContentStaysInPlace(): void {
		$lesson = (new DocxLessonReader())->read(content: self::docx(parts: self::lessonPlan()));

		$this->assertNotNull($lesson);
		$this->assertSame('Breuken vergelijken', $lesson['title']);
		$this->assertCount(3, $lesson['sections']);
		$this->assertSame('', $lesson['sections'][0]['heading']);
		$this->assertSame(['Doel: breuken vergelijken.'], $lesson['sections'][0]['paragraphs']);
		$this->assertSame('Start', $lesson['sections'][1]['heading']);
		$this->assertSame(['Wat weten we al?'], $lesson['sections'][1]['paragraphs']);

		$instructie = $lesson['sections'][2];
		$this->assertSame('Instructie', $instructie['heading']);
		$this->assertSame('Leg uit met een getallenlijn.', $instructie['paragraphs'][0]);
		$this->assertSame('- 3/8', $instructie['paragraphs'][1]);
		$this->assertSame('- 5/8', $instructie['paragraphs'][2]);
		$this->assertSame("| Breuk | Groter? |\n| --- | --- |\n| 5/8 | ja |", $instructie['paragraphs'][3]);
		$this->assertSame([['name' => 'image1.png', 'bytes' => 'PNGBYTES']], $instructie['images']);
		$this->assertSame([], $lesson['notes']);

	}//end testHeadingsSplitSectionsAndContentStaysInPlace()

	/**
	 * Without a core title, the first Title paragraph names the lesson and is not a section.
	 *
	 * @return void
	 */
	public function testTheFirstTitleParagraphNamesTheLesson(): void {
		$parts = self::lessonPlan();
		unset($parts['docProps/core.xml']);
		$parts['word/document.xml'] = '<?xml version="1.0"?><w:document ' . self::W . '><w:body>'
			. self::p('Fotosynthese', 'Title') . self::p('Planten maken voedsel.') . '</w:body></w:document>';

		$lesson = (new DocxLessonReader())->read(content: self::docx(parts: $parts));

		$this->assertSame('Fotosynthese', $lesson['title']);
		$this->assertSame([['heading' => '', 'paragraphs' => ['Planten maken voedsel.'], 'images' => [], 'notes' => '']], $lesson['sections']);

	}//end testTheFirstTitleParagraphNamesTheLesson()

	/**
	 * An outline level marks a heading even without a heading style.
	 *
	 * @return void
	 */
	public function testAnOutlineLevelMarksAHeading(): void {
		$parts = self::lessonPlan();
		$parts['word/document.xml'] = '<?xml version="1.0"?><w:document ' . self::W . '><w:body>'
			. '<w:p><w:pPr><w:outlineLvl w:val="0"/></w:pPr><w:r><w:t>Afsluiting</w:t></w:r></w:p>'
			. self::p('Terugblik.') . '</w:body></w:document>';

		$lesson = (new DocxLessonReader())->read(content: self::docx(parts: $parts));

		$this->assertSame('Afsluiting', $lesson['sections'][0]['heading']);

	}//end testAnOutlineLevelMarksAHeading()

	/**
	 * Not a zip, no document part, or a DOCTYPE: null, never an exception.
	 *
	 * @return void
	 */
	public function testUnreadableInputIsNull(): void {
		$reader = new DocxLessonReader();
		$this->assertNull($reader->read(content: 'not a zip'));
		$this->assertNull($reader->read(content: self::docx(parts: ['word/other.xml' => '<x/>'])));

		$hostile = self::lessonPlan();
		$hostile['word/document.xml'] = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa">]><w:document ' . self::W . '><w:body>'
			. self::p('&a;') . '</w:body></w:document>';
		$this->assertNull($reader->read(content: self::docx(parts: $hostile)));
		$this->assertSame(['word/document.xml'], $reader->refusedParts());

	}//end testUnreadableInputIsNull()

	/**
	 * An empty body reads as a structure without sections.
	 *
	 * @return void
	 */
	public function testAnEmptyBodyHasNoSections(): void {
		$parts = self::lessonPlan();
		$parts['word/document.xml'] = '<?xml version="1.0"?><w:document ' . self::W . '><w:body>' . self::p('') . '</w:body></w:document>';

		$lesson = (new DocxLessonReader())->read(content: self::docx(parts: $parts));

		$this->assertSame([], $lesson['sections']);

	}//end testAnEmptyBodyHasNoSections()
}//end class
