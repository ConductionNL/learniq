<?php

/**
 * Unit tests for LessonOnboardingFileListener.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-new-word-or-powerpoint-file-in-the-folder-is-detected-and-the-teacher-is-notified-and-nothing-is-read
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\LessonOnboardingFileListener;
use OCA\Learniq\Service\LessonOnboarding\OnboardingFolderSetting;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Detection of Word and PowerPoint files in a teacher's onboarding folder.
 */
class LessonOnboardingFileListenerTest extends TestCase {

	private const FOLDER_ID = 4711;

	/** @var OnboardingFolderSetting&MockObject */
	private OnboardingFolderSetting $folderSetting;

	/** @var ObjectService&MockObject */
	private ObjectService $objectService;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private LessonOnboardingFileListener $listener;

	/**
	 * Build the listener with doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->folderSetting = $this->createMock(OnboardingFolderSetting::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new LessonOnboardingFileListener(
			folderSetting: $this->folderSetting,
			objectService: $this->objectService,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Map a mocked saveObject() call's positional arguments to parameter names.
	 * The stub and the real ObjectService order their optional parameters
	 * differently, so a test that reads by position passes on one and not the other.
	 *
	 * @param array<int, mixed> $args The positional arguments PHPUnit hands the callback.
	 *
	 * @return array<string, mixed>
	 */
	private static function named(array $args): array {
		$named = [];
		foreach ((new \ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters() as $index => $parameter) {
			if (array_key_exists($index, $args) === true) {
				$named[$parameter->getName()] = $args[$index];
			}
		}

		return $named;

	}//end named()

	/**
	 * A file double owned by `jdevries`, whose content must never be read.
	 *
	 * @param string $name File name.
	 * @param int $parentId Parent folder id.
	 * @param int $fileId File id.
	 *
	 * @return File&MockObject
	 */
	private function file(string $name, int $parentId = self::FOLDER_ID, int $fileId = 99): File {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('jdevries');
		$parent = $this->createMock(Folder::class);
		$parent->method('getId')->willReturn($parentId);

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getOwner')->willReturn($owner);
		$file->method('getParent')->willReturn($parent);
		$file->method('getId')->willReturn($fileId);
		$file->method('getMimeType')->willReturn('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
		$file->method('getPath')->willReturn('/jdevries/files/Lessen inbox/' . $name);
		$file->expects($this->never())->method('getContent');
		$file->expects($this->never())->method('fopen');
		return $file;

	}//end file()

	/**
	 * A docx dropped directly in the owner's folder becomes one detected row,
	 * written without RBAC as the owner, carrying no content.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-teacher-drops-a-word-file-in-the-folder
	 */
	public function testADocxInTheFolderIsRecordedForItsOwner(): void {
		$this->folderSetting->method('folderId')->with('jdevries')->willReturn(self::FOLDER_ID);
		$this->folderSetting->method('tenantOf')->willReturn('00000000-0000-0000-0000-000000000001');
		$this->objectService->method('findAll')->willReturn([]);

		$saved = null;
		$options = [];
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (...$args) use (&$saved, &$options) {
				$options = self::named(args: $args);
				$saved = $options['object'];
				return $this->createStub(\OCA\OpenRegister\Db\ObjectEntity::class);
			}
		);

		$this->listener->handle(new NodeCreatedEvent($this->file(name: 'Breuken.DOCX')));

		$this->assertSame('jdevries', $saved['teacherId']);
		$this->assertSame(99, $saved['fileId']);
		$this->assertSame('Breuken.DOCX', $saved['fileName']);
		$this->assertSame('/Lessen inbox/Breuken.DOCX', $saved['filePath']);
		$this->assertSame('docx', $saved['format']);
		$this->assertArrayNotHasKey('lifecycle', $saved, 'the schema starts every row in detected');
		$this->assertArrayNotHasKey('lessonId', $saved);
		$this->assertSame('lesson-onboarding-file', $options['schema']);
		$this->assertFalse($options['_rbac']);
		$this->assertSame('jdevries', $options['currentUser']->getUID());

	}//end testADocxInTheFolderIsRecordedForItsOwner()

	/**
	 * A pptx is recorded with format pptx.
	 *
	 * @return void
	 */
	public function testAPptxIsRecordedAsPptx(): void {
		$this->folderSetting->method('folderId')->willReturn(self::FOLDER_ID);
		$this->objectService->method('findAll')->willReturn([]);
		$format = null;
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (...$args) use (&$format) {
				$format = self::named(args: $args)['object']['format'];
				return $this->createStub(\OCA\OpenRegister\Db\ObjectEntity::class);
			}
		);

		$this->listener->handle(new NodeCreatedEvent($this->file(name: 'Fotosynthese.pptx')));

		$this->assertSame('pptx', $format);

	}//end testAPptxIsRecordedAsPptx()

	/**
	 * A file moved into the folder is recorded from the event's target.
	 *
	 * @return void
	 */
	public function testAFileMovedIntoTheFolderIsRecorded(): void {
		$this->folderSetting->method('folderId')->willReturn(self::FOLDER_ID);
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->expects($this->once())->method('saveObject')
			->willReturn($this->createStub(\OCA\OpenRegister\Db\ObjectEntity::class));

		$source = $this->file(name: 'Breuken.docx', parentId: 12);
		$target = $this->file(name: 'Breuken.docx');
		$this->listener->handle(new NodeRenamedEvent($source, $target));

	}//end testAFileMovedIntoTheFolderIsRecorded()

	/**
	 * A docx in another folder of the same teacher is ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-file-elsewhere-or-of-another-type-is-ignored
	 */
	public function testAFileOutsideTheFolderIsIgnored(): void {
		$this->folderSetting->method('folderId')->willReturn(self::FOLDER_ID);
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->listener->handle(new NodeCreatedEvent($this->file(name: 'Breuken.docx', parentId: 12)));

	}//end testAFileOutsideTheFolderIsIgnored()

	/**
	 * A teacher without a folder is ignored.
	 *
	 * @return void
	 */
	public function testATeacherWithoutAFolderIsIgnored(): void {
		$this->folderSetting->method('folderId')->willReturn(0);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->listener->handle(new NodeCreatedEvent($this->file(name: 'Breuken.docx')));

	}//end testATeacherWithoutAFolderIsIgnored()

	/**
	 * Another type is ignored before any setting or object lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-file-elsewhere-or-of-another-type-is-ignored
	 */
	public function testAnotherTypeIsIgnoredBeforeAnyLookup(): void {
		$this->folderSetting->expects($this->never())->method('folderId');
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->listener->handle(new NodeCreatedEvent($this->file(name: 'foto.png')));
		$this->listener->handle(new NodeCreatedEvent($this->file(name: 'oud.doc')));
		$this->listener->handle(new NodeCreatedEvent($this->createMock(Folder::class)));
		$this->listener->handle(new Event());

	}//end testAnotherTypeIsIgnoredBeforeAnyLookup()

	/**
	 * A file already recorded for this teacher is not recorded twice.
	 *
	 * @return void
	 */
	public function testAFileRecordedBeforeIsNotRecordedTwice(): void {
		$this->folderSetting->method('folderId')->willReturn(self::FOLDER_ID);
		$this->objectService->expects($this->once())->method('findAll')->with(
			$this->callback(static fn (array $config): bool => $config['filters'] === ['teacherId' => 'jdevries', 'fileId' => 99]),
			false,
			false
		)->willReturn([['id' => 'existing']]);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->listener->handle(new NodeCreatedEvent($this->file(name: 'Breuken.docx')));

	}//end testAFileRecordedBeforeIsNotRecordedTwice()

	/**
	 * A failure is logged and never reaches the upload.
	 *
	 * @return void
	 */
	public function testAFailureNeverReachesTheUpload(): void {
		$this->folderSetting->method('folderId')->willThrowException(new RuntimeException('config down'));
		$this->logger->expects($this->once())->method('warning');

		$this->listener->handle(new NodeCreatedEvent($this->file(name: 'Breuken.docx')));

	}//end testAFailureNeverReachesTheUpload()

	/**
	 * The format helper maps extensions case-insensitively.
	 *
	 * @return void
	 */
	public function testFormatOf(): void {
		$this->assertSame('docx', LessonOnboardingFileListener::formatOf('Les 1.Docx'));
		$this->assertSame('pptx', LessonOnboardingFileListener::formatOf('deck.pptx'));
		$this->assertNull(LessonOnboardingFileListener::formatOf('deck.ppt'));
		$this->assertNull(LessonOnboardingFileListener::formatOf('docx'));

	}//end testFormatOf()
}//end class
