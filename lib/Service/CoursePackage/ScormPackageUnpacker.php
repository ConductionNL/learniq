<?php

/**
 * Learniq SCORM Package Unpacker
 *
 * Unpacks a SCORM zip into a course's folder in the importing user's Files
 * (content-scorm-package-upload). A zip entry that would land outside that
 * folder (`..`, an absolute path, a drive letter, a NUL byte) is never written
 * and comes back as a dropped report entry; a file that cannot be written is
 * logged, not thrown.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CoursePackage
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/content-scorm-package-upload/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

use OCA\Learniq\Service\FolderFileWriter;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;
use ZipArchive;

/**
 * Writes a SCORM zip's entries into a course folder, dropping escaping ones.
 *
 * @spec openspec/changes/content-scorm-package-upload/specs/course-management/spec.md#requirement-the-import-report-is-honest-about-what-does-not-play
 */
class ScormPackageUnpacker {

	/**
	 * Constructor.
	 *
	 * @param IRootFolder                 $rootFolder The importing user's Files.
	 * @param CoursePackageImportReporter $reporter   Builds the dropped entries.
	 * @param LoggerInterface             $logger     Notes a file that could not be written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly CoursePackageImportReporter $reporter,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a zip entry name stays inside the package root.
	 *
	 * @param string $name The entry name.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/content-scorm-package-upload/specs/course-management/spec.md#requirement-the-import-report-is-honest-about-what-does-not-play
	 */
	public static function staysInside(string $name): bool {
		$normalised = str_replace('\\', '/', $name);
		if ($normalised === '' || $normalised[0] === '/' || preg_match('#^[A-Za-z]:#', $normalised) === 1) {
			return false;
		}

		return str_contains($normalised, "\0") === false && in_array('..', explode('/', $normalised), true) === false;
	}//end staysInside()

	/**
	 * Write every entry that stays inside the package root into the folder;
	 * report every other one as dropped.
	 *
	 * @param string $packagePath The zip.
	 * @param string $folder      The course folder.
	 * @param string $importedBy  Whose Files receive it.
	 *
	 * @return list<array<string, mixed>> The dropped entries.
	 *
	 * @spec openspec/changes/content-scorm-package-upload/specs/course-management/spec.md#requirement-the-import-report-is-honest-about-what-does-not-play
	 */
	public function unpack(string $packagePath, string $folder, string $importedBy): array {
		$zip = new ZipArchive();
		if ($zip->open($packagePath) !== true) {
			return [];
		}

		$files   = new FolderFileWriter(userFolder: $this->rootFolder->getUserFolder($importedBy));
		$dropped = [];
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$name = (string)$zip->getNameIndex($index);
			if (self::staysInside(name: $name) === true) {
				$this->writeEntry(files: $files, path: $folder . '/' . ltrim($name, './'), content: (string)$zip->getFromIndex($index));
			} else if (str_ends_with($name, '/') === false) {
				$dropped[] = $this->dropped(name: $name);
			}
		}

		$zip->close();

		return $dropped;
	}//end unpack()

	/**
	 * The report entry of an entry that was not written.
	 *
	 * @param string $name The entry name.
	 *
	 * @return array<string, mixed>
	 */
	private function dropped(string $name): array {
		return $this->reporter->entry(
			resourceIdentifier: $name,
			resourceType: 'file',
			title: $name,
			result: [
				'outcome' => 'dropped',
				'targetType' => null,
				'targetId' => null,
				'reason' => 'The entry points outside the package folder and was not written.',
			]
		);
	}//end dropped()

	/**
	 * Write one file entry, creating its folder; a folder entry is skipped and
	 * a failure is logged, not thrown.
	 *
	 * @param FolderFileWriter $files   The writer.
	 * @param string           $path    The file's path.
	 * @param string           $content Its bytes.
	 *
	 * @return void
	 */
	private function writeEntry(FolderFileWriter $files, string $path, string $content): void {
		if (str_ends_with($path, '/') === true) {
			return;
		}

		try {
			$files->ensureFolder(path: dirname($path));
			$files->put(path: $path, content: $content);
		} catch (Throwable $e) {
			$this->logger->warning('[ScormPackageUnpacker] Could not write {path}: {msg}', ['path' => $path, 'msg' => $e->getMessage()]);
		}
	}//end writeEntry()
}//end class
