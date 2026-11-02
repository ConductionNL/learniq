<?php

/**
 * Learniq course package output.
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
 * What every course package importer writes to: the Learniq objects, the
 * files in nc:files and the report entries.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */
final class CoursePackageOutput {

	/**
	 * Constructor.
	 *
	 * @param CoursePackageObjectWriter   $objectWriter Creates the Learniq objects a package materialises.
	 * @param CoursePackageFileWriter     $fileWriter   Resolves package-relative file bytes into nc:files.
	 * @param CoursePackageImportReporter $reporter     Builds the report entry rows.
	 */
	public function __construct(
		public readonly CoursePackageObjectWriter $objectWriter,
		public readonly CoursePackageFileWriter $fileWriter,
		public readonly CoursePackageImportReporter $reporter,
	) {
	}//end __construct()
}//end class
