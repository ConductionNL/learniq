<?php

/**
 * Learniq Lesson Onboarding Importer
 *
 * Runs when a teacher confirms one detected Word or PowerPoint file on the
 * review page (office-file-lesson-onboarding, decision D17): reads the file,
 * creates one `Lesson` draft in the chosen course, records each embedded image
 * and the original file as `Material` rows, and moves the detection row to
 * `imported`. Nothing is published: the lesson keeps its initial `draft`
 * state, and every OpenRegister write runs as the teacher, with RBAC on.
 *
 * ADR-031 legitimate exception: document parsing.
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

use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports one confirmed onboarding file as a lesson draft.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The import touches the row, the course, the
 * file, the readers and two writers by design; each collaborator is already its own class.
 */
class LessonOnboardingImporter {

	private const REGISTER = 'learniq';

	private const ROW_SCHEMA = 'lesson-onboarding-file';

	/**
	 * The staff-only schema teacher notes are written to (teacher-notes-protection).
	 */
	private const NOTE_SCHEMA = 'lesson-teacher-note';

	/**
	 * Takes slide notes out of the lesson blocks. Pure, so it is built here
	 * rather than injected (the constructor is at phpmd's parameter limit).
	 *
	 * @var TeacherNoteSplitter
	 */
	private readonly TeacherNoteSplitter $noteSplitter;

	/**
	 * Stores the lesson's images and the original file as materials.
	 *
	 * @var LessonMaterialWriter
	 */
	private readonly LessonMaterialWriter $materials;

	/**
	 * Reads the confirmed file.
	 *
	 * @var OfficeLessonExtractor
	 */
	private readonly OfficeLessonExtractor $extractor;

	/**
	 * Maps sections to blocks.
	 *
	 * @var LessonDraftBuilder
	 */
	private readonly LessonDraftBuilder $draftBuilder;

	/**
	 * Resolves the file inside the teacher's files.
	 *
	 * @var IRootFolder
	 */
	private readonly IRootFolder $rootFolder;

	/**
	 * Tenant fallback.
	 *
	 * @var OnboardingFolderSetting
	 */
	private readonly OnboardingFolderSetting $folderSetting;

	/**
	 * Creates the lesson and its materials.
	 *
	 * @var CoursePackageObjectWriter
	 */
	private readonly CoursePackageObjectWriter $objectWriter;

	/**
	 * Moves the row to imported.
	 *
	 * @var TransitionEngine
	 */
	private readonly TransitionEngine $transitionEngine;

	/**
	 * Constructor.
	 *
	 * @param ObjectService           $objectService Reads the row, the course and its lessons; updates the lesson.
	 * @param LessonOnboardingReaders $readers       The extractor, draft builder, root folder and folder setting.
	 * @param LessonOnboardingWriters $writers       The object writer, file writer and transition engine.
	 * @param LoggerInterface         $logger        Logs ids and counts, never document text.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		LessonOnboardingReaders $readers,
		LessonOnboardingWriters $writers,
		private readonly LoggerInterface $logger,
	) {
		$this->extractor = $readers->extractor;
		$this->draftBuilder = $readers->draftBuilder;
		$this->rootFolder = $readers->rootFolder;
		$this->folderSetting = $readers->folderSetting;
		$this->objectWriter = $writers->objectWriter;
		$this->transitionEngine = $writers->transitionEngine;
		$this->noteSplitter = new TeacherNoteSplitter();
		$this->materials = new LessonMaterialWriter(objectWriter: $writers->objectWriter, fileWriter: $writers->fileWriter);
	}//end __construct()

	/**
	 * Import one detected row into a course.
	 *
	 * @param string $userId The confirming teacher.
	 * @param string $rowId The LessonOnboardingFile uuid.
	 * @param string $courseId The target Course uuid.
	 *
	 * @return array{lessonId: string, lessonName: string, courseId: string, blocks: int, materials: int, notes: list<string>}
	 *
	 * @throws OnboardingImportException When the row, the course or the file refuses the import.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
	 */
	public function import(string $userId, string $rowId, string $courseId): array {
		$row = $this->loadRow(userId: $userId, rowId: $rowId);
		$course = $this->loadCourse(courseId: $courseId);
		$file = $this->resolveFile(userId: $userId, fileId: (int)($row['fileId'] ?? 0));
		$run = [
			'userId' => $userId,
			'rowId' => $rowId,
			'courseId' => $courseId,
			'format' => (string)($row['format'] ?? ''),
			'lesson' => $this->readLesson(file: $file, format: (string)($row['format'] ?? '')),
		];
		$run['tenantId'] = $this->tenantFor(course: $course, userId: $userId);
		$run['fileName'] = (string)$file->getName();

		$run = $this->attachImages(run: $this->createDraftLesson(run: $run), file: $file);

		return $this->finishImport(run: $run, file: $file);
	}//end import()

	/**
	 * The lesson the extractor reads from the file.
	 *
	 * @param File   $file   The file.
	 * @param string $format The row's format.
	 *
	 * @return array<string,mixed> The lesson: title, sections and notes.
	 *
	 * @throws OnboardingImportException When the reader is unavailable or the file holds no lesson.
	 */
	private function readLesson(File $file, string $format): array {
		$read = $this->extractor->extract(file: $file, format: $format);
		if ($read['status'] === 'unavailable') {
			throw new OnboardingImportException(
				'PowerPoint import needs a newer OpenRegister. The file stays in your list.',
				503,
				'reader-unavailable'
			);
		}

		if ($read['status'] !== 'ok' || $read['lesson'] === null) {
			throw new OnboardingImportException('This file holds no lesson content learniq can read.', 422, 'unreadable');
		}

		return $read['lesson'];
	}//end readLesson()

	/**
	 * The tenant of the course, else the tenant of the teacher's onboarding folder.
	 *
	 * @param array<string,mixed> $course The course.
	 * @param string              $userId The teacher.
	 *
	 * @return string
	 */
	private function tenantFor(array $course, string $userId): string {
		$tenantId = (string)($course['tenant_id'] ?? '');
		if ($tenantId === '') {
			return $this->folderSetting->tenantOf(userId: $userId);
		}

		return $tenantId;
	}//end tenantFor()

	/**
	 * Create the draft lesson. Slide notes never enter the lesson: every
	 * signed-in user reads a Lesson. They are kept on the run and written to
	 * the staff-only note schema once the final blocks, and so their ids, are
	 * known (teacher-notes-protection).
	 *
	 * @param array<string,mixed> $run The import so far.
	 *
	 * @return array<string,mixed> The run with its payload, lessonId and teacher notes.
	 *
	 * @throws OnboardingImportException When the lesson could not be created.
	 */
	private function createDraftLesson(array $run): array {
		$draft = $this->noteSplitter->split(blocks: $this->draftBuilder->build(sections: $run['lesson']['sections']));
		$run['payload'] = $this->lessonPayload(run: $run, blocks: $draft['blocks']);
		$run['lessonId'] = $this->objectWriter->create(schema: 'lesson', object: $run['payload']);
		if ($run['lessonId'] === null) {
			throw new OnboardingImportException('The lesson draft could not be created.', 500, 'lesson-not-created');
		}

		$run['notes'] = $draft['notes'];

		return $run;
	}//end createDraftLesson()

	/**
	 * The draft lesson's fields.
	 *
	 * @param array<string,mixed>            $run    The import so far.
	 * @param array<int,array<string,mixed>> $blocks The lesson's blocks.
	 *
	 * @return array<string,mixed>
	 */
	private function lessonPayload(array $run, array $blocks): array {
		return [
			'courseId' => $run['courseId'],
			'name' => $this->lessonName(title: (string)$run['lesson']['title'], fileName: $run['fileName']),
			'order' => $this->nextOrder(courseId: $run['courseId']),
			'contentType' => 'text',
			'blocks' => $blocks,
			'tenant_id' => $run['tenantId'],
		];
	}//end lessonPayload()

	/**
	 * Store the lesson's images as materials and, when there are any, rebuild
	 * the lesson's blocks around them and save the lesson again.
	 *
	 * @param array<string,mixed> $run  The import so far.
	 * @param File                $file The imported file.
	 *
	 * @return array<string,mixed> The run with its materialIds, and its final payload and teacher notes.
	 */
	private function attachImages(array $run, File $file): array {
		$run['materialIds'] = $this->materials->images(sections: $run['lesson']['sections'], file: $file, context: self::context(run: $run));
		if ($run['materialIds'] === []) {
			return $run;
		}

		$draft = $this->noteSplitter->split(blocks: $this->draftBuilder->build(sections: $run['lesson']['sections'], materialIds: $run['materialIds']));
		$run['payload']['blocks'] = $draft['blocks'];
		$run['notes'] = $draft['notes'];
		$this->objectService->saveObject(
			object: $run['payload'],
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER, schema: 'lesson'),
				uuid: $run['lessonId']
			)
		);

		return $run;
	}//end attachImages()

	/**
	 * Write the teacher notes and the original file's material, mark the row
	 * imported, log, and report.
	 *
	 * @param array<string,mixed> $run  The import so far.
	 * @param File                $file The imported file.
	 *
	 * @return array{lessonId: string, lessonName: string, courseId: string, blocks: int, materials: int, notes: list<string>}
	 */
	private function finishImport(array $run, File $file): array {
		$notesLost = $this->writeTeacherNotes(notes: $run['notes'], lessonId: $run['lessonId'], tenantId: $run['tenantId']);
		$run['materials'] = $this->materialCount(run: $run, file: $file);
		$notes = self::importNotes(notes: $run['lesson']['notes'], notesLost: $notesLost);
		$notes = $this->markImported(rowId: $run['rowId'], context: self::context(run: $run), notes: $notes);

		$this->logImport(run: $run, file: $file);

		return [
			'lessonId' => $run['lessonId'],
			'lessonName' => $run['payload']['name'],
			'courseId' => $run['courseId'],
			'blocks' => count($run['payload']['blocks']),
			'materials' => $run['materials'],
			'notes' => $notes,
		];
	}//end finishImport()

	/**
	 * Log the finished import.
	 *
	 * @param array<string,mixed> $run  The import.
	 * @param File                $file The imported file.
	 *
	 * @return void
	 */
	private function logImport(array $run, File $file): void {
		$this->logger->info(
			'[LessonOnboardingImporter] File {fileId} imported as lesson {lessonId} ({blocks} blocks, {materials} materials)',
			['fileId' => $file->getId(), 'lessonId' => $run['lessonId'], 'blocks' => count($run['payload']['blocks']), 'materials' => $run['materials']]
		);
	}//end logImport()

	/**
	 * The image materials plus the original file's material, when that was created.
	 *
	 * @param array<string,mixed> $run  The import so far.
	 * @param File                $file The imported file.
	 *
	 * @return int
	 */
	private function materialCount(array $run, File $file): int {
		$materials = array_sum(array_map('count', $run['materialIds']));
		if ($this->materials->original(file: $file, format: $run['format'], context: self::context(run: $run)) !== null) {
			$materials++;
		}

		return $materials;
	}//end materialCount()

	/**
	 * The reader's notes, plus one when teacher notes were lost.
	 *
	 * @param list<string> $notes     The reader's notes.
	 * @param int          $notesLost The teacher notes that could not be saved.
	 *
	 * @return list<string>
	 */
	private static function importNotes(array $notes, int $notesLost): array {
		if ($notesLost > 0) {
			$notes[] = sprintf('%d teacher note(s) could not be saved', $notesLost);
		}

		return $notes;
	}//end importNotes()

	/**
	 * The ids the materials and the row transition need.
	 *
	 * @param array<string,mixed> $run The import so far.
	 *
	 * @return array{userId: string, courseId: string, lessonId: string, tenantId: string}
	 */
	private static function context(array $run): array {
		return ['userId' => $run['userId'], 'courseId' => $run['courseId'], 'lessonId' => $run['lessonId'], 'tenantId' => $run['tenantId']];
	}//end context()

	/**
	 * Write the lesson's teacher notes to the staff-only note schema, as the
	 * confirming teacher. A note that fails is counted, not fatal: the lesson
	 * is already there, and the import report says a note is missing.
	 *
	 * @param list<array{blockId: string, afterBlockId: string, position: int, text: string}> $notes The split notes.
	 * @param string $lessonId The created lesson.
	 * @param string $tenantId The lesson's tenant.
	 *
	 * @return int How many notes could not be saved.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-imported-slide-notes-land-in-the-staff-store
	 */
	private function writeTeacherNotes(array $notes, string $lessonId, string $tenantId): int {
		$lost = 0;
		foreach ($notes as $note) {
			try {
				$noteId = $this->objectWriter->create(schema: self::NOTE_SCHEMA, object: [...$note, 'lessonId' => $lessonId, 'tenant_id' => $tenantId]);
			} catch (Throwable $e) {
				$this->logger->warning(
					'[LessonOnboardingImporter] A teacher note of lesson {lessonId} could not be saved: {error}',
					['lessonId' => $lessonId, 'error' => $e->getMessage()]
				);
				$noteId = null;
			}

			if ($noteId === null) {
				$lost++;
			}
		}

		return $lost;
	}//end writeTeacherNotes()

	/**
	 * The detection row, read as the teacher; only their own and only while detected.
	 *
	 * @param string $userId The teacher.
	 * @param string $rowId The row uuid.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @throws OnboardingImportException 404 for a missing or foreign row, 409 for one not detected.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-another-teacher-s-row-cannot-be-imported
	 */
	private function loadRow(string $userId, string $rowId): array {
		$row = $this->findArray(id: $rowId, schema: self::ROW_SCHEMA);
		if ($row === null || (string)($row['teacherId'] ?? '') !== $userId) {
			throw new OnboardingImportException('This file is not in your list.', 404, 'not-found');
		}

		if (($row['lifecycle'] ?? 'detected') !== 'detected') {
			throw new OnboardingImportException('This file was already imported or dismissed.', 409, 'not-detected');
		}

		return $row;
	}//end loadRow()

	/**
	 * The target course, read as the teacher.
	 *
	 * @param string $courseId The course uuid.
	 *
	 * @return array<string, mixed> The course.
	 *
	 * @throws OnboardingImportException 422 when it does not exist or cannot be read.
	 */
	private function loadCourse(string $courseId): array {
		$course = $this->findArray(id: $courseId, schema: 'course');
		if ($course === null) {
			throw new OnboardingImportException('That course does not exist, or you cannot see it.', 422, 'course-not-found');
		}

		return $course;
	}//end loadCourse()

	/**
	 * The file by id inside the teacher's own files.
	 *
	 * @param string $userId The teacher.
	 * @param int $fileId The file id.
	 *
	 * @return File The file.
	 *
	 * @throws OnboardingImportException 410 when it is gone.
	 */
	private function resolveFile(string $userId, int $fileId): File {
		if ($fileId > 0) {
			foreach ($this->rootFolder->getUserFolder($userId)->getById($fileId) as $node) {
				if ($node instanceof File) {
					return $node;
				}
			}
		}

		throw new OnboardingImportException('The file is no longer in your files.', 410, 'file-gone');
	}//end resolveFile()

	/**
	 * One object as an array, or null when it cannot be read.
	 *
	 * @param string $id The uuid.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findArray(string $id, string $schema): ?array {
		try {
			$entity = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $entity->jsonSerialize();
	}//end findArray()

	/**
	 * One past the highest lesson order in the course.
	 *
	 * @param string $courseId The course uuid.
	 *
	 * @return int The order for the new lesson.
	 */
	private function nextOrder(string $courseId): int {
		$highest = 0;
		$lessons = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => 'lesson',
					'courseId' => $courseId,
				],
				'limit' => 1000,
			]
		);
		foreach ($lessons as $lesson) {
			if (is_object($lesson) === true && method_exists($lesson, 'jsonSerialize') === true) {
				$lesson = $lesson->jsonSerialize();
			}

			if (is_array($lesson) === true) {
				$highest = max($highest, (int)($lesson['order'] ?? 0));
			}
		}

		return ($highest + 1);
	}//end nextOrder()

	/**
	 * The lesson name: the document title, else the file name without extension.
	 *
	 * @param string $title The title the reader found.
	 * @param string $fileName The file name.
	 *
	 * @return string
	 */
	private function lessonName(string $title, string $fileName): string {
		$title = trim($title);
		if ($title !== '') {
			return mb_substr($title, 0, 255);
		}

		return (string)pathinfo($fileName, PATHINFO_FILENAME);
	}//end lessonName()

	/**
	 * Move the row to imported with its lesson and course. A failure here leaves
	 * the lesson in place and says so in the notes, so the teacher can dismiss
	 * the row instead of importing it twice.
	 *
	 * @param string $rowId The row uuid.
	 * @param array{userId: string, courseId: string, lessonId: string, tenantId: string} $context The import.
	 * @param list<string> $notes What the read left out.
	 *
	 * @return list<string> The notes, plus one when the row could not be moved.
	 */
	private function markImported(string $rowId, array $context, array $notes): array {
		$inputs = ['lessonId' => $context['lessonId'], 'courseId' => $context['courseId']];
		if ($notes !== []) {
			$inputs['importNote'] = implode('; ', $notes);
		}

		try {
			$this->transitionEngine->transition($rowId, 'import', $inputs);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[LessonOnboardingImporter] Row {rowId} stays detected after import: {exception}',
				['rowId' => $rowId, 'exception' => get_class($e)]
			);
			$notes[] = 'The file stays in your list; dismiss it so you do not import it twice';
		}

		return $notes;
	}//end markImported()
}//end class
