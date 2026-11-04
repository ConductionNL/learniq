<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\LessonOnboarding;

use OCA\Learniq\Service\LessonOnboarding\DocxLessonReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Pins read() and refusedParts() for Word packages that reach every branch of
 * the reader against golden/DocxLessonRead.json, case by case. With
 * RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\LessonOnboarding\DocxLessonReader
 * @covers \OCA\Learniq\Service\LessonOnboarding\DocxPackage
 */
class DocxLessonReaderGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/DocxLessonRead.json';

	private const NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
		. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
		. 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';

	private const IMAGE_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

	/**
	 * Every case reads as recorded.
	 *
	 * @return void
	 */
	public function testReadMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => $content) {
			$reader = new DocxLessonReader();
			$read = $reader->read($content);
			$actual[$name] = ['read' => self::printable(value: $read), 'refused' => $reader->refusedParts()];
		}

		$reused = new DocxLessonReader();
		$cases = self::cases();
		foreach (['doctype in document', 'not a zip', 'doctype in styles', 'empty string'] as $name) {
			$read = $reused->read($cases[$name]);
			$actual['reused reader: ' . $name] = ['read' => self::printable(value: $read), 'refused' => $reused->refusedParts()];
		}

		$actual = json_decode((string)json_encode($actual, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		self::assertSame(expected: array_keys($golden), actual: array_keys($actual));
		foreach ($golden as $name => $expected) {
			self::assertSame(expected: $expected, actual: $actual[$name], message: $name);
		}
	}//end testReadMatchesGolden()

	/**
	 * Image bytes as their length and md5, so the golden stays text.
	 *
	 * @param mixed $value The read result.
	 *
	 * @return mixed
	 */
	private static function printable(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value['sections'] as $s => $section) {
			foreach ($section['images'] as $i => $image) {
				$value['sections'][$s]['images'][$i]['bytes'] = strlen($image['bytes']) . ':' . md5($image['bytes']);
			}
		}

		return $value;
	}//end printable()

	/**
	 * The cases by name: package bytes.
	 *
	 * @return array<string, string>
	 */
	private static function cases(): array {
		$cases = [
			'not a zip' => 'plain text',
			'empty string' => '',
			'no document part' => self::zip(parts: ['word/styles.xml' => '<x/>']),
			'empty document part' => self::zip(parts: ['word/document.xml' => '']),
			'broken document xml' => self::zip(parts: ['word/document.xml' => '<w:document ' . self::NS . '><w:body>']),
			'doctype in document' => self::zip(parts: ['word/document.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "y">]><w:document ' . self::NS . '><w:body/></w:document>']),
			'no body' => self::zip(parts: ['word/document.xml' => '<?xml version="1.0"?><w:document ' . self::NS . '/>']),
			'empty body' => self::package(body: ''),
			'oversized document part' => self::zip(parts: ['word/document.xml' => str_repeat(' ', (DocxLessonReader::MAX_PART_BYTES + 1))]),
		];

		$cases['text runs, tabs and breaks'] = self::package(
			body: '<w:p><w:r><w:t>Een</w:t><w:tab/><w:t xml:space="preserve">  twee   drie </w:t><w:br/><w:t>vier</w:t><w:cr/><w:t>vijf</w:t></w:r></w:p>'
				. self::p(text: '')
				. '<w:p/>'
		);
		$cases['built-in styles and title twice'] = self::package(
			body: self::p(text: 'Les één', style: 'Title')
				. self::p(text: 'Inleiding')
				. self::p(text: 'Tweede titel', style: 'Title')
				. self::p(text: 'Kop drie', style: 'Heading3')
				. self::p(text: 'Kop zes', style: 'Heading6')
				. self::p(text: 'Geen kop', style: 'Heading7')
				. self::p(text: '', style: 'Heading1')
				. self::p(text: 'Slot')
		);
		$cases['core title wins over title style'] = self::package(
			body: self::p(text: 'Stijltitel', style: 'Title') . self::p(text: 'Tekst'),
			extra: ['docProps/core.xml' => self::core(title: '  Kerntitel  ')]
		);
		$cases['empty core title'] = self::package(
			body: self::p(text: 'Stijltitel', style: 'Title'),
			extra: ['docProps/core.xml' => self::core(title: '')]
		);
		$cases['core without title'] = self::package(
			body: self::p(text: 'Tekst'),
			extra: ['docProps/core.xml' => '<?xml version="1.0"?><cp:coreProperties xmlns:cp="urn:cp"/>']
		);
		$cases['doctype in core'] = self::package(
			body: self::p(text: 'Tekst'),
			extra: ['docProps/core.xml' => '<?xml version="1.0"?><!DOCTYPE c><cp:coreProperties xmlns:cp="urn:cp"/>']
		);
		$cases['named styles'] = self::package(
			body: self::p(text: 'Titel als kop', style: 'Title')
				. self::p(text: 'Titel via naam', style: 'Hoofdtitel')
				. self::p(text: 'Kop via naam', style: 'Kop1')
				. self::p(text: 'Kop met spatie', style: 'Kop4')
				. self::p(text: 'Kop zeven', style: 'Kop7')
				. self::p(text: 'Teken', style: 'Teken1')
				. self::p(text: 'Gewoon', style: 'Standaard'),
			extra: [
				'word/styles.xml' => '<?xml version="1.0"?><w:styles ' . self::NS . '>'
					. '<w:style w:type="paragraph" w:styleId="Hoofdtitel"><w:name w:val=" Title "/></w:style>'
					. '<w:style w:type="paragraph" w:styleId="Kop1"><w:name w:val="heading 1"/></w:style>'
					. '<w:style w:type="paragraph" w:styleId="Kop4"><w:name w:val="Heading4"/></w:style>'
					. '<w:style w:type="paragraph" w:styleId="Kop7"><w:name w:val="heading 7"/></w:style>'
					. '<w:style w:type="character" w:styleId="Teken1"><w:name w:val="heading 1"/></w:style>'
					. '<w:style w:type="paragraph" w:styleId="Standaard"><w:name w:val="Normal"/></w:style>'
					. '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="heading 1"/></w:style>'
					. '</w:styles>',
			]
		);
		$cases['doctype in styles'] = self::package(
			body: self::p(text: 'Kop', style: 'Heading1') . self::p(text: 'Tekst'),
			extra: ['word/styles.xml' => '<?xml version="1.0"?><!DOCTYPE s><w:styles ' . self::NS . '/>']
		);
		$outline = '';
		foreach (['0', '5', '6', '9', ''] as $level) {
			$outline .= '<w:p><w:pPr><w:outlineLvl w:val="' . $level . '"/></w:pPr><w:r><w:t>Niveau ' . $level . '</w:t></w:r></w:p>';
		}

		$cases['outline levels'] = self::package(body: $outline . self::p(text: 'Tekst'));
		$cases['lists'] = self::package(
			body: self::p(text: 'Eén', list: true) . self::p(text: 'Kop als lijst', style: 'Heading2', list: true) . self::p(text: '', list: true)
		);
		$cases['tables'] = self::package(
			body: '<w:tbl>'
				. '<w:tr><w:tc><w:p><w:r><w:t>A|B</w:t></w:r></w:p><w:p><w:r><w:t>regel</w:t><w:br/><w:t>twee</w:t></w:r></w:p></w:tc><w:tc><w:p/></w:tc></w:tr>'
				. '<w:tr><w:tc><w:p/></w:tc></w:tr>'
				. '<w:tr><w:tc><w:p><w:r><w:t>1</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>2</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>3</w:t></w:r></w:p></w:tc></w:tr>'
				. '</w:tbl>'
				. '<w:tbl><w:tr><w:tc><w:p/></w:tc></w:tr></w:tbl>'
				. '<w:tbl/>'
				. self::p(text: 'Na de tabel')
		);
		$cases['content controls'] = self::package(
			body: '<w:sdt><w:sdtContent>' . self::p(text: 'Kop in controle', style: 'Heading1') . '<w:sdt><w:sdtContent>' . self::p(text: 'Diep') . '</w:sdtContent></w:sdt></w:sdtContent></w:sdt>'
				. '<w:sdt><w:sdtPr/></w:sdt>'
				. '<w:bookmarkStart/>'
				. '<x:p xmlns:x="urn:other"><w:r><w:t>Vreemd</w:t></w:r></x:p>'
				. self::p(text: 'Einde')
		);
		$cases['images'] = self::package(
			body: self::p(text: 'Met plaatje', image: 'rIdOk')
				. self::p(text: '', image: 'rIdSlash')
				. self::p(text: 'Kop met plaatje', style: 'Heading1', image: 'rIdWord')
				. self::p(text: 'Extern', image: 'rIdExt')
				. self::p(text: 'Extern maar binnen', image: 'rIdExtIn')
				. self::p(text: 'Klimt binnen', image: 'rIdUpIn')
				. self::p(text: 'Klimt', image: 'rIdUp')
				. self::p(text: 'Geen beeld', image: 'rIdOther')
				. self::p(text: 'Ontbreekt', image: 'rIdGone')
				. self::p(text: 'Leeg', image: 'rIdEmpty')
				. self::p(text: 'Onbekend', image: 'rIdNone')
				. self::p(text: 'Te groot', image: 'rIdBig'),
			extra: [
				'word/_rels/document.xml.rels' => self::rels(
					rels: [
						'rIdOk' => ['media/a.png', ''],
						'rIdSlash' => ['/media/b.png', ''],
						'rIdWord' => ['word/media/c.png', ''],
						'rIdExt' => ['https://example.invalid/x.png', 'External'],
						'rIdExtIn' => ['media/a.png', 'External'],
						'rIdUpIn' => ['media/../d.png', ''],
						'rIdUp' => ['../secret.png', ''],
						'rIdGone' => ['media/missing.png', ''],
						'rIdEmpty' => ['media/empty.png', ''],
						'rIdBig' => ['media/big.png', ''],
					]
				) ,
				'word/media/a.png' => 'AAA',
				'word/media/b.png' => 'BBBB',
				'word/media/c.png' => 'CC',
				'word/media/empty.png' => '',
				'word/media/big.png' => str_repeat('x', (DocxLessonReader::MAX_IMAGE_BYTES + 1)),
				'secret.png' => 'S',
				'word/media/../d.png' => 'DDDDD',
			]
		);
		$many = '';
		for ($i = 0; $i < (DocxLessonReader::MAX_IMAGES + 2); $i++) {
			$many .= self::p(text: 'Beeld ' . $i, image: 'rIdOk');
		}

		$cases['more images than the cap'] = self::package(
			body: $many,
			extra: ['word/_rels/document.xml.rels' => self::rels(rels: ['rIdOk' => ['media/a.png', '']]), 'word/media/a.png' => 'AAA']
		);
		$cases['doctype in relationships'] = self::package(
			body: self::p(text: 'Plaatje', image: 'rIdOk'),
			extra: ['word/_rels/document.xml.rels' => '<?xml version="1.0"?><!DOCTYPE r><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>', 'word/media/a.png' => 'AAA']
		);
		$cases['image before a heading stays with the previous section'] = self::package(
			body: self::p(text: 'Kop A', style: 'Heading1') . self::p(text: '', image: 'rIdOk') . self::p(text: 'Kop B', style: 'Heading1'),
			extra: ['word/_rels/document.xml.rels' => self::rels(rels: ['rIdOk' => ['media/a.png', '']]), 'word/media/a.png' => 'AAA']
		);

		$cases['a section of images only'] = self::package(
			body: self::p(text: '', image: 'rIdOk'),
			extra: ['word/_rels/document.xml.rels' => self::rels(rels: ['rIdOk' => ['media/a.png', '']]), 'word/media/a.png' => 'AAA']
		);

		return $cases;
	}//end cases()

	/**
	 * A package with this body plus extra parts.
	 *
	 * @param string                $body  The w:body inner XML.
	 * @param array<string, string> $extra More parts.
	 *
	 * @return string
	 */
	private static function package(string $body, array $extra=[]): string {
		return self::zip(
			parts: array_merge(
				['word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document ' . self::NS . '><w:body>' . $body . '</w:body></w:document>'],
				$extra
			)
		);
	}//end package()

	/**
	 * A paragraph.
	 *
	 * @param string      $text  The text.
	 * @param string|null $style The style id.
	 * @param bool        $list  Whether it is a list item.
	 * @param string|null $image A relationship id of an embedded image.
	 *
	 * @return string
	 */
	private static function p(string $text, ?string $style=null, bool $list=false, ?string $image=null): string {
		$pPr = '';
		if ($style !== null || $list === true) {
			$pPr = '<w:pPr>';
			if ($style !== null) {
				$pPr .= '<w:pStyle w:val="' . $style . '"/>';
			}

			if ($list === true) {
				$pPr .= '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>';
			}

			$pPr .= '</w:pPr>';
		}

		$drawing = '';
		if ($image !== null) {
			$drawing = '<w:r><w:drawing><a:graphic><a:graphicData><a:blip r:embed="' . $image . '"/></a:graphicData></a:graphic></w:drawing></w:r>';
		}

		return '<w:p>' . $pPr . '<w:r><w:t xml:space="preserve">' . htmlspecialchars($text) . '</w:t></w:r>' . $drawing . '</w:p>';
	}//end p()

	/**
	 * A relationships part.
	 *
	 * @param array<string, array{0: string, 1: string}> $rels Id to target and target mode.
	 *
	 * @return string
	 */
	private static function rels(array $rels): string {
		$xml = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach ($rels as $id => [$target, $mode]) {
			$xml .= '<Relationship Id="' . $id . '" Type="' . self::IMAGE_TYPE . '" Target="' . $target . '"' . ($mode === '' ? '' : ' TargetMode="' . $mode . '"') . '/>';
		}

		$xml .= '<Relationship Id="rIdOther" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
		return $xml . '</Relationships>';
	}//end rels()

	/**
	 * A core properties part with this title.
	 *
	 * @param string $title The title.
	 *
	 * @return string
	 */
	private static function core(string $title): string {
		return '<?xml version="1.0"?><cp:coreProperties xmlns:cp="urn:cp" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>' . $title . '</dc:title></cp:coreProperties>';
	}//end core()

	/**
	 * Zip these parts and return the bytes.
	 *
	 * @param array<string, string> $parts Part name to content.
	 *
	 * @return string
	 */
	private static function zip(array $parts): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'docxg');
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$bytes = (string)file_get_contents($path);
		unlink($path);
		return $bytes;
	}//end zip()
}//end class
