<?php

/**
 * Learniq Lesson Onboarding File Listener
 *
 * Notices a Word or PowerPoint file that lands directly in a teacher's lesson
 * onboarding folder and records it as a `LessonOnboardingFile` row in state
 * `detected` (office-file-lesson-onboarding). The row's declared notification
 * tells the teacher; nothing else happens until the teacher confirms the file
 * on the review page (decision D17). The file's content is never opened here.
 *
 * ADR-031 legitimate exception: a Nextcloud file event, which no schema
 * declaration can listen to. Follows the fleet's NodeCreatedEvent pattern
 * (integriq NextcloudFileEventListener, OpenRegister FileChangeListener):
 * the cheapest filters run first, and no failure reaches the upload that
 * raised the event.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-new-word-or-powerpoint-file-in-the-folder-is-detected-and-the-teacher-is-notified-and-nothing-is-read
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Learniq\Service\LessonOnboarding\OnboardingFolderSetting;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\File;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records Word and PowerPoint files dropped in a teacher's onboarding folder.
 *
 * @template-implements IEventListener<Event>
 */
class LessonOnboardingFileListener implements IEventListener {

	/**
	 * OpenRegister register slug.
	 *
	 * @var string
	 */
	public const REGISTER = 'learniq';

	/**
	 * OpenRegister schema slug of the detection row.
	 *
	 * @var string
	 */
	public const SCHEMA = 'lesson-onboarding-file';

	/**
	 * File extensions this feature reads, mapped to the row's `format`.
	 *
	 * @var array<string, string>
	 */
	private const FORMATS = ['docx' => 'docx', 'pptx' => 'pptx'];

	/**
	 * Constructor.
	 *
	 * @param OnboardingFolderSetting $folderSetting The teacher's chosen folder.
	 * @param ObjectService $objectService Writes the detection row.
	 * @param LoggerInterface $logger Logs a failure instead of failing the upload.
	 */
	public function __construct(
		private readonly OnboardingFolderSetting $folderSetting,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The row `format` for a file name, or null when this feature does not read it.
	 *
	 * @param string $fileName The file name.
	 *
	 * @return string|null `docx`, `pptx` or null.
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-file-elsewhere-or-of-another-type-is-ignored
	 */
	public static function formatOf(string $fileName): ?string {
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		return (self::FORMATS[$extension] ?? null);
	}//end formatOf()

	/**
	 * Handle a created node: record it when it is a Word or PowerPoint file
	 * directly inside its owner's onboarding folder.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-teacher-drops-a-word-file-in-the-folder
	 */
	public function handle(Event $event): void {
		if (($event instanceof NodeCreatedEvent) === false) {
			return;
		}

		$node = $event->getNode();
		if (($node instanceof File) === false) {
			return;
		}

		$format = self::formatOf(fileName: (string)$node->getName());
		if ($format === null) {
			return;
		}

		try {
			$this->record(file: $node, format: $format);
		} catch (Throwable $e) {
			// Runs inside the file operation that raised the event: a failure here
			// must never unwind into, and fail, the teacher's upload.
			$this->logger->warning(
				'[LessonOnboardingFileListener] Could not record file {fileId}: {message}',
				['fileId' => $node->getId(), 'message' => $e->getMessage(), 'exception' => get_class($e)]
			);
		}
	}//end handle()

	/**
	 * Record the file when its owner watches its parent folder and it is new.
	 *
	 * @param File $file The created file.
	 * @param string $format `docx` or `pptx`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-new-word-or-powerpoint-file-in-the-folder-is-detected-and-the-teacher-is-notified-and-nothing-is-read
	 */
	private function record(File $file, string $format): void {
		$owner = $file->getOwner();
		if (($owner instanceof IUser) === false) {
			return;
		}

		$teacherId = $owner->getUID();
		$folderId = $this->folderSetting->folderId(userId: $teacherId);
		if ($folderId === 0 || (int)$file->getParent()->getId() !== $folderId) {
			return;
		}

		$fileId = (int)$file->getId();
		if ($fileId <= 0 || $this->alreadyRecorded(teacherId: $teacherId, fileId: $fileId) === true) {
			return;
		}

		// Written without RBAC as the owner: the upload may come from someone the
		// folder is shared with, and the checks above already proved the file
		// sits in the owner's own folder. Reading the row back is self-only.
		$this->objectService->saveObject(
			object: [
				'teacherId' => $teacherId,
				'fileId' => $fileId,
				'fileName' => (string)$file->getName(),
				'filePath' => $this->relativePath(file: $file, teacherId: $teacherId),
				'mimeType' => (string)$file->getMimeType(),
				'format' => $format,
				'detectedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
				'tenant_id' => $this->folderSetting->tenantOf(userId: $teacherId),
			],
			register: self::REGISTER,
			schema: self::SCHEMA,
			_rbac: false,
			_multitenancy: false,
			currentUser: $owner,
		);
	}//end record()

	/**
	 * Whether a row for this file and teacher already exists.
	 *
	 * @param string $teacherId The teacher.
	 * @param int $fileId The file id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-new-word-or-powerpoint-file-in-the-folder-is-detected-and-the-teacher-is-notified-and-nothing-is-read
	 */
	private function alreadyRecorded(string $teacherId, int $fileId): bool {
		$existing = $this->objectService->findAll(
			config: [
				'register' => self::REGISTER,
				'schema' => self::SCHEMA,
				'filters' => ['teacherId' => $teacherId, 'fileId' => $fileId],
				'limit' => 1,
			],
			_rbac: false,
			_multitenancy: false,
		);

		return empty($existing) === false;
	}//end alreadyRecorded()

	/**
	 * The file's path inside its owner's files (`/Lessen inbox/Breuken.docx`).
	 *
	 * @param File $file The file.
	 * @param string $teacherId The owner.
	 *
	 * @return string The relative path, or the bare file name when it cannot be derived.
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-new-word-or-powerpoint-file-in-the-folder-is-detected-and-the-teacher-is-notified-and-nothing-is-read
	 */
	private function relativePath(File $file, string $teacherId): string {
		$prefix = '/' . $teacherId . '/files';
		$path = (string)$file->getPath();
		if (str_starts_with($path, $prefix . '/') === true) {
			return substr($path, strlen($prefix));
		}

		return '/' . (string)$file->getName();
	}//end relativePath()
}//end class
