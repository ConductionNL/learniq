<?php

/**
 * Learniq Course Package File Writer
 *
 * The nc:files plumbing every course-package importer shares: resolving a
 * package-relative file (or a base64 payload) into the importing tenant's
 * Scholiq course-imports folder, per design.md's "app does not store file
 * bytes, OR does" contract, plus the temp-directory cleanup the orchestrator
 * runs after every import.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CoursePackage
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
 * @spec openspec/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

use OCA\Learniq\Service\FolderFileWriter;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Writes resolved course-package file bytes into nc:files.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
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
	 * @spec openspec/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
	 */
	public function resolveFileRef(string $dir, ?string $href, string $importedBy, string $tenantId): ?string {
		if ($href === null) {
			return null;
		}

		$sourcePath = $this->sourceFile(dir: $dir, href: $href);
		if ($sourcePath === null) {
			return null;
		}

		$content = (string)file_get_contents($sourcePath);
		return $this->writeBytesToFiles(content: $content, filename: basename($sourcePath), importedBy: $importedBy, tenantId: $tenantId);
	}//end resolveFileRef()

	/**
	 * The package file an href points at, or null when it is missing or a directory.
	 *
	 * @param string $dir  Extracted package directory.
	 * @param string $href Package-relative path.
	 *
	 * @return string|null The absolute path of the file.
	 */
	private function sourceFile(string $dir, string $href): ?string {
		$sourcePath = $dir . '/' . $href;
		// A directory-shaped source (e.g. a Moodle activity folder) has nothing to attach directly.
		if (is_dir($sourcePath) === true || file_exists($sourcePath) === false) {
			return null;
		}

		return $sourcePath;
	}//end sourceFile()

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
	 * @spec openspec/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
	 */
	public function writeBase64ToFiles(string $base64Content, string $title, string $importedBy, string $tenantId): ?string {
		$decoded = base64_decode($base64Content, strict: true);
		if ($decoded === false) {
			return null;
		}

		return $this->writeBytesToFiles(content: $decoded, filename: $this->safeFilename(title: $title), importedBy: $importedBy, tenantId: $tenantId);
	}//end writeBase64ToFiles()

	/**
	 * A title as a file name: runs of other characters become one dash; 'material' when nothing is left.
	 *
	 * @param string $title The title.
	 *
	 * @return string
	 */
	private function safeFilename(string $title): string {
		$filename = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $title);
		if ($filename === null || $filename === '') {
			return 'material';
		}

		return $filename;
	}//end safeFilename()

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
	 * @spec openspec/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
	 */
	public function writeBytesToFiles(string $content, string $filename, string $importedBy, string $tenantId): ?string {
		try {
			$ncBaseDir = $this->importFolder(tenantId: $tenantId);
			$ncPath = $ncBaseDir . '/' . $filename;

			$files = new FolderFileWriter(userFolder: $this->rootFolder->getUserFolder($importedBy));
			$files->ensureFolder(path: $ncBaseDir);
			$files->put(path: $ncPath, content: $content);

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
	 * The course-imports folder of a tenant; 'default' when there is no tenant.
	 *
	 * @param string $tenantId Tenant UUID, or ''.
	 *
	 * @return string Folder-relative path.
	 */
	private function importFolder(string $tenantId): string {
		$tenantSegment = 'default';
		if ($tenantId !== '') {
			$tenantSegment = $tenantId;
		}

		return 'Learniq/' . $tenantSegment . '/course-imports';
	}//end importFolder()

	/**
	 * Recursively remove a directory and its contents.
	 *
	 * @param string $dir Absolute path to the directory.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#security--privacy-posture
	 */
	public function removeDirectory(string $dir): void {
		if (is_dir($dir) === false) {
			return;
		}

		foreach ($this->entries(dir: $dir) as $item) {
			$this->removeEntry(path: $dir . '/' . $item);
		}

		rmdir($dir);
	}//end removeDirectory()

	/**
	 * The names in a directory without `.` and `..`; none when it cannot be read.
	 *
	 * @param string $dir Absolute path to the directory.
	 *
	 * @return array<int,string>
	 */
	private function entries(string $dir): array {
		$items = scandir($dir);
		if ($items === false) {
			return [];
		}

		return array_diff($items, ['.', '..']);
	}//end entries()

	/**
	 * Remove one directory entry: a directory's contents, or a file.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return void
	 */
	private function removeEntry(string $path): void {
		if (is_dir($path) === true) {
			$this->removeDirectory(dir: $path);
			return;
		}

		unlink($path);
	}//end removeEntry()

}//end class
