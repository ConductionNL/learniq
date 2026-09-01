<?php

/**
 * Learniq Native JSON Course Importer
 *
 * Reads and re-imports a scholiq-native JSON export
 * (`CoursePackageExportService::exportScholiqJson()`'s own output) — the
 * lossless round-trip path the design's format-support matrix describes.
 *
 * The Course, its direct child Courses (re-parented onto the newly created
 * Course, not the stale exported id), Lessons, Materials (with
 * `contentBase64` bytes written back into `nc:files`), and Rubrics all
 * round-trip cleanly. Assessments are recreated as shells — their `itemRefs`
 * are source-tenant UUIDs that do not resolve in the destination tenant, so
 * they are reported `degraded` rather than silently relinked to the wrong
 * Items; LtiToolPlacements degrade the same way import from a CC package does
 * (no live OpenConnector deployment carried).
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
 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

/**
 * Re-imports a scholiq-native JSON course export.
 */
class LearniqJsonCourseImporter {

	private const SOURCE_FORMAT = 'scholiq-json';

	/**
	 * Constructor.
	 *
	 * @param CoursePackageObjectWriter $objectWriter Creates the learniq objects the export materialises.
	 * @param CoursePackageFileWriter $fileWriter Writes `contentBase64` Material bytes into nc:files.
	 * @param CoursePackageImportReporter $reporter Builds and persists the import report.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CoursePackageObjectWriter $objectWriter,
		private readonly CoursePackageFileWriter $fileWriter,
		private readonly CoursePackageImportReporter $reporter,
	) {
	}//end __construct()

	/**
	 * Read a scholiq-native JSON package and persist its import report.
	 *
	 * @param string $packagePath Absolute path to the uploaded JSON file.
	 * @param string $sourceFilename Original filename.
	 * @param string $importedBy NC user id of the caller.
	 * @param string $importedAt ISO-8601 timestamp already resolved by the orchestrator.
	 * @param string $tenantId Tenant UUID stamped on every created object.
	 *
	 * @return array<string, mixed> The persisted `CoursePackageImportReport`.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
	 */
	public function importPackage(
		string $packagePath,
		string $sourceFilename,
		string $importedBy,
		string $importedAt,
		string $tenantId,
	): array {
		$raw = (string)file_get_contents($packagePath);
		$tree = json_decode($raw, associative: true);

		if (is_array($tree) === false || isset($tree['course']) === false) {
			return $this->reporter->persistReport(
				sourceFormat: self::SOURCE_FORMAT,
				sourceFilename: $sourceFilename,
				importedBy: $importedBy,
				importedAt: $importedAt,
				tenantId: $tenantId,
				courseId: null,
				lifecycle: 'failed',
				entries: [],
				errorMessage: 'File is not a valid scholiq-native course export (missing top-level "course" object).',
			);
		}

		$entries = [];
		$courseId = $this->importTree(tree: $tree, importedBy: $importedBy, tenantId: $tenantId, entries: $entries);

		return $this->reporter->persistReport(
			sourceFormat: self::SOURCE_FORMAT,
			sourceFilename: $sourceFilename,
			importedBy: $importedBy,
			importedAt: $importedAt,
			tenantId: $tenantId,
			courseId: $courseId,
			lifecycle: $this->reporter->resolveLifecycle(entries: $entries),
			entries: $entries,
			errorMessage: null,
		);
	}//end importPackage()

	/**
	 * Materialise a scholiq-native JSON export's object graph.
	 *
	 * @param array<string, mixed> $tree The decoded scholiq-native JSON tree.
	 * @param string $importedBy NC user id (nc:files owner for resolved Material bytes).
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return string|null UUID of the re-created top-level Course.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
	 */
	private function importTree(array $tree, string $importedBy, string $tenantId, array &$entries): ?string {
		$courseData = (array)($tree['course'] ?? []);
		$courseId = $this->objectWriter->createCourse(
			title: (string)($courseData['name'] ?? 'Imported course'),
			parentCourseId: null,
			tenantId: $tenantId
		);
		$entries[] = $this->reporter->entry(
			resourceIdentifier: $this->sourceIdentifier(row: $courseData, fallback: 'course'),
			resourceType: 'course',
			title: (string)($courseData['name'] ?? ''),
			outcome: 'imported',
			targetType: 'course',
			targetId: $courseId,
			reason: null,
		);

		$this->importChildCourses(tree: $tree, courseId: $courseId, tenantId: $tenantId, entries: $entries);
		$this->importLessons(tree: $tree, courseId: $courseId, tenantId: $tenantId, entries: $entries);
		$this->importMaterials(tree: $tree, courseId: $courseId, importedBy: $importedBy, tenantId: $tenantId, entries: $entries);
		$this->importRubrics(tree: $tree, tenantId: $tenantId, entries: $entries);
		$this->importAssessments(tree: $tree, courseId: $courseId, tenantId: $tenantId, entries: $entries);
		$this->importLtiPlacements(tree: $tree, courseId: $courseId, tenantId: $tenantId, entries: $entries);

		return $courseId;
	}//end importTree()

	/**
	 * Re-create the export's direct child Courses under the new Course.
	 *
	 * @param array<string, mixed> $tree The decoded scholiq-native JSON tree.
	 * @param string|null $courseId Re-created top-level Course UUID.
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
	 */
	private function importChildCourses(array $tree, ?string $courseId, string $tenantId, array &$entries): void {
		foreach ((array)($tree['childCourses'] ?? []) as $child) {
			$child = (array)$child;
			$childCourseId = $this->objectWriter->createCourse(
				title: (string)($child['name'] ?? 'Imported module'),
				parentCourseId: $courseId,
				tenantId: $tenantId
			);
			$entries[] = $this->reporter->entry(
				resourceIdentifier: $this->sourceIdentifier(row: $child, fallback: 'child-course'),
				resourceType: 'course',
				title: (string)($child['name'] ?? ''),
				outcome: 'imported',
				targetType: 'course',
				targetId: $childCourseId,
				reason: null,
			);
		}
	}//end importChildCourses()

	/**
	 * Re-create the export's Lessons under the new Course.
	 *
	 * @param array<string, mixed> $tree The decoded scholiq-native JSON tree.
	 * @param string|null $courseId Re-created top-level Course UUID.
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
	 */
	private function importLessons(array $tree, ?string $courseId, string $tenantId, array &$entries): void {
		foreach ((array)($tree['lessons'] ?? []) as $lesson) {
			$lesson = (array)$lesson;
			$lessonId = $this->objectWriter->createLesson(
				courseId: $courseId,
				title: (string)($lesson['name'] ?? ''),
				order: (int)($lesson['order'] ?? 0),
				contentType: (string)($lesson['contentType'] ?? 'text'),
				contentRef: (string)($lesson['contentRef'] ?? ''),
				tenantId: $tenantId
			);
			$entries[] = $this->reporter->entry(
				resourceIdentifier: $this->sourceIdentifier(row: $lesson, fallback: 'lesson'),
				resourceType: 'lesson',
				title: (string)($lesson['name'] ?? ''),
				outcome: 'imported',
				targetType: 'lesson',
				targetId: $lessonId,
				reason: null,
			);
		}
	}//end importLessons()

	/**
	 * Re-create the export's Materials, writing any `contentBase64` bytes back
	 * into `nc:files` so the destination tenant can resolve them.
	 *
	 * @param array<string, mixed> $tree The decoded scholiq-native JSON tree.
	 * @param string|null $courseId Re-created top-level Course UUID.
	 * @param string $importedBy NC user id (nc:files owner for resolved Material bytes).
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
	 */
	private function importMaterials(array $tree, ?string $courseId, string $importedBy, string $tenantId, array &$entries): void {
		foreach ((array)($tree['materials'] ?? []) as $material) {
			$material = (array)$material;
			$fileRef = null;
			if (empty($material['contentBase64']) === false) {
				$fileRef = $this->fileWriter->writeBase64ToFiles(
					base64Content: (string)$material['contentBase64'],
					title: (string)($material['title'] ?? 'material'),
					importedBy: $importedBy,
					tenantId: $tenantId,
				);
			}

			$materialId = $this->objectWriter->createMaterial(
				title: (string)($material['title'] ?? ''),
				kind: (string)($material['kind'] ?? 'document'),
				fileRef: $fileRef,
				url: $material['url'] ?? null,
				courseId: $courseId,
				tenantId: $tenantId,
			);
			$entries[] = $this->reporter->entry(
				resourceIdentifier: $this->sourceIdentifier(row: $material, fallback: 'material'),
				resourceType: 'material',
				title: (string)($material['title'] ?? ''),
				outcome: 'imported',
				targetType: 'material',
				targetId: $materialId,
				reason: null,
			);
		}//end foreach
	}//end importMaterials()

	/**
	 * Re-create the export's Rubrics.
	 *
	 * @param array<string, mixed> $tree The decoded scholiq-native JSON tree.
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
	 */
	private function importRubrics(array $tree, string $tenantId, array &$entries): void {
		foreach ((array)($tree['rubrics'] ?? []) as $rubric) {
			$rubric = (array)$rubric;
			$rubricId = $this->objectWriter->create(
				schema: 'rubric',
				object: [
					'name' => $rubric['name'] ?? '',
					'criteria' => $rubric['criteria'] ?? [],
					'maxPoints' => $rubric['maxPoints'] ?? 100,
					'lifecycle' => 'draft',
					'tenant_id' => $tenantId,
				]
			);
			$entries[] = $this->reporter->entry(
				resourceIdentifier: $this->sourceIdentifier(row: $rubric, fallback: 'rubric'),
				resourceType: 'rubric',
				title: (string)($rubric['name'] ?? ''),
				outcome: 'imported',
				targetType: 'rubric',
				targetId: $rubricId,
				reason: null,
			);
		}//end foreach
	}//end importRubrics()

	/**
	 * Re-create the export's Assessments as degraded shells — their `itemRefs`
	 * are source-tenant UUIDs that do not resolve here.
	 *
	 * @param array<string, mixed> $tree The decoded scholiq-native JSON tree.
	 * @param string|null $courseId Re-created top-level Course UUID.
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
	 */
	private function importAssessments(array $tree, ?string $courseId, string $tenantId, array &$entries): void {
		foreach ((array)($tree['assessments'] ?? []) as $assessment) {
			$assessment = (array)$assessment;
			$assessmentId = $this->objectWriter->create(
				schema: 'exam',
				object: [
					'title' => $assessment['title'] ?? '',
					'courseId' => $courseId,
					'lifecycle' => 'draft',
					'tenant_id' => $tenantId,
				]
			);
			$entries[] = $this->reporter->entry(
				resourceIdentifier: $this->sourceIdentifier(row: $assessment, fallback: 'assessment'),
				resourceType: 'assessment',
				title: (string)($assessment['title'] ?? ''),
				outcome: 'degraded',
				targetType: 'assessment',
				targetId: $assessmentId,
				reason: 'Item references are source-tenant UUIDs and do not resolve here; '
					. 're-import the ItemBank via QTI package import to relink.',
			);
		}//end foreach
	}//end importAssessments()

	/**
	 * Re-create the export's LtiToolPlacements as degraded placements — no
	 * live OpenConnector deployment binding travels in the package.
	 *
	 * @param array<string, mixed> $tree The decoded scholiq-native JSON tree.
	 * @param string|null $courseId Re-created top-level Course UUID.
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-an-lti-resource-becomes-a-placement-not-an-inline-link
	 */
	private function importLtiPlacements(array $tree, ?string $courseId, string $tenantId, array &$entries): void {
		foreach ((array)($tree['ltiPlacements'] ?? []) as $placement) {
			$placement = (array)$placement;
			$placementId = $this->objectWriter->createLtiPlacement(courseId: $courseId, tenantId: $tenantId);
			$entries[] = $this->reporter->entry(
				resourceIdentifier: $this->sourceIdentifier(row: $placement, fallback: 'lti-placement'),
				resourceType: 'lti-tool-placement',
				title: 'LTI tool placement',
				outcome: 'degraded',
				targetType: 'lti-tool-placement',
				targetId: $placementId,
				reason: 'LTI placement re-created without a configured OpenConnector deployment; '
					. 'an admin must bind a deployment before this tool can be launched.',
			);
		}
	}//end importLtiPlacements()

	/**
	 * Resolve the source identifier a re-imported row is reported under.
	 *
	 * @param array<string, mixed> $row One decoded export row.
	 * @param string $fallback Identifier to use when the row carries neither `id` nor `uuid`.
	 *
	 * @return string The source identifier.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
	 */
	private function sourceIdentifier(array $row, string $fallback): string {
		return (string)($row['id'] ?? $row['uuid'] ?? $fallback);
	}//end sourceIdentifier()
}//end class
