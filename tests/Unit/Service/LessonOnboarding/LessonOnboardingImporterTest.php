<?php

/**
 * Unit tests for LessonOnboardingImporter.
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

use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\LessonOnboarding\LessonDraftBuilder;
use OCA\Learniq\Service\LessonOnboarding\LessonOnboardingImporter;
use OCA\Learniq\Service\LessonOnboarding\OfficeLessonExtractor;
use OCA\Learniq\Service\LessonOnboarding\OnboardingFolderSetting;
use OCA\Learniq\Service\LessonOnboarding\OnboardingImportException;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The confirmed import: guards, the draft lesson, its materials, the row.
 */
class LessonOnboardingImporterTest extends TestCase {

	private const ROW = '00000000-0000-0000-0000-0000000000a1';

	private const COURSE = '00000000-0000-0000-0000-0000000000c1';

	private const LESSON = '00000000-0000-0000-0000-0000000000b1';

	/** @var ObjectService&MockObject */
	private ObjectService $objectService;

	/** @var CoursePackageObjectWriter&MockObject */
	private CoursePackageObjectWriter $objectWriter;

	/** @var CoursePackageFileWriter&MockObject */
	private CoursePackageFileWriter $fileWriter;

	/** @var OfficeLessonExtractor&MockObject */
	private OfficeLessonExtractor $extractor;

	/** @var TransitionEngine&MockObject */
	private TransitionEngine $transitionEngine;

	/** @var Folder&MockObject */
	private Folder $userFolder;

	/**
	 * Created objects, in order: [schema, payload].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $created = [];

	private LessonOnboardingImporter $importer;

	/**
	 * Build the importer with doubles and a real draft builder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectWriter = $this->createMock(CoursePackageObjectWriter::class);
		$this->fileWriter = $this->createMock(CoursePackageFileWriter::class);
		$this->extractor = $this->createMock(OfficeLessonExtractor::class);
		$this->transitionEngine = $this->createMock(TransitionEngine::class);
		$this->userFolder = $this->createMock(Folder::class);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($this->userFolder);
		$folderSetting = $this->createMock(OnboardingFolderSetting::class);
		$folderSetting->method('tenantOf')->willReturn('00000000-0000-0000-0000-000000000009');

		$this->objectWriter->method('create')->willReturnCallback(
			function (string $schema, array $object): string {
				$this->created[] = [$schema, $object];
				if ($schema === 'lesson') {
					return self::LESSON;
				}

				return 'material-' . count($this->created);
			}
		);

		$this->importer = new LessonOnboardingImporter(
			objectService: $this->objectService,
			objectWriter: $this->objectWriter,
			fileWriter: $this->fileWriter,
			extractor: $this->extractor,
			draftBuilder: new LessonDraftBuilder(),
			transitionEngine: $this->transitionEngine,
			rootFolder: $root,
			folderSetting: $folderSetting,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Wire the row, the course, the course's lessons and the file.
	 *
	 * @param array<string, mixed> $row Row overrides.
	 * @param string $fileName The file name.
	 *
	 * @return void
	 */
	private function given(array $row = [], string $fileName = 'Breuken.docx'): void {
		$rowData = array_merge(
			['teacherId' => 'jdevries', 'fileId' => 99, 'fileName' => $fileName, 'format' => 'docx', 'lifecycle' => 'detected'],
			$row
		);
		$this->objectService->method('find')->willReturnCallback(
			static function (int|string $id) use ($rowData) {
				if ($id === self::ROW) {
					return OrEntityFactory::make($rowData, 'lesson-onboarding-file', 'learniq', self::ROW);
				}

				if ($id === self::COURSE) {
					return OrEntityFactory::make(['name' => 'Rekenen groep 6', 'tenant_id' => '00000000-0000-0000-0000-000000000001'], 'course', 'learniq', self::COURSE);
				}

				return null;
			}
		);
		$this->objectService->method('findAll')->willReturn(
			[OrEntityFactory::make(['order' => 3], 'lesson'), ['order' => 7]]
		);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(99);
		$file->method('getName')->willReturn($fileName);
		$file->method('getPath')->willReturn('/jdevries/files/Lessen inbox/' . $fileName);
		$this->userFolder->method('getById')->with(99)->willReturn([$file]);

	}//end given()

	/**
	 * A docx with an image becomes one draft lesson, two materials and an imported row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
	 */
	public function testADocxBecomesOneDraftLessonWithMaterials(): void {
		$this->given();
		$this->extractor->method('extract')->willReturn(
			[
				'status' => 'ok',
				'lesson' => [
					'title' => 'Breuken vergelijken',
					'sections' => [
						['heading' => 'Start', 'paragraphs' => ['Wat weten we al?'], 'images' => [], 'notes' => ''],
						['heading' => 'Instructie', 'paragraphs' => ['Uitleg.'], 'images' => [['name' => 'image1.png', 'bytes' => 'PNG']], 'notes' => ''],
					],
					'notes' => [],
				],
			]
		);
		$this->fileWriter->expects($this->once())->method('writeBytesToFiles')
			->with('PNG', 'Breuken-99-image1.png', 'jdevries', '00000000-0000-0000-0000-000000000001')
			->willReturn('/Scholiq/tenant/course-imports/Breuken-99-image1.png');

		$updated = null;
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (...$args) use (&$updated) {
				$updated = $args;
				return OrEntityFactory::make([], 'lesson');
			}
		);
		$this->transitionEngine->expects($this->once())->method('transition')
			->with(self::ROW, 'import', ['lessonId' => self::LESSON, 'courseId' => self::COURSE])
			->willReturn(OrEntityFactory::make([], 'lesson-onboarding-file'));

		$result = $this->importer->import(userId: 'jdevries', rowId: self::ROW, courseId: self::COURSE);

		[$schema, $lesson] = $this->created[0];
		$this->assertSame('lesson', $schema);
		$this->assertSame('Breuken vergelijken', $lesson['name']);
		$this->assertSame(8, $lesson['order'], 'one past the highest lesson order in the course');
		$this->assertSame('text', $lesson['contentType']);
		$this->assertSame(self::COURSE, $lesson['courseId']);
		$this->assertSame(['richText', 'richText'], array_column($lesson['blocks'], 'type'));

		$this->assertSame(['material', 'material'], [$this->created[1][0], $this->created[2][0]]);
		$this->assertSame('other', $this->created[1][1]['kind']);
		$this->assertSame(self::LESSON, $this->created[1][1]['lessonId']);
		$this->assertSame('document', $this->created[2][1]['kind']);
		$this->assertSame('/Lessen inbox/Breuken.docx', $this->created[2][1]['fileRef']);

		$this->assertSame(['richText', 'richText', 'media'], array_column($updated[0]['blocks'], 'type'));
		$this->assertSame('material-2', $updated[0]['blocks'][2]['materialId']);

		$this->assertSame(
			['lessonId' => self::LESSON, 'lessonName' => 'Breuken vergelijken', 'courseId' => self::COURSE, 'blocks' => 3, 'materials' => 2, 'notes' => []],
			$result
		);

	}//end testADocxBecomesOneDraftLessonWithMaterials()

	/**
	 * No lifecycle is ever sent: the lesson keeps its initial draft state.
	 *
	 * @return void
	 */
	public function testTheLessonIsNeverPublished(): void {
		$this->given(fileName: 'Kale les.docx');
		$this->extractor->method('extract')->willReturn(
			['status' => 'ok', 'lesson' => ['title' => '', 'sections' => [['heading' => '', 'paragraphs' => ['Tekst'], 'images' => [], 'notes' => '']], 'notes' => ['Read as plain text']]]
		);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->transitionEngine->expects($this->once())->method('transition')
			->with(self::ROW, 'import', ['lessonId' => self::LESSON, 'courseId' => self::COURSE, 'importNote' => 'Read as plain text']);

		$result = $this->importer->import(userId: 'jdevries', rowId: self::ROW, courseId: self::COURSE);

		foreach ($this->created as [$schema, $object]) {
			$this->assertArrayNotHasKey('lifecycle', $object, $schema . ' must not set a lifecycle');
		}

		$this->assertSame('Kale les', $result['lessonName']);

	}//end testTheLessonIsNeverPublished()

	/**
	 * A pptx without OpenRegister's reader writes nothing and leaves the row detected.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-openregister-has-no-presentation-reader-yet
	 */
	public function testAPptxWithoutTheReaderLeavesTheRowDetected(): void {
		$this->given(row: ['format' => 'pptx'], fileName: 'Fotosynthese.pptx');
		$this->extractor->method('extract')->willReturn(['status' => 'unavailable', 'lesson' => null]);
		$this->objectWriter->expects($this->never())->method('create');
		$this->transitionEngine->expects($this->never())->method('transition');

		try {
			$this->importer->import(userId: 'jdevries', rowId: self::ROW, courseId: self::COURSE);
			$this->fail('expected a refusal');
		} catch (OnboardingImportException $e) {
			$this->assertSame(503, $e->getStatus());
			$this->assertSame('reader-unavailable', $e->getReason());
		}

	}//end testAPptxWithoutTheReaderLeavesTheRowDetected()

	/**
	 * Another teacher's row, a row not detected, a missing course, a gone file and
	 * an unreadable file are refused before anything is written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-another-teacher-s-row-cannot-be-imported
	 */
	public function testRefusalsWriteNothing(): void {
		$this->objectWriter->expects($this->never())->method('create');
		$cases = [
			[['teacherId' => 'mbakker'], self::COURSE, 404, 'not-found'],
			[['lifecycle' => 'imported'], self::COURSE, 409, 'not-detected'],
			[[], 'no-such-course', 422, 'course-not-found'],
		];
		foreach ($cases as [$row, $courseId, $status, $reason]) {
			$this->setUp();
			$this->objectWriter->expects($this->never())->method('create');
			$this->given(row: $row);
			try {
				$this->importer->import(userId: 'jdevries', rowId: self::ROW, courseId: $courseId);
				$this->fail('expected ' . $reason);
			} catch (OnboardingImportException $e) {
				$this->assertSame([$status, $reason], [$e->getStatus(), $e->getReason()]);
			}
		}

		$this->setUp();
		$this->given();
		$this->extractor->method('extract')->willReturn(['status' => 'unreadable', 'lesson' => null]);
		try {
			$this->importer->import(userId: 'jdevries', rowId: 'unknown-row', courseId: self::COURSE);
			$this->fail('expected not-found');
		} catch (OnboardingImportException $e) {
			$this->assertSame(404, $e->getStatus());
		}

		try {
			$this->importer->import(userId: 'jdevries', rowId: self::ROW, courseId: self::COURSE);
			$this->fail('expected unreadable');
		} catch (OnboardingImportException $e) {
			$this->assertSame([422, 'unreadable'], [$e->getStatus(), $e->getReason()]);
		}

	}//end testRefusalsWriteNothing()

	/**
	 * A file no longer in the teacher's files answers 410.
	 *
	 * @return void
	 */
	public function testAGoneFileIsRefused(): void {
		$this->objectService->method('find')->willReturnCallback(
			static fn (int|string $id) => OrEntityFactory::make(['teacherId' => 'jdevries', 'fileId' => 5, 'format' => 'docx', 'lifecycle' => 'detected', 'tenant_id' => 'x'], 'x', 'learniq', (string)$id)
		);
		$this->userFolder->method('getById')->willReturn([]);

		try {
			$this->importer->import(userId: 'jdevries', rowId: self::ROW, courseId: self::COURSE);
			$this->fail('expected file-gone');
		} catch (OnboardingImportException $e) {
			$this->assertSame([410, 'file-gone'], [$e->getStatus(), $e->getReason()]);
		}

	}//end testAGoneFileIsRefused()

	/**
	 * When the row cannot move to imported, the lesson stays and the notes say so.
	 *
	 * @return void
	 */
	public function testAFailedTransitionIsReportedNotHidden(): void {
		$this->given();
		$this->extractor->method('extract')->willReturn(
			['status' => 'ok', 'lesson' => ['title' => 'T', 'sections' => [['heading' => 'A', 'paragraphs' => [], 'images' => [], 'notes' => '']], 'notes' => []]]
		);
		$this->transitionEngine->method('transition')->willThrowException(new RuntimeException('guard'));

		$result = $this->importer->import(userId: 'jdevries', rowId: self::ROW, courseId: self::COURSE);

		$this->assertSame(self::LESSON, $result['lessonId']);
		$this->assertCount(1, $result['notes']);

	}//end testAFailedTransitionIsReportedNotHidden()

	/**
	 * A presentation's speaker notes never enter the lesson: the lesson holds
	 * the slides only, and each note is written to the staff-only note schema
	 * after the block of its slide (teacher-notes-protection).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-presentation-with-speaker-notes
	 */
	public function testSlideNotesGoToTheStaffStoreNotTheLesson(): void {
		$this->given(row: ['format' => 'pptx'], fileName: 'Licht.pptx');
		$this->extractor->method('extract')->willReturn(
			[
				'status' => 'ok',
				'lesson' => [
					'title' => 'Licht en schaduw',
					'sections' => [
						['heading' => 'Dia 1', 'paragraphs' => ['Wat is licht?'], 'images' => [], 'notes' => ''],
						['heading' => 'Dia 3', 'paragraphs' => ['Proefje met een zaklamp'], 'images' => [], 'notes' => 'Vraag naar de rol van licht.'],
					],
					'notes' => [],
				],
			]
		);
		$this->transitionEngine->method('transition')->willReturn(OrEntityFactory::make([], 'lesson-onboarding-file'));

		$result = $this->importer->import(userId: 'jdevries', rowId: self::ROW, courseId: self::COURSE);

		[$schema, $lesson] = $this->created[0];
		$this->assertSame('lesson', $schema);
		$this->assertSame(['richText', 'richText'], array_column($lesson['blocks'], 'type'));
		$this->assertStringNotContainsString('Vraag naar de rol van licht', (string)json_encode($lesson), 'no note text in the lesson a learner reads');

		$noteWrites = array_values(array_filter($this->created, static fn (array $write): bool => $write[0] === 'lesson-teacher-note'));
		$this->assertCount(1, $noteWrites);
		$note = $noteWrites[0][1];
		$this->assertSame('Vraag naar de rol van licht.', $note['text']);
		$this->assertSame(self::LESSON, $note['lessonId']);
		$this->assertSame($lesson['blocks'][1]['blockId'], $note['afterBlockId'], 'the note follows the block of its slide');
		$this->assertSame(0, $note['position']);
		$this->assertSame('00000000-0000-0000-0000-000000000001', $note['tenant_id']);
		$this->assertSame(2, $result['blocks']);
		$this->assertSame([], $result['notes']);
	}//end testSlideNotesGoToTheStaffStoreNotTheLesson()

	/**
	 * A note that cannot be saved does not undo the lesson, and the import
	 * report says a note is missing.
	 *
	 * @return void
	 */
	public function testALostNoteIsReported(): void {
		$this->setUpFailingNoteWriter();
		$this->given(row: ['format' => 'pptx'], fileName: 'Licht.pptx');
		$this->extractor->method('extract')->willReturn(
			['status' => 'ok', 'lesson' => ['title' => 'Licht', 'sections' => [['heading' => 'Dia 1', 'paragraphs' => ['Tekst'], 'images' => [], 'notes' => 'Notitie']], 'notes' => []]]
		);
		$this->transitionEngine->expects($this->once())->method('transition')
			->with(self::ROW, 'import', ['lessonId' => self::LESSON, 'courseId' => self::COURSE, 'importNote' => '1 teacher note(s) could not be saved']);

		$result = $this->importer->import(userId: 'jdevries', rowId: self::ROW, courseId: self::COURSE);

		$this->assertSame(['1 teacher note(s) could not be saved'], $result['notes']);
	}//end testALostNoteIsReported()

	/**
	 * Rebuild the importer with an object writer whose note writes fail.
	 *
	 * @return void
	 */
	private function setUpFailingNoteWriter(): void {
		$this->setUp();
		$this->objectWriter = $this->createMock(CoursePackageObjectWriter::class);
		$this->objectWriter->method('create')->willReturnCallback(
			function (string $schema, array $object): ?string {
				$this->created[] = [$schema, $object];
				return $schema === 'lesson' ? self::LESSON : null;
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($this->userFolder);
		$folderSetting = $this->createMock(OnboardingFolderSetting::class);
		$folderSetting->method('tenantOf')->willReturn('00000000-0000-0000-0000-000000000009');
		$this->importer = new LessonOnboardingImporter(
			objectService: $this->objectService,
			objectWriter: $this->objectWriter,
			fileWriter: $this->fileWriter,
			extractor: $this->extractor,
			draftBuilder: new LessonDraftBuilder(),
			transitionEngine: $this->transitionEngine,
			rootFolder: $root,
			folderSetting: $folderSetting,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUpFailingNoteWriter()
}//end class
