<?php

/**
 * Scholiq Moodle Course Importer
 *
 * Extracts a Moodle backup (`.mbz`, gzipped tar), parses its manifest and
 * materialises the section/activity structure, routing every module via
 * `MoodleActivityRouter`.
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing tar/XML from
 * an external interchange format cannot be expressed declaratively.
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
 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-moodle-backup-materialises-the-same-structural-shapes
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

use OCA\Learniq\Service\MbzExtractor;
use OCA\Learniq\Service\MoodleBackupParser;

/**
 * Materialises a Moodle backup's section/activity structure.
 */
class MoodleCourseImporter {
	/**
	 * Constructor.
	 *
	 * @param MbzExtractor $mbzExtractor Moodle `.mbz` (gzipped tar) extractor.
	 * @param MoodleBackupParser $moodleParser Moodle backup manifest parser.
	 * @param MoodleActivityRouter $activityRouter Per-activity routing to scholiq targets.
	 * @param CoursePackageObjectWriter $objectWriter Creates the scholiq objects the backup materialises.
	 * @param CoursePackageImportReporter $reporter Builds the report entry rows.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MbzExtractor $mbzExtractor,
		private readonly MoodleBackupParser $moodleParser,
		private readonly MoodleActivityRouter $activityRouter,
		private readonly CoursePackageObjectWriter $objectWriter,
		private readonly CoursePackageImportReporter $reporter,
	) {
	}//end __construct()

	/**
	 * Extract the backup into `$targetDir` and parse its manifest.
	 *
	 * @param string $packagePath Absolute path to the uploaded `.mbz` archive.
	 * @param string $targetDir Directory to extract into.
	 *
	 * @return array<string, mixed> `MoodleBackupParser::parseManifest()` result.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-moodle-backup-materialises-the-same-structural-shapes
	 */
	public function extractAndParse(string $packagePath, string $targetDir): array {
		$this->mbzExtractor->extract(mbzPath: $packagePath, targetDir: $targetDir);

		return $this->moodleParser->parseManifest(dir: $targetDir);
	}//end extractAndParse()

	/**
	 * Materialise a parsed Moodle backup manifest.
	 *
	 * @param string $dir Extracted Moodle backup directory.
	 * @param array<string, mixed> $manifest `MoodleBackupParser::parseManifest()` result.
	 * @param string $importedBy NC user id (used for Assignment ownership context only).
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return string|null UUID of the top-level Course, or null if none was created.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-moodle-backup-materialises-the-same-structural-shapes
	 */
	public function importManifest(string $dir, array $manifest, string $importedBy, string $tenantId, array &$entries): ?string {
		$courseId = $this->objectWriter->createCourse(title: 'Imported Moodle course', parentCourseId: null, tenantId: $tenantId);

		foreach ($manifest['sectionNodes'] as $section) {
			$entries[] = $this->reporter->entry(
				resourceIdentifier: $section['identifier'],
				resourceType: 'section',
				title: $section['title'],
				outcome: 'imported',
				targetType: 'course',
				targetId: $courseId,
				reason: null,
			);
		}

		foreach ($manifest['activities'] as $activity) {
			$this->activityRouter->routeActivity(
				activity: $activity,
				dir: $dir,
				courseId: $courseId,
				importedBy: $importedBy,
				tenantId: $tenantId,
				entries: $entries
			);
		}

		return $courseId;
	}//end importManifest()
}//end class
