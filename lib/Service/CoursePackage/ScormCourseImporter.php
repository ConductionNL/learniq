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

use OCA\Learniq\Service\FolderFileWriter;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
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
	 * @param IRootFolder                 $rootFolder   The importing user's Files.
	 * @param LoggerInterface             $logger       Notes a file that could not be written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ScormManifestParser $parser,
		private readonly CoursePackageObjectWriter $objectWriter,
		private readonly CoursePackageImportReporter $reporter,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
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
			return $this->persist(origin: $origin, courseId: null, entries: [], error: 'The SCORM manifest could not be read: ' . $e->getMessage());
		}

		$title = $manifest['title'];
		if ($title === '') {
			$title = $origin->sourceFilename;
		}

		$courseId = $this->objectWriter->createCourse(title: $title, parentCourseId: null, tenantId: $origin->tenantId);
		if ($courseId === null) {
			return $this->persist(origin: $origin, courseId: null, entries: [], error: 'The course could not be created.');
		}

		$folder  = $this->courseFolder(tenantId: $origin->tenantId, courseId: $courseId);
		$entries = $this->unpack(packagePath: $packagePath, folder: $folder, importedBy: $origin->importedBy);
		foreach ($manifest['scos'] as $index => $sco) {
			$entries[] = $this->lesson(
				sco: $sco,
				order: ($index + 1),
				version: $manifest['version'],
				course: ['id' => $courseId, 'folder' => $folder],
				origin: $origin
			);
		}

		return $this->persist(origin: $origin, courseId: $courseId, entries: $entries, error: null);
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
		$normalised = str_replace('\\', '/', $name);
		if ($normalised === '' || $normalised[0] === '/' || preg_match('#^[A-Za-z]:#', $normalised) === 1 || str_contains($normalised, "\0") === true) {
			return false;
		}

		return in_array('..', explode('/', $normalised), true) === false;
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
	 */
	private function unpack(string $packagePath, string $folder, string $importedBy): array {
		$zip = new ZipArchive();
		if ($zip->open($packagePath) !== true) {
			return [];
		}

		$dropped = [];
		$files   = new FolderFileWriter(userFolder: $this->rootFolder->getUserFolder($importedBy));
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$name = (string)$zip->getNameIndex($index);
			if (str_ends_with($name, '/') === true) {
				continue;
			}

			if (self::staysInside(name: $name) === false) {
				$dropped[] = $this->reporter->entry(
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
				continue;
			}

			$this->write(files: $files, path: $folder . '/' . ltrim($name, './'), content: (string)$zip->getFromIndex($index));
		}

		$zip->close();

		return $dropped;
	}//end unpack()

	/**
	 * Write one file, creating its folder; a failure is logged, not thrown.
	 *
	 * @param FolderFileWriter $files   The writer.
	 * @param string           $path    The file's path.
	 * @param string           $content Its bytes.
	 *
	 * @return void
	 */
	private function write(FolderFileWriter $files, string $path, string $content): void {
		try {
			$files->ensureFolder(path: dirname($path));
			$files->put(path: $path, content: $content);
		} catch (Throwable $e) {
			$this->logger->warning('[ScormCourseImporter] Could not write {path}: {msg}', ['path' => $path, 'msg' => $e->getMessage()]);
		}
	}//end write()

	/**
	 * Create one SCO's lesson and its report entry.
	 *
	 * @param array{identifier: string, title: string, href: string} $sco     The SCO.
	 * @param int                                                    $order   Its place in the manifest.
	 * @param string                                                 $version The manifest's SCORM version.
	 * @param array{id: string, folder: string}                      $course  The course and its folder.
	 * @param ImportOrigin                                           $origin  The import.
	 *
	 * @return array<string, mixed> The report entry.
	 */
	private function lesson(array $sco, int $order, string $version, array $course, ImportOrigin $origin): array {
		$contentType = 'scorm12';
		if ($version === ScormManifestParser::VERSION_2004) {
			$contentType = 'scorm2004';
		}

		$lessonId = $this->objectWriter->createLesson(
			lesson: [
				'courseId' => $course['id'],
				'title' => $sco['title'],
				'order' => $order,
				'contentType' => $contentType,
				'contentRef' => '/' . $course['folder'] . '/' . ltrim($sco['href'], './'),
			],
			tenantId: $origin->tenantId
		);

		$result = ['outcome' => 'imported', 'targetType' => 'lesson', 'targetId' => $lessonId, 'reason' => null];
		if ($lessonId === null) {
			$result = ['outcome' => 'dropped', 'targetType' => null, 'targetId' => null, 'reason' => 'The lesson could not be created.'];
		} else if ($contentType === 'scorm2004') {
			$result = ['outcome' => 'degraded', 'targetType' => 'lesson', 'targetId' => $lessonId, 'reason' => self::NOT_PLAYED];
		}

		return $this->reporter->entry(resourceIdentifier: $sco['identifier'], resourceType: 'sco', title: $sco['title'], result: $result);
	}//end lesson()

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
	 * Persist the report.
	 *
	 * @param ImportOrigin                  $origin   The import.
	 * @param string|null                   $courseId The course, when created.
	 * @param list<array<string, mixed>>    $entries  The entries.
	 * @param string|null                   $error    The refusal, when nothing was imported.
	 *
	 * @return array<string, mixed>
	 */
	private function persist(ImportOrigin $origin, ?string $courseId, array $entries, ?string $error): array {
		return $this->reporter->persistReport(
			origin: $origin,
			outcome: new ImportReportOutcome(sourceFormat: self::SOURCE_FORMAT, courseId: $courseId, entries: $entries, errorMessage: $error),
		);
	}//end persist()
}//end class
