<?php

/**
 * Learniq Onboarding Folder Setting
 *
 * The one folder in a teacher's own Nextcloud files that learniq watches for
 * Word and PowerPoint files to turn into lesson drafts
 * (office-file-lesson-onboarding). Stored per user as the folder's file id,
 * so renaming or moving the folder keeps the choice. The listener reads it on
 * every candidate file; the review page reads and changes it.
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-teacher-chooses-one-lesson-onboarding-folder-in-their-own-files
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

use InvalidArgumentException;
use OCA\Learniq\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;

/**
 * Reads and stores a teacher's lesson onboarding folder.
 */
class OnboardingFolderSetting {

	/**
	 * Per-user config key holding the folder's file id.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'lesson_onboarding_folder_id';

	/**
	 * Constructor.
	 *
	 * @param IConfig $config Per-user settings and the tenant binding.
	 * @param IRootFolder $rootFolder Resolves a user's own files.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * The stored folder id, or 0 when the teacher chose none.
	 *
	 * @param string $userId The teacher.
	 *
	 * @return int The folder's file id, or 0.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-teacher-chooses-one-lesson-onboarding-folder-in-their-own-files
	 */
	public function folderId(string $userId): int {
		$value = $this->config->getUserValue($userId, Application::APP_ID, self::CONFIG_KEY, '');
		if (is_numeric($value) === false) {
			return 0;
		}

		return max(0, (int)$value);
	}//end folderId()

	/**
	 * The stored folder as the review page shows it: its id and its current
	 * path in the teacher's files, or nulls when none is set or it is gone.
	 *
	 * @param string $userId The teacher.
	 *
	 * @return array{folderId: int|null, path: string|null}
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-teacher-chooses-one-lesson-onboarding-folder-in-their-own-files
	 */
	public function describe(string $userId): array {
		$folderId = $this->folderId(userId: $userId);
		if ($folderId === 0) {
			return ['folderId' => null, 'path' => null];
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		foreach ($userFolder->getById($folderId) as $node) {
			if ($node instanceof Folder) {
				return ['folderId' => $folderId, 'path' => $userFolder->getRelativePath($node->getPath())];
			}
		}

		return ['folderId' => null, 'path' => null];
	}//end describe()

	/**
	 * Store a folder, given as a path in the teacher's own files. An empty
	 * path clears the setting. The whole of the teacher's files is refused:
	 * that would treat every Word file in the root as a lesson.
	 *
	 * @param string $userId The teacher.
	 * @param string $path Path relative to the teacher's files.
	 *
	 * @return array{folderId: int|null, path: string|null} The stored folder.
	 *
	 * @throws InvalidArgumentException When the path is not a folder in the teacher's files.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-file-is-not-a-folder
	 */
	public function choose(string $userId, string $path): array {
		$path = trim($path);
		if ($path === '') {
			$this->config->deleteUserValue($userId, Application::APP_ID, self::CONFIG_KEY);
			return ['folderId' => null, 'path' => null];
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		try {
			$node = $userFolder->get($path);
		} catch (NotFoundException $e) {
			throw new InvalidArgumentException('There is no folder at that path in your files.');
		}

		if (($node instanceof Folder) === false) {
			throw new InvalidArgumentException('Choose a folder, not a file.');
		}

		if ($node->getId() === $userFolder->getId()) {
			throw new InvalidArgumentException('Choose one folder inside your files, not all of your files.');
		}

		$this->config->setUserValue($userId, Application::APP_ID, self::CONFIG_KEY, (string)$node->getId());

		return ['folderId' => (int)$node->getId(), 'path' => $userFolder->getRelativePath($node->getPath())];
	}//end choose()

	/**
	 * The tenant a teacher's objects belong to: their per-user binding, else
	 * the instance id. The same resolution CoursePackageImportController and
	 * QtiImportController use.
	 *
	 * @param string $userId The teacher.
	 *
	 * @return string The tenant id.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-new-word-or-powerpoint-file-in-the-folder-is-detected-and-the-teacher-is-notified-and-nothing-is-read
	 */
	public function tenantOf(string $userId): string {
		$tenantId = $this->config->getUserValue($userId, Application::APP_ID, 'tenant_id', '');
		if ($tenantId !== '') {
			return $tenantId;
		}

		return (string)$this->config->getSystemValue('instanceid', '');
	}//end tenantOf()
}//end class
