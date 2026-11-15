<?php

/**
 * Unit tests for LessonMaterialWriter: imported images stored and linked as
 * materials, and the original file linked by its user-relative path.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\LessonOnboarding;

use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\LessonOnboarding\LessonMaterialWriter;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\LessonOnboarding\LessonMaterialWriter
 */
class LessonMaterialWriterTest extends TestCase {

	private const CONTEXT = ['userId' => 'docent', 'courseId' => 'c-1', 'lessonId' => 'l-1', 'tenantId' => 't-1'];

	/** @var CoursePackageObjectWriter&MockObject */
	private CoursePackageObjectWriter $objectWriter;

	/** @var CoursePackageFileWriter&MockObject */
	private CoursePackageFileWriter $fileWriter;

	private LessonMaterialWriter $writer;

	/**
	 * Build the writer over mocked object and file writers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectWriter = $this->createMock(CoursePackageObjectWriter::class);
		$this->fileWriter = $this->createMock(CoursePackageFileWriter::class);
		$this->writer = new LessonMaterialWriter($this->objectWriter, $this->fileWriter);
	}//end setUp()

	/**
	 * A source file with the given name, id and path.
	 *
	 * @param string $name The file name.
	 * @param int    $id   The file id.
	 * @param string $path The full path.
	 *
	 * @return File&MockObject
	 */
	private function file(string $name, int $id = 42, string $path = ''): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn($path);
		return $file;
	}//end file()

	/**
	 * Each image is written under a prefix of the sanitised base name and file id, and becomes a material per section.
	 *
	 * @return void
	 */
	public function testImagesAreStoredAndLinkedPerSection(): void {
		$written = [];
		$this->fileWriter->expects(self::exactly(3))->method('writeBytesToFiles')->willReturnCallback(
			static function (string $content, string $filename, string $importedBy, string $tenantId) use (&$written): string {
				$written[] = [$content, $filename, $importedBy, $tenantId];
				return '/import/' . $filename;
			}
		);
		$created = [];
		$this->objectWriter->expects(self::exactly(3))->method('create')->willReturnCallback(
			static function (string $schema, array $object) use (&$created): string {
				$created[] = [$schema, $object];
				return 'm-' . count($created);
			}
		);

		$result = $this->writer->images(
			[
				['images' => [['name' => 'foto 1.png', 'bytes' => 'A'], ['name' => 'b.jpg', 'bytes' => 'B']]],
				['heading' => 'zonder plaatjes'],
				['images' => [['name' => 'c.gif', 'bytes' => 'C']]],
			],
			$this->file('Les 3: licht & schaduw.docx', 42),
			self::CONTEXT
		);

		self::assertSame([0 => ['m-1', 'm-2'], 2 => ['m-3']], $result);
		self::assertSame(
			[
				['A', 'Les-3-licht-schaduw-42-foto-1.png', 'docent', 't-1'],
				['B', 'Les-3-licht-schaduw-42-b.jpg', 'docent', 't-1'],
				['C', 'Les-3-licht-schaduw-42-c.gif', 'docent', 't-1'],
			],
			$written
		);
		self::assertSame(
			[
				'material',
				[
					'title' => 'foto 1.png',
					'kind' => 'other',
					'fileRef' => '/import/Les-3-licht-schaduw-42-foto-1.png',
					'courseId' => 'c-1',
					'lessonId' => 'l-1',
					'tenant_id' => 't-1',
				],
			],
			$created[0]
		);
	}//end testImagesAreStoredAndLinkedPerSection()

	/**
	 * An image whose bytes cannot be stored, or whose material is not created, is left out.
	 *
	 * @return void
	 */
	public function testUnstoredImagesAreLeftOut(): void {
		$this->fileWriter->method('writeBytesToFiles')->willReturnOnConsecutiveCalls(null, '/import/b', '/import/c');
		$this->objectWriter->expects(self::exactly(2))->method('create')->willReturnOnConsecutiveCalls(null, 'm-c');

		$result = $this->writer->images(
			[['images' => [['name' => 'a', 'bytes' => 'A'], ['name' => 'b', 'bytes' => 'B'], ['name' => 'c', 'bytes' => 'C']]]],
			$this->file('les.docx'),
			self::CONTEXT
		);

		self::assertSame([0 => ['m-c']], $result);
	}//end testUnstoredImagesAreLeftOut()

	/**
	 * Without images nothing is written.
	 *
	 * @return void
	 */
	public function testNoImagesWritesNothing(): void {
		$this->fileWriter->expects(self::never())->method('writeBytesToFiles');
		$this->objectWriter->expects(self::never())->method('create');

		self::assertSame([], $this->writer->images([['heading' => 'Intro'], ['images' => []]], $this->file('les.docx'), self::CONTEXT));
	}//end testNoImagesWritesNothing()

	/**
	 * The original is linked by its path relative to the user's files, as a document or slides.
	 *
	 * @return void
	 */
	public function testTheOriginalIsLinkedByItsUserRelativePath(): void {
		$this->objectWriter->expects(self::exactly(2))->method('create')->willReturnCallback(
			static function (string $schema, array $object): string {
				self::assertSame('material', $schema);
				return $object['kind'] . ':' . $object['fileRef'] . ':' . $object['title'] . ':' . $object['lessonId'];
			}
		);

		self::assertSame(
			'document:/Lessen/les.docx:les.docx:l-1',
			$this->writer->original($this->file('les.docx', 1, '/docent/files/Lessen/les.docx'), 'docx', self::CONTEXT)
		);
		self::assertSame(
			'slides:/docent/files/Lessen/deck.pptx:deck.pptx:l-1',
			$this->writer->original(
				$this->file('deck.pptx', 2, '/docent/files/Lessen/deck.pptx'),
				'pptx',
				['userId' => 'iemand', 'courseId' => 'c-1', 'lessonId' => 'l-1', 'tenantId' => 't-1']
			),
			'a path outside the user\'s own files is kept whole'
		);
	}//end testTheOriginalIsLinkedByItsUserRelativePath()

	/**
	 * A failed create answers null.
	 *
	 * @return void
	 */
	public function testAFailedOriginalIsNull(): void {
		$this->objectWriter->method('create')->willReturn(null);

		self::assertNull($this->writer->original($this->file('les.docx', 1, '/docent/files/les.docx'), 'docx', self::CONTEXT));
	}//end testAFailedOriginalIsNull()
}//end class
