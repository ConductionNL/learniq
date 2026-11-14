<?php

/**
 * Learniq course package archive.
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
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

/**
 * An uploaded archive-shaped package (Common Cartridge or Moodle backup) and
 * the directory it is extracted into.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */
final class ArchivePackage {

	/**
	 * Constructor.
	 *
	 * @param string $format      Detected source format.
	 * @param string $packagePath Absolute path to the uploaded archive.
	 * @param string $tmpDir      Extraction directory.
	 */
	public function __construct(
		public readonly string $format,
		public readonly string $packagePath,
		public readonly string $tmpDir,
	) {
	}//end __construct()

	/**
	 * A failure message with this package's server paths replaced by plain words, fit for the stored report.
	 *
	 * @param string $message The failure message.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-corrupt-or-unrecognised-archive-fails-loudly-not-silently
	 */
	public function withoutPaths(string $message): string {
		return str_replace(
			search: ["'{$this->tmpDir}'", "'{$this->packagePath}'", $this->tmpDir, $this->packagePath],
			replace: ['the package', 'the uploaded archive', 'the package', 'the uploaded archive'],
			subject: $message
		);
	}//end withoutPaths()
}//end class
