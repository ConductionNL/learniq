<?php

/**
 * Unit tests for OfficeLessonExtractor.
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
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\LessonOnboarding;

use OCA\Learniq\Service\LessonOnboarding\DocumentLessonReader;
use OCA\Learniq\Service\LessonOnboarding\DocxLessonReader;
use OCA\Learniq\Service\LessonOnboarding\OfficeLessonExtractor;
use OCA\Learniq\Service\LessonOnboarding\PresentationLessonReader;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stands in for OpenRegister's WordExtractor.
 */
class FakeWordExtractor {

	/**
	 * What extract() returns next.
	 *
	 * @var string|null
	 */
	public static ?string $next = null;

	/**
	 * Mirror of WordExtractor::extract().
	 *
	 * @param File $file The document.
	 *
	 * @return string|null
	 */
	public function extract(File $file): ?string {
		return self::$next;
	}//end extract()
}//end class

/**
 * Format dispatch and the Word fallback.
 */
class OfficeLessonExtractorTest extends TestCase {

	/** @var DocxLessonReader&MockObject */
	private DocxLessonReader $docx;

	/** @var PresentationLessonReader&MockObject */
	private PresentationLessonReader $presentation;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->docx = $this->createMock(DocxLessonReader::class);
		$this->presentation = $this->createMock(PresentationLessonReader::class);

	}//end setUp()

	/**
	 * The extractor over the doubles.
	 *
	 * @param string $wordClass The fallback class.
	 *
	 * @return OfficeLessonExtractor
	 */
	private function extractor(string $wordClass = FakeWordExtractor::class): OfficeLessonExtractor {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new FakeWordExtractor());
		return new OfficeLessonExtractor(
			docxReader: $this->docx,
			presentationReader: $this->presentation,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			documentReader: new DocumentLessonReader(container: $container, logger: $this->createMock(LoggerInterface::class), extractorClass: 'OCA\\OpenRegister\\NoSuchDocumentExtractor'),
			wordExtractorClass: $wordClass
		);

	}//end extractor()

	/**
	 * A structured docx read is used as it is.
	 *
	 * @return void
	 */
	public function testAStructuredDocxIsUsed(): void {
		$lesson = ['title' => 'T', 'sections' => [['heading' => 'A', 'paragraphs' => [], 'images' => [], 'notes' => '']], 'notes' => []];
		$this->docx->method('read')->willReturn($lesson);

		$this->assertSame(['status' => 'ok', 'lesson' => $lesson], $this->extractor()->extract(file: $this->createMock(File::class), format: 'docx'));

	}//end testAStructuredDocxIsUsed()

	/**
	 * A docx without structured text falls back to WordExtractor's flat text.
	 *
	 * @return void
	 */
	public function testAnEmptyStructureFallsBackToFlatText(): void {
		$this->docx->method('read')->willReturn(['title' => 'Oud', 'sections' => [], 'notes' => []]);
		FakeWordExtractor::$next = "Regel een\n\n Regel twee \n";

		$read = $this->extractor()->extract(file: $this->createMock(File::class), format: 'docx');

		$this->assertSame('ok', $read['status']);
		$this->assertSame('Oud', $read['lesson']['title']);
		$this->assertSame(['Regel een', 'Regel twee'], $read['lesson']['sections'][0]['paragraphs']);
		$this->assertCount(1, $read['lesson']['notes']);

	}//end testAnEmptyStructureFallsBackToFlatText()

	/**
	 * No structure and no fallback class: unreadable.
	 *
	 * @return void
	 */
	public function testNoStructureAndNoFallbackIsUnreadable(): void {
		$this->docx->method('read')->willReturn(null);

		$read = $this->extractor(wordClass: 'OCA\\OpenRegister\\NoSuchWordExtractor')->extract(file: $this->createMock(File::class), format: 'docx');

		$this->assertSame(['status' => 'unreadable', 'lesson' => null], $read);

	}//end testNoStructureAndNoFallbackIsUnreadable()

	/**
	 * pptx follows the presentation reader: unavailable, unreadable or ok.
	 *
	 * @return void
	 */
	public function testPptxFollowsThePresentationReader(): void {
		$lesson = ['title' => '', 'sections' => [['heading' => 'Slide 1', 'paragraphs' => ['x'], 'images' => [], 'notes' => '']], 'notes' => []];
		$this->presentation->method('read')->willReturnOnConsecutiveCalls(
			['available' => false, 'lesson' => null],
			['available' => true, 'lesson' => null],
			['available' => true, 'lesson' => $lesson]
		);
		$this->docx->expects($this->never())->method('read');
		$file = $this->createMock(File::class);

		$this->assertSame('unavailable', $this->extractor()->extract(file: $file, format: 'pptx')['status']);
		$this->assertSame('unreadable', $this->extractor()->extract(file: $file, format: 'pptx')['status']);
		$this->assertSame(['status' => 'ok', 'lesson' => $lesson], $this->extractor()->extract(file: $file, format: 'pptx'));

	}//end testPptxFollowsThePresentationReader()

	/**
	 * Reset the fake.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		FakeWordExtractor::$next = null;
		parent::tearDown();

	}//end tearDown()
}//end class
