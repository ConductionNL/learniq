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
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
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
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the row, the course and its lessons; updates the lesson.
	 * @param CoursePackageObjectWriter $objectWriter Creates the lesson and its materials.
	 * @param CoursePackageFileWriter $fileWriter Writes image bytes into the teacher's files.
	 * @param OfficeLessonExtractor $extractor Reads the confirmed file.
	 * @param LessonDraftBuilder $draftBuilder Maps sections to blocks.
	 * @param TransitionEngine $transitionEngine Moves the row to imported.
	 * @param IRootFolder $rootFolder Resolves the file inside the teacher's files.
	 * @param OnboardingFolderSetting $folderSetting Tenant fallback.
	 * @param LoggerInterface $logger Logs ids and counts, never document text.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CoursePackageObjectWriter $objectWriter,
		private readonly CoursePackageFileWriter $fileWriter,
		private readonly OfficeLessonExtractor $extractor,
		private readonly LessonDraftBuilder $draftBuilder,
		private readonly TransitionEngine $transitionEngine,
		private readonly IRootFolder $rootFolder,
		private readonly OnboardingFolderSetting $folderSetting,
		private readonly LoggerInterface $logger,
	) {
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
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
	 */
	public function import(string $userId, string $rowId, string $courseId): array {
		$row = $this->loadRow(userId: $userId, rowId: $rowId);
		$course = $this->loadCourse(courseId: $courseId);
		$file = $this->resolveFile(userId: $userId, fileId: (int)($row['fileId'] ?? 0));

		$read = $this->extractor->extract(file: $file, format: (string)($row['format'] ?? ''));
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

		$lesson = $read['lesson'];
		$tenantId = (string)($course['tenant_id'] ?? '');
		if ($tenantId === '') {
			$tenantId = $this->folderSetting->tenantOf(userId: $userId);
		}

		$fileName = (string)$file->getName();
		$payload = [
			'courseId' => $courseId,
			'name' => $this->lessonName(title: (string)$lesson['title'], fileName: $fileName),
			'order' => $this->nextOrder(courseId: $courseId),
			'contentType' => 'text',
			'blocks' => $this->draftBuilder->build(sections: $lesson['sections']),
			'tenant_id' => $tenantId,
		];

		$lessonId = $this->objectWriter->create(schema: 'lesson', object: $payload);
		if ($lessonId === null) {
			throw new OnboardingImportException('The lesson draft could not be created.', 500, 'lesson-not-created');
		}

		$context = ['userId' => $userId, 'courseId' => $courseId, 'lessonId' => $lessonId, 'tenantId' => $tenantId];
		$materialIds = $this->imageMaterials(sections: $lesson['sections'], file: $file, context: $context);
		if ($materialIds !== []) {
			$payload['blocks'] = $this->draftBuilder->build(sections: $lesson['sections'], materialIds: $materialIds);
			$this->objectService->saveObject(object: $payload, register: self::REGISTER, schema: 'lesson', uuid: $lessonId);
		}

		$materials = array_sum(array_map('count', $materialIds));
		if ($this->originalMaterial(file: $file, format: (string)$row['format'], context: $context) !== null) {
			$materials++;
		}

		$notes = $lesson['notes'];
		$notes = $this->markImported(rowId: $rowId, context: $context, notes: $notes);

		$this->logger->info(
			'[LessonOnboardingImporter] File {fileId} imported as lesson {lessonId} ({blocks} blocks, {materials} materials)',
			['fileId' => $file->getId(), 'lessonId' => $lessonId, 'blocks' => count($payload['blocks']), 'materials' => $materials]
		);

		return [
			'lessonId' => $lessonId,
			'lessonName' => $payload['name'],
			'courseId' => $courseId,
			'blocks' => count($payload['blocks']),
			'materials' => $materials,
			'notes' => $notes,
		];
	}//end import()

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
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-another-teacher-s-row-cannot-be-imported
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
			config: ['register' => self::REGISTER, 'schema' => 'lesson', 'filters' => ['courseId' => $courseId], 'limit' => 1000]
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
	 * Write each section's images to the teacher's files and record them as Materials.
	 *
	 * @param list<array<string, mixed>> $sections The sections.
	 * @param File $file The source document (names the image files).
	 * @param array{userId: string, courseId: string, lessonId: string, tenantId: string} $context Where they belong.
	 *
	 * @return array<int, list<string>> Per section index, the Material ids.
	 */
	private function imageMaterials(array $sections, File $file, array $context): array {
		$base = preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string)pathinfo((string)$file->getName(), PATHINFO_FILENAME));
		$materialIds = [];
		foreach ($sections as $index => $section) {
			foreach (($section['images'] ?? []) as $image) {
				$name = preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string)$image['name']);
				$fileRef = $this->fileWriter->writeBytesToFiles(
					content: (string)$image['bytes'],
					filename: trim((string)$base, '-') . '-' . (int)$file->getId() . '-' . $name,
					importedBy: $context['userId'],
					tenantId: $context['tenantId'],
				);
				if ($fileRef === null) {
					continue;
				}

				$materialId = $this->objectWriter->create(
					schema: 'material',
					object: [
						'title' => (string)$image['name'],
						'kind' => 'other',
						'fileRef' => $fileRef,
						'courseId' => $context['courseId'],
						'lessonId' => $context['lessonId'],
						'tenant_id' => $context['tenantId'],
					]
				);
				if ($materialId !== null) {
					$materialIds[$index][] = $materialId;
				}
			}//end foreach
		}//end foreach

		return $materialIds;
	}//end imageMaterials()

	/**
	 * Link the original file to the lesson as a Material.
	 *
	 * @param File $file The document or deck.
	 * @param string $format `docx` or `pptx`.
	 * @param array{userId: string, courseId: string, lessonId: string, tenantId: string} $context Where it belongs.
	 *
	 * @return string|null The Material id.
	 */
	private function originalMaterial(File $file, string $format, array $context): ?string {
		$prefix = '/' . $context['userId'] . '/files';
		$path = (string)$file->getPath();
		if (str_starts_with($path, $prefix . '/') === true) {
			$path = substr($path, strlen($prefix));
		}

		$kind = 'document';
		if ($format === 'pptx') {
			$kind = 'slides';
		}

		return $this->objectWriter->create(
			schema: 'material',
			object: [
				'title' => (string)$file->getName(),
				'kind' => $kind,
				'fileRef' => $path,
				'courseId' => $context['courseId'],
				'lessonId' => $context['lessonId'],
				'tenant_id' => $context['tenantId'],
			]
		);
	}//end originalMaterial()

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
