<?php

/**
 * Learniq archive extraction target.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-course-lesson-material-hierarchy
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * The file-system side of extracting an uploaded archive (a QTI / Common
 * Cartridge ZIP or a Moodle backup): keep every entry inside the target
 * directory, and remove a working directory again.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-course-lesson-material-hierarchy
 */
final class ExtractionTarget {

	/**
	 * Create the entry's parent directory when it is missing, and tell
	 * whether it resolves inside the target directory (the zip-slip /
	 * tar-slip check).
	 *
	 * @param string $destPath      Where the entry would be written.
	 * @param string $targetDirReal The resolved absolute target directory.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-course-lesson-material-hierarchy
	 */
	public static function parentInside(string $destPath, string $targetDirReal): bool {
		$parentDir = dirname($destPath);
		if (is_dir($parentDir) === false) {
			mkdir(directory: $parentDir, permissions: 0700, recursive: true);
		}

		$resolvedParent = realpath($parentDir);

		return $resolvedParent !== false && str_starts_with($resolvedParent, $targetDirReal) === true;
	}//end parentInside()

	/**
	 * Recursively remove a directory and its contents; a missing directory
	 * is left alone.
	 *
	 * @param string $dir Absolute path to the directory.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-course-lesson-material-hierarchy
	 */
	public static function removeDirectory(string $dir): void {
		if (is_dir($dir) === false) {
			return;
		}

		$items = scandir($dir);
		if ($items === false) {
			return;
		}

		foreach (array_diff($items, ['.', '..']) as $item) {
			$path = $dir . '/' . $item;
			if (is_dir($path) === true) {
				self::removeDirectory(dir: $path);
				continue;
			}

			unlink($path);
		}

		rmdir($dir);
	}//end removeDirectory()
}//end class
