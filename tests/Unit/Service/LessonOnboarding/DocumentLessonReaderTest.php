<?php

/**
 * Unit tests for DocumentLessonReader and its use in OfficeLessonExtractor.
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\LessonOnboarding;

use OCA\Learniq\Service\LessonOnboarding\DocumentLessonReader;
use OCA\Learniq\Service\LessonOnboarding\DocxLessonReader;
use OCA\Learniq\Service\LessonOnboarding\OfficeLessonExtractor;
use OCA\Learniq\Service\LessonOnboarding\PresentationLessonReader;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use ZipArchive;

/**
 * Stands in for OpenRegister's DocumentExtractor (openregister #4111).
 */
class FakeDocumentExtractor {

	/**
	 * What extract() returns next, or the exception it throws.
	 *
	 * @var array<string, mixed>|RuntimeException|null
	 */
	public static array|RuntimeException|null $next = null;

	/**
	 * Mirror of DocumentExtractor::extract().
	 *
	 * @param File $file The document.
	 *
	 * @return array<string, mixed>|null
	 */
	public function extract(File $file): ?array {
		if (self::$next instanceof RuntimeException) {
			throw self::$next;
		}

		return self::$next;
	}//end extract()
}//end class

/**
 * DocumentExtractor first, DocxLessonReader when it is missing or reads nothing.
 */
class DocumentLessonReaderTest extends TestCase {

	/**
	 * A Word package holding one picture at word/media/image1.png.
	 *
	 * @return string The package bytes.
	 */
	private function package(): string {
		$path = tempnam(sys_get_temp_dir(), 'lq-docx-');
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		$zip->addFromString('word/document.xml', '<w:document/>');
		$zip->addFromString('word/media/image1.png', 'PNGBYTES');
		$zip->close();
		$bytes = (string)file_get_contents($path);
		unlink($path);
		return $bytes;
	}//end package()

	/**
	 * A file double with the package as its content.
	 *
	 * @return File
	 */
	private function file(): File {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($this->package());
		$file->method('getId')->willReturn(7);
		return $file;
	}//end file()

	/**
	 * The reader over the fake extractor, or over a class that does not exist.
	 *
	 * @param string $class The extractor class.
	 *
	 * @return DocumentLessonReader
	 */
	private function reader(string $class = FakeDocumentExtractor::class): DocumentLessonReader {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new FakeDocumentExtractor());
		return new DocumentLessonReader(container: $container, logger: new NullLogger(), extractorClass: $class);
	}//end reader()

	/**
	 * The extractor's sections become lesson sections: paragraphs, lists and
	 * tables as text, pictures with their bytes, linked pictures left out.
	 *
	 * @return void
	 */
	public function testSectionsBecomeALesson(): void {
		FakeDocumentExtractor::$next = [
			'title' => 'Fotosynthese',
			'truncated' => false,
			'text' => 'flat',
			'sections' => [
				['heading' => '', 'level' => 0, 'blocks' => [['type' => 'paragraph', 'text' => 'Inleiding']]],
				[
					'heading' => 'Stappen',
					'level' => 1,
					'blocks' => [
						['type' => 'list', 'items' => [['text' => 'Licht', 'level' => 0, 'ordered' => false], ['text' => 'Water', 'level' => 1, 'ordered' => true]]],
						['type' => 'table', 'rows' => [['Stof', 'Rol'], ['CO2', 'in|put']]],
						['type' => 'image', 'target' => 'word/media/image1.png', 'external' => false, 'name' => 'blad', 'description' => ''],
						['type' => 'image', 'target' => 'https://example.org/x.png', 'external' => true, 'name' => '', 'description' => ''],
					],
				],
				['heading' => '', 'level' => 0, 'blocks' => []],
			],
		];

		$read = $this->reader()->read(file: $this->file());

		self::assertTrue($read['available']);
		self::assertSame('Fotosynthese', $read['lesson']['title']);
		self::assertCount(2, $read['lesson']['sections']);
		self::assertSame(['Inleiding'], $read['lesson']['sections'][0]['paragraphs']);
		$steps = $read['lesson']['sections'][1];
		self::assertSame('Stappen', $steps['heading']);
		self::assertSame("- Licht\n  1. Water", $steps['paragraphs'][0]);
		self::assertSame("| Stof | Rol |\n| --- | --- |\n| CO2 | in\\|put |", $steps['paragraphs'][1]);
		self::assertSame([['name' => 'image1.png', 'bytes' => 'PNGBYTES']], $steps['images']);
		self::assertSame([], $read['lesson']['notes']);
	}//end testSectionsBecomeALesson()

	/**
	 * A cut-off document says so.
	 *
	 * @return void
	 */
	public function testATruncatedDocumentCarriesANote(): void {
		FakeDocumentExtractor::$next = ['title' => '', 'truncated' => true, 'sections' => [['heading' => 'A', 'level' => 1, 'blocks' => []]]];

		self::assertSame(['The document was cut off at the size limit'], $this->reader()->read(file: $this->file())['lesson']['notes']);
	}//end testATruncatedDocumentCarriesANote()

	/**
	 * Without the class, the reader is unavailable; a failing or empty read is
	 * available with no lesson.
	 *
	 * @return void
	 */
	public function testUnavailableFailingAndEmpty(): void {
		self::assertSame(['available' => false, 'lesson' => null], $this->reader(class: 'OCA\\OpenRegister\\NoSuchExtractor')->read(file: $this->file()));

		FakeDocumentExtractor::$next = new RuntimeException('broken');
		self::assertSame(['available' => true, 'lesson' => null], $this->reader()->read(file: $this->file()));

		FakeDocumentExtractor::$next = null;
		self::assertSame(['available' => true, 'lesson' => null], $this->reader()->read(file: $this->file()));
	}//end testUnavailableFailingAndEmpty()

	/**
	 * OfficeLessonExtractor uses DocumentExtractor's read, and does not run
	 * its own Word parser, when OpenRegister has the reader.
	 *
	 * @return void
	 */
	public function testTheExtractorPrefersOpenRegistersReader(): void {
		FakeDocumentExtractor::$next = ['title' => 'T', 'truncated' => false, 'sections' => [['heading' => 'A', 'level' => 1, 'blocks' => [['type' => 'paragraph', 'text' => 'x']]]]];
		$docx = $this->createMock(DocxLessonReader::class);
		$docx->expects($this->never())->method('read');

		$read = $this->extractor(docx: $docx, reader: $this->reader())->extract(file: $this->file(), format: 'docx');

		self::assertSame('ok', $read['status']);
		self::assertSame(['x'], $read['lesson']['sections'][0]['paragraphs']);
	}//end testTheExtractorPrefersOpenRegistersReader()

	/**
	 * Without OpenRegister's reader, or when it reads nothing, the extractor
	 * falls back to learniq's own DocxLessonReader.
	 *
	 * @return void
	 */
	public function testTheExtractorFallsBackToDocxLessonReader(): void {
		$lesson = ['title' => 'Eigen', 'sections' => [['heading' => 'B', 'paragraphs' => ['y'], 'images' => [], 'notes' => '']], 'notes' => []];
		$docx = $this->createMock(DocxLessonReader::class);
		$docx->method('read')->willReturn($lesson);

		$missing = $this->extractor(docx: $docx, reader: $this->reader(class: 'OCA\\OpenRegister\\NoSuchExtractor'));
		self::assertSame(['status' => 'ok', 'lesson' => $lesson], $missing->extract(file: $this->file(), format: 'docx'));

		FakeDocumentExtractor::$next = ['title' => '', 'truncated' => false, 'sections' => []];
		$empty = $this->extractor(docx: $docx, reader: $this->reader());
		self::assertSame(['status' => 'ok', 'lesson' => $lesson], $empty->extract(file: $this->file(), format: 'docx'));
	}//end testTheExtractorFallsBackToDocxLessonReader()

	/**
	 * An OfficeLessonExtractor over the given readers.
	 *
	 * @param DocxLessonReader $docx The own reader.
	 * @param DocumentLessonReader $reader The OpenRegister adapter.
	 *
	 * @return OfficeLessonExtractor
	 */
	private function extractor(DocxLessonReader $docx, DocumentLessonReader $reader): OfficeLessonExtractor {
		return new OfficeLessonExtractor(
			docxReader: $docx,
			presentationReader: $this->createMock(PresentationLessonReader::class),
			container: $this->createMock(ContainerInterface::class),
			logger: new NullLogger(),
			wordExtractorClass: 'OCA\\OpenRegister\\NoSuchWordExtractor',
			documentReader: $reader
		);
	}//end extractor()

	/**
	 * Reset the fake.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		FakeDocumentExtractor::$next = null;
		parent::tearDown();
	}//end tearDown()
}//end class
