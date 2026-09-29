<?php

/**
 * Unit tests for PresentationLessonReader, against a fake extractor.
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-powerpoint-file-becomes-one-lesson-draft-or-waits-when-the-reader-is-missing
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\LessonOnboarding;

use OCA\Learniq\Service\LessonOnboarding\PresentationLessonReader;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Stands in for OpenRegister's PresentationExtractor (openregister PR 4077).
 */
class FakePresentationExtractor {

	/**
	 * What extract() returns next, or a throwable it throws.
	 *
	 * @var array<string, mixed>|\Throwable|null
	 */
	public static array|\Throwable|null $next = null;

	/**
	 * Mirror of PresentationExtractor::extract().
	 *
	 * @param File $file The deck.
	 *
	 * @return array<string, mixed>|null
	 */
	public function extract(File $file): ?array {
		if (self::$next instanceof \Throwable) {
			throw self::$next;
		}

		return self::$next;
	}//end extract()
}//end class

/**
 * Slides to sections, hidden slides left out, and the missing-class path.
 */
class PresentationLessonReaderTest extends TestCase {

	/**
	 * A reader over the fake extractor.
	 *
	 * @param string $class The extractor class to use.
	 *
	 * @return PresentationLessonReader
	 */
	private function reader(string $class = FakePresentationExtractor::class): PresentationLessonReader {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new FakePresentationExtractor());
		return new PresentationLessonReader(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			extractorClass: $class
		);

	}//end reader()

	/**
	 * Without OpenRegister's class the reader says so and reads nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-openregister-has-no-presentation-reader-yet
	 */
	public function testMissingExtractorIsReportedAsUnavailable(): void {
		$file = $this->createMock(File::class);
		$file->expects($this->never())->method('getContent');

		$reader = $this->reader(class: 'OCA\\OpenRegister\\Service\\TextExtraction\\NoSuchExtractor');

		$this->assertFalse($reader->isAvailable());
		$this->assertSame(['available' => false, 'lesson' => null], $reader->read(file: $file));

	}//end testMissingExtractorIsReportedAsUnavailable()

	/**
	 * Visible slides become sections with their notes; the first title names the lesson.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-deck-with-speaker-notes
	 */
	public function testSlidesMapToSections(): void {
		FakePresentationExtractor::$next = [
			'slides' => [
				['number' => 1, 'hidden' => false, 'title' => 'Fotosynthese', 'body' => ['Planten maken voedsel', ' '], 'notes' => '', 'images' => []],
				['number' => 2, 'hidden' => true, 'title' => 'Reserve', 'body' => ['Niet tonen'], 'notes' => 'geheim', 'images' => []],
				['number' => 3, 'hidden' => false, 'title' => '', 'body' => ['Licht + water'], 'notes' => 'Vraag naar de rol van licht.', 'images' => []],
			],
			'truncated' => false,
		];

		$read = $this->reader()->read(file: $this->createMock(File::class));

		$this->assertTrue($read['available']);
		$this->assertSame('Fotosynthese', $read['lesson']['title']);
		$this->assertSame(
			[
				['heading' => 'Fotosynthese', 'paragraphs' => ['Planten maken voedsel'], 'images' => [], 'notes' => ''],
				['heading' => 'Slide 3', 'paragraphs' => ['Licht + water'], 'images' => [], 'notes' => 'Vraag naar de rol van licht.'],
			],
			$read['lesson']['sections']
		);

	}//end testSlidesMapToSections()

	/**
	 * Hidden slides are counted and a truncated deck is named in the notes.
	 *
	 * @return void
	 */
	public function testHiddenSlidesAreLeftOut(): void {
		$lesson = $this->reader()->toLesson(
			result: [
				'slides' => [
					['number' => 1, 'hidden' => true, 'title' => 'A', 'body' => [], 'notes' => ''],
					['number' => 2, 'hidden' => true, 'title' => 'B', 'body' => [], 'notes' => ''],
				],
				'truncated' => true,
			]
		);

		$this->assertSame([], $lesson['sections']);
		$this->assertSame(['2 hidden slides left out', 'The deck was cut off at the slide limit'], $lesson['notes']);

	}//end testHiddenSlidesAreLeftOut()

	/**
	 * A deck the extractor cannot read, or that throws, reads as no lesson.
	 *
	 * @return void
	 */
	public function testAnUnreadableDeckIsNoLesson(): void {
		$file = $this->createMock(File::class);

		FakePresentationExtractor::$next = null;
		$this->assertSame(['available' => true, 'lesson' => null], $this->reader()->read(file: $file));

		FakePresentationExtractor::$next = new RuntimeException('zip missing');
		$this->assertSame(['available' => true, 'lesson' => null], $this->reader()->read(file: $file));

	}//end testAnUnreadableDeckIsNoLesson()

	/**
	 * Reset the fake.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		FakePresentationExtractor::$next = null;
		parent::tearDown();

	}//end tearDown()
}//end class
