<?php

/**
 * Learniq SCORM Course Importer
 *
 * Imports a SCORM 1.2 or SCORM 2004 zip (content-scorm-package-upload): one
 * course, one lesson per SCO in manifest order with `contentType` `scorm12`
 * or `scorm2004` and `contentRef` pointing at the SCO's unpacked launch file.
 * The package is unpacked once into the course's folder in Nextcloud Files;
 * a zip entry that would land outside it (`..`, an absolute path) is never
 * written and is reported as dropped. A SCORM 2004 SCO is imported but
 * reported as degraded, because only SCORM 1.2 plays yet.
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

use Throwable;
use ZipArchive;

/**
 * Imports a SCORM package as a course with one lesson per SCO.
 *
 * @spec openspec/changes/content-scorm-package-upload/specs/course-management/spec.md#requirement-the-package-import-accepts-a-scorm-zip
 */
class ScormCourseImporter {

	/**
	 * The report's source format.
	 */
	public const SOURCE_FORMAT = 'scorm';

	/**
	 * The manifest at the package root.
	 */
	private const MANIFEST = 'imsmanifest.xml';

	/**
	 * The reason a SCORM 2004 SCO is degraded.
	 */
	public const NOT_PLAYED = 'SCORM 2004 is not played yet';

	/**
	 * Constructor.
	 *
	 * @param ScormManifestParser         $parser       Reads the manifest.
	 * @param CoursePackageObjectWriter   $objectWriter Creates the course and the lessons.
	 * @param CoursePackageImportReporter $reporter     Builds and persists the report.
	 * @param ScormPackageUnpacker        $unpacker     Unpacks the zip into the course folder.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ScormManifestParser $parser,
		private readonly CoursePackageObjectWriter $objectWriter,
		private readonly CoursePackageImportReporter $reporter,
		private readonly ScormPackageUnpacker $unpacker,
	) {
	}//end __construct()

	/**
	 * Whether a zip is a SCORM package: its root holds an `imsmanifest.xml`
	 * that declares SCORM 1.2 or 2004.
	 *
	 * @param string $packagePath The uploaded zip.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/content-scorm-package-upload/specs/course-management/spec.md#requirement-the-package-import-accepts-a-scorm-zip
	 */
	public function isScormPackage(string $packagePath): bool {
		$manifest = $this->manifestOf(packagePath: $packagePath);

		return $manifest !== null && $this->parser->isScorm(xml: $manifest);
	}//end isScormPackage()

	/**
	 * Import a SCORM zip and persist its report.
	 *
	 * @param string       $packagePath The uploaded zip.
	 * @param ImportOrigin $origin      Who imported which file, when, for which tenant.
	 *
	 * @return array<string, mixed> The persisted `CoursePackageImportReport`.
	 *
	 * @spec openspec/changes/content-scorm-package-upload/specs/course-management/spec.md#requirement-the-package-import-accepts-a-scorm-zip
	 */
	public function importPackage(string $packagePath, ImportOrigin $origin): array {
		try {
			$manifest = $this->parser->parse(xml: (string)$this->manifestOf(packagePath: $packagePath));
		} catch (Throwable $e) {
			return $this->refuse(origin: $origin, error: 'The SCORM manifest could not be read: ' . $e->getMessage());
		}

		$title    = self::titleOf(manifest: $manifest, origin: $origin);
		$courseId = $this->objectWriter->createCourse(title: $title, parentCourseId: null, tenantId: $origin->tenantId);
		if ($courseId === null) {
			return $this->refuse(origin: $origin, error: 'The course could not be created.');
		}

		$folder  = $this->courseFolder(tenantId: $origin->tenantId, courseId: $courseId);
		$course  = ['id' => $courseId, 'folder' => $folder, 'version' => $manifest['version'], 'tenantId' => $origin->tenantId];
		$entries = $this->unpacker->unpack(packagePath: $packagePath, folder: $course['folder'], importedBy: $origin->importedBy);
		foreach ($manifest['scos'] as $index => $sco) {
			$entries[] = $this->lesson(sco: $sco + ['order' => ($index + 1)], course: $course);
		}

		return $this->report(origin: $origin, courseId: $courseId, entries: $entries);
	}//end importPackage()

	/**
	 * The folder a course's package is unpacked into.
	 *
	 * @param string $tenantId The tenant, or ''.
	 * @param string $courseId The course.
	 *
	 * @return string Folder-relative path, without a leading slash.
	 *
	 * @spec openspec/changes/content-scorm-package-upload/specs/course-management/spec.md#requirement-the-package-import-accepts-a-scorm-zip
	 */
	public function courseFolder(string $tenantId, string $courseId): string {
		$tenant = 'default';
		if ($tenantId !== '') {
			$tenant = $tenantId;
		}

		return 'Learniq/' . $tenant . '/' . $courseId . '/scorm';
	}//end courseFolder()

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
		return ScormPackageUnpacker::staysInside(name: $name);
	}//end staysInside()

	/**
	 * The course title: the manifest's, else the uploaded file's name.
	 *
	 * @param array{title: string} $manifest The parsed manifest.
	 * @param ImportOrigin         $origin   The import.
	 *
	 * @return string
	 */
	private static function titleOf(array $manifest, ImportOrigin $origin): string {
		if ($manifest['title'] === '') {
			return $origin->sourceFilename;
		}

		return $manifest['title'];
	}//end titleOf()

	/**
	 * Create one SCO's lesson and its report entry.
	 *
	 * @param array{identifier: string, title: string, href: string, order: int}  $sco    The SCO and its place in the manifest.
	 * @param array{id: string, folder: string, version: string, tenantId: string} $course The course, its folder, the SCORM version and the tenant.
	 *
	 * @return array<string, mixed> The report entry.
	 */
	private function lesson(array $sco, array $course): array {
		$contentType = self::contentTypeOf(version: $course['version']);
		$lessonId    = $this->objectWriter->createLesson(
			lesson: [
				'courseId' => $course['id'],
				'title' => $sco['title'],
				'order' => $sco['order'],
				'contentType' => $contentType,
				'contentRef' => '/' . $course['folder'] . '/' . ltrim($sco['href'], './'),
			],
			tenantId: $course['tenantId']
		);

		$result = self::lessonResult(lessonId: $lessonId, contentType: $contentType);

		return $this->reporter->entry(resourceIdentifier: $sco['identifier'], resourceType: 'sco', title: $sco['title'], result: $result);
	}//end lesson()

	/**
	 * The lesson content type of a SCORM version.
	 *
	 * @param string $version The manifest's SCORM version.
	 *
	 * @return string
	 */
	private static function contentTypeOf(string $version): string {
		if ($version === ScormManifestParser::VERSION_2004) {
			return 'scorm2004';
		}

		return 'scorm12';
	}//end contentTypeOf()

	/**
	 * The outcome of one SCO: dropped without a lesson, degraded for SCORM 2004, else imported.
	 *
	 * @param string|null $lessonId    The created lesson, or null.
	 * @param string      $contentType Its content type.
	 *
	 * @return array{outcome: string, targetType: string|null, targetId: string|null, reason: string|null}
	 */
	private static function lessonResult(?string $lessonId, string $contentType): array {
		if ($lessonId === null) {
			return ['outcome' => 'dropped', 'targetType' => null, 'targetId' => null, 'reason' => 'The lesson could not be created.'];
		}

		if ($contentType === 'scorm2004') {
			return ['outcome' => 'degraded', 'targetType' => 'lesson', 'targetId' => $lessonId, 'reason' => self::NOT_PLAYED];
		}

		return ['outcome' => 'imported', 'targetType' => 'lesson', 'targetId' => $lessonId, 'reason' => null];
	}//end lessonResult()

	/**
	 * The text of the package's root manifest, or null without one.
	 *
	 * @param string $packagePath The zip.
	 *
	 * @return string|null
	 */
	private function manifestOf(string $packagePath): ?string {
		$zip = new ZipArchive();
		if ($zip->open($packagePath) !== true) {
			return null;
		}

		$xml = $zip->getFromName(self::MANIFEST);
		$zip->close();

		if ($xml === false) {
			return null;
		}

		return $xml;
	}//end manifestOf()

	/**
	 * Persist the report of an import that refused before creating anything.
	 *
	 * @param ImportOrigin $origin The import.
	 * @param string       $error  The refusal.
	 *
	 * @return array<string, mixed>
	 */
	private function refuse(ImportOrigin $origin, string $error): array {
		return $this->reporter->persistReport(
			origin: $origin,
			outcome: new ImportReportOutcome(sourceFormat: self::SOURCE_FORMAT, courseId: null, entries: [], errorMessage: $error),
		);
	}//end refuse()

	/**
	 * Persist the report of a created course.
	 *
	 * @param ImportOrigin               $origin   The import.
	 * @param string                     $courseId The course.
	 * @param list<array<string, mixed>> $entries  The entries.
	 *
	 * @return array<string, mixed>
	 */
	private function report(ImportOrigin $origin, string $courseId, array $entries): array {
		return $this->reporter->persistReport(
			origin: $origin,
			outcome: new ImportReportOutcome(sourceFormat: self::SOURCE_FORMAT, courseId: $courseId, entries: $entries, errorMessage: null),
		);
	}//end report()
}//end class
