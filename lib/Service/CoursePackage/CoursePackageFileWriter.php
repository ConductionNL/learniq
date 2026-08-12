<?php

/**
 * Scholiq Course Package File Writer
 *
 * The nc:files plumbing every course-package importer shares: resolving a
 * package-relative file (or a base64 payload) into the importing tenant's
 * Scholiq course-imports folder, per design.md's "app does not store file
 * bytes, OR does" contract, plus the temp-directory cleanup the orchestrator
 * runs after every import.
 *
 * @category Service
 * @package  OCA\Scholiq\Service\CoursePackage
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
 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service\CoursePackage;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Writes resolved course-package file bytes into nc:files.
 */
class CoursePackageFileWriter {
	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder NC root folder for writing resolved Material file bytes.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a package-relative file into an nc:files path by writing its
	 * bytes into the importing tenant's Scholiq course-imports folder —
	 * Material's `fileRef` is always an nc:files path, never a package-local
	 * temp path the recipient cannot resolve.
	 *
	 * @param string $dir Extracted package directory.
	 * @param string|null $href Package-relative path to the source file (or a directory, for Moodle).
	 * @param string $importedBy NC user id whose home folder owns the resolved file.
	 * @param string $tenantId Tenant UUID, used to namespace the destination folder.
	 *
	 * @return string|null The nc:files path, or null when the source file could not be resolved.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
	 */
	public function resolveFileRef(string $dir, ?string $href, string $importedBy, string $tenantId): ?string {
		if ($href === null) {
			return null;
		}

		$sourcePath = $dir . '/' . $href;
		if (is_dir($sourcePath) === true) {
			// Directory-shaped source (e.g. a Moodle activity folder) — nothing to attach directly.
			return null;
		}

		if (file_exists($sourcePath) === false) {
			return null;
		}

		$content = (string)file_get_contents($sourcePath);
		return $this->writeBytesToFiles(content: $content, filename: basename($sourcePath), importedBy: $importedBy, tenantId: $tenantId);
	}//end resolveFileRef()

	/**
	 * Write a base64-encoded Material's bytes (from a scholiq-native JSON
	 * export's `contentBase64`) into `nc:files`, same destination convention
	 * `resolveFileRef()` uses for CC/Moodle-sourced files.
	 *
	 * @param string $base64Content Base64-encoded file content.
	 * @param string $title Material title, used to derive a filename.
	 * @param string $importedBy NC user id whose home folder owns the resolved file.
	 * @param string $tenantId Tenant UUID, used to namespace the destination folder.
	 *
	 * @return string|null The nc:files path, or null when the content could not be decoded/written.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
	 */
	public function writeBase64ToFiles(string $base64Content, string $title, string $importedBy, string $tenantId): ?string {
		$decoded = base64_decode($base64Content, strict: true);
		if ($decoded === false) {
			return null;
		}

		$filename = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $title);
		if ($filename === null || $filename === '') {
			$filename = 'material';
		}

		return $this->writeBytesToFiles(content: $decoded, filename: $filename, importedBy: $importedBy, tenantId: $tenantId);
	}//end writeBase64ToFiles()

	/**
	 * Write raw bytes into the importing tenant's Scholiq course-imports
	 * nc:files folder and return the resulting `fileRef` path.
	 *
	 * @param string $content Raw file bytes.
	 * @param string $filename Destination filename (already sanitised by the caller).
	 * @param string $importedBy NC user id whose home folder owns the resolved file.
	 * @param string $tenantId Tenant UUID, used to namespace the destination folder.
	 *
	 * @return string|null The nc:files path, or null on failure.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
	 */
	public function writeBytesToFiles(string $content, string $filename, string $importedBy, string $tenantId): ?string {
		try {
			$tenantSegment = 'default';
			if ($tenantId !== '') {
				$tenantSegment = $tenantId;
			}

			$ncBaseDir = 'Scholiq/' . $tenantSegment . '/course-imports';
			$ncPath = $ncBaseDir . '/' . $filename;

			$userFolder = $this->rootFolder->getUserFolder($importedBy);
			$this->ensureFolder(userFolder: $userFolder, path: $ncBaseDir);
			$this->putFileContent(userFolder: $userFolder, path: $ncPath, content: $content);

			return '/' . $ncPath;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[CoursePackageFileWriter] Could not write resolved file "{filename}": {msg}',
				['filename' => $filename, 'msg' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end writeBytesToFiles()

	/**
	 * Recursively remove a directory and its contents.
	 *
	 * @param string $dir Absolute path to the directory.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#security--privacy-posture
	 */
	public function removeDirectory(string $dir): void {
		if (is_dir($dir) === false) {
			return;
		}

		$items = scandir($dir);
		if ($items === false) {
			return;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$path = $dir . '/' . $item;
			if (is_dir($path) === true) {
				$this->removeDirectory(dir: $path);
				continue;
			}

			unlink($path);
		}
	}//end removeDirectory()

	/**
	 * Write (or overwrite) a file node at the given nc:files path.
	 *
	 * @param Folder $userFolder The root user folder.
	 * @param string $path Folder-relative destination path.
	 * @param string $content Raw file bytes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
	 */
	private function putFileContent(Folder $userFolder, string $path, string $content): void {
		if ($userFolder->nodeExists($path) === false) {
			$userFolder->newFile($path, $content);
			return;
		}

		$existingNode = $userFolder->get($path);
		if ($existingNode instanceof File) {
			$existingNode->putContent($content);
		}
	}//end putFileContent()

	/**
	 * Ensure a nested nc:files folder path exists under the given folder.
	 *
	 * @param Folder $userFolder The root user folder.
	 * @param string $path Slash-separated relative path to ensure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
	 */
	private function ensureFolder(Folder $userFolder, string $path): void {
		$segments = array_filter(explode('/', $path));
		$current = '';
		foreach ($segments as $segment) {
			$prefix = '';
			if ($current !== '') {
				$prefix = $current . '/';
			}

			$current = $prefix . $segment;

			try {
				$userFolder->get($current);
			} catch (NotFoundException $e) {
				$userFolder->newFolder($current);
			}
		}
	}//end ensureFolder()
}//end class
