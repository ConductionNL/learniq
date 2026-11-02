<?php

/**
 * Learniq course package importers.
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
 * The importer for each course package format.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */
final class CoursePackageImporters {

	/**
	 * Constructor.
	 *
	 * @param CommonCartridgeCourseImporter $commonCartridge Common Cartridge extraction and materialisation.
	 * @param MoodleCourseImporter          $moodle          Moodle backup extraction and materialisation.
	 * @param LearniqJsonCourseImporter     $learniqJson     The learniq-native JSON round-trip import.
	 */
	public function __construct(
		public readonly CommonCartridgeCourseImporter $commonCartridge,
		public readonly MoodleCourseImporter $moodle,
		public readonly LearniqJsonCourseImporter $learniqJson,
	) {
	}//end __construct()
}//end class
