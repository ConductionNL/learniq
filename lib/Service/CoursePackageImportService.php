<?php

/**
 * Scholiq Course Package Import Service
 *
 * Orchestrates course-package import: it sniffs the uploaded archive's format,
 * hands extraction/parsing and materialisation to the matching format importer
 * (`CommonCartridgeCourseImporter`, `MoodleCourseImporter` or
 * `ScholiqJsonCourseImporter`), and persists the resulting
 * `CoursePackageImportReport` through `CoursePackageImportReporter`. Every
 * source-package resource — supported or not — produces exactly one report
 * entry; nothing is ever silently dropped (the structural anti-Canvas promise,
 * see the proposal's "Why").
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing ZIP/tar/XML
 * from an external interchange format cannot be expressed declaratively.
 * Same exception category as `QtiImportService`, at course-package scope
 * (design.md "Routing: scholiq, not openconnector").
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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
 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCA\Scholiq\Service\CoursePackage\CommonCartridgeCourseImporter;
use OCA\Scholiq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Scholiq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Scholiq\Service\CoursePackage\MoodleCourseImporter;
use OCA\Scholiq\Service\CoursePackage\ScholiqJsonCourseImporter;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates course-package import: format detection, extraction,
 * manifest-driven object creation, and the resulting import report.
 */
class CoursePackageImportService {

	private const FORMAT_COMMON_CARTRIDGE = 'common-cartridge-1.3';
	private const FORMAT_MOODLE_BACKUP = 'moodle-backup';
	private const FORMAT_SCHOLIQ_JSON = 'scholiq-json';

	/**
	 * Constructor.
	 *
	 * @param CommonCartridgeCourseImporter $ccImporter Common Cartridge extraction + materialisation.
	 * @param MoodleCourseImporter $moodleImporter Moodle backup extraction + materialisation.
	 * @param ScholiqJsonCourseImporter $jsonImporter Scholiq-native JSON round-trip import.
	 * @param CoursePackageFileWriter $fileWriter Temp-directory cleanup after every import.
	 * @param CoursePackageImportReporter $reporter Builds and persists the import report.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CommonCartridgeCourseImporter $ccImporter,
		private readonly MoodleCourseImporter $moodleImporter,
		private readonly ScholiqJsonCourseImporter $jsonImporter,
		private readonly CoursePackageFileWriter $fileWriter,
		private readonly CoursePackageImportReporter $reporter,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Import a course package and persist its `CoursePackageImportReport`.
	 *
	 * @param string $packagePath Absolute path to the uploaded archive (tmp upload path).
	 * @param string $sourceFilename Original filename as supplied by the browser.
	 * @param string $importedBy NC user id of the caller.
	 * @param string $tenantId Tenant UUID stamped on every created object.
	 *
	 * @return array<string, mixed> The persisted `CoursePackageImportReport` (includes `uuid`).
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
	 */
	public function import(string $packagePath, string $sourceFilename, string $importedBy, string $tenantId = ''): array {
		$importedAt = $this->reporter->now();
		$tmpDir = sys_get_temp_dir() . '/scholiq_coursepkg_' . bin2hex(random_bytes(8));

		try {
			$format = $this->detectFormat(packagePath: $packagePath, sourceFilename: $sourceFilename);
			if ($format === null) {
				return $this->reporter->persistReport(
					sourceFormat: self::FORMAT_COMMON_CARTRIDGE,
					sourceFilename: $sourceFilename,
					importedBy: $importedBy,
					importedAt: $importedAt,
					tenantId: $tenantId,
					courseId: null,
					lifecycle: 'failed',
					entries: [],
					errorMessage: 'Archive is not a recognised IMS Common Cartridge (ZIP), Moodle backup '
						. '(.mbz / gzipped tar), or scholiq-native JSON package.',
				);
			}

			// The scholiq-native JSON format is a single file, not an archive to
			// extract — it is this change's own lossless export target (design.md
			// format-support matrix), read and walked directly.
			if ($format === self::FORMAT_SCHOLIQ_JSON) {
				return $this->jsonImporter->importPackage(
					packagePath: $packagePath,
					sourceFilename: $sourceFilename,
					importedBy: $importedBy,
					importedAt: $importedAt,
					tenantId: $tenantId,
				);
			}

			mkdir(directory: $tmpDir, permissions: 0700, recursive: true);

			return $this->importArchive(
				format: $format,
				packagePath: $packagePath,
				tmpDir: $tmpDir,
				sourceFilename: $sourceFilename,
				importedBy: $importedBy,
				importedAt: $importedAt,
				tenantId: $tenantId,
			);
		} finally {
			$this->fileWriter->removeDirectory(dir: $tmpDir);
		}//end try
	}//end import()

	/**
	 * Extract, parse and materialise an archive-shaped package (Common
	 * Cartridge or Moodle backup), then persist its report.
	 *
	 * @param string $format Detected source format.
	 * @param string $packagePath Absolute path to the uploaded archive.
	 * @param string $tmpDir Extraction directory.
	 * @param string $sourceFilename Original filename.
	 * @param string $importedBy NC user id of the caller.
	 * @param string $importedAt ISO-8601 timestamp resolved by `import()`.
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string, mixed> The persisted `CoursePackageImportReport`.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	private function importArchive(
		string $format,
		string $packagePath,
		string $tmpDir,
		string $sourceFilename,
		string $importedBy,
		string $importedAt,
		string $tenantId,
	): array {
		try {
			$manifest = $this->parseArchive(format: $format, packagePath: $packagePath, tmpDir: $tmpDir);
		} catch (\Throwable $e) {
			return $this->reporter->persistReport(
				sourceFormat: $format,
				sourceFilename: $sourceFilename,
				importedBy: $importedBy,
				importedAt: $importedAt,
				tenantId: $tenantId,
				courseId: null,
				lifecycle: 'failed',
				entries: [],
				errorMessage: 'Could not extract or parse the package: ' . $e->getMessage(),
			);
		}

		$entries = [];
		$courseId = $this->materialise(
			format: $format,
			dir: $tmpDir,
			manifest: $manifest,
			importedBy: $importedBy,
			tenantId: $tenantId,
			entries: $entries,
		);

		return $this->reporter->persistReport(
			sourceFormat: $format,
			sourceFilename: $sourceFilename,
			importedBy: $importedBy,
			importedAt: $importedAt,
			tenantId: $tenantId,
			courseId: $courseId,
			lifecycle: $this->reporter->resolveLifecycle(entries: $entries),
			entries: $entries,
			errorMessage: null,
		);
	}//end importArchive()

	/**
	 * Extract the archive into `$tmpDir` and parse its manifest with the
	 * format's own parser.
	 *
	 * @param string $format Detected source format.
	 * @param string $packagePath Absolute path to the uploaded archive.
	 * @param string $tmpDir Extraction directory.
	 *
	 * @return array<string, mixed> The parsed manifest.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-corrupt-or-unrecognised-archive-fails-loudly-not-silently
	 */
	private function parseArchive(string $format, string $packagePath, string $tmpDir): array {
		if ($format === self::FORMAT_COMMON_CARTRIDGE) {
			return $this->ccImporter->extractAndParse(packagePath: $packagePath, targetDir: $tmpDir);
		}

		return $this->moodleImporter->extractAndParse(packagePath: $packagePath, targetDir: $tmpDir);
	}//end parseArchive()

	/**
	 * Materialise a parsed manifest with the format's own importer.
	 *
	 * @param string $format Detected source format.
	 * @param string $dir Extracted package directory.
	 * @param array<string, mixed> $manifest The parsed manifest.
	 * @param string $importedBy NC user id of the caller.
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return string|null UUID of the top-level Course, or null if none was created.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	private function materialise(
		string $format,
		string $dir,
		array $manifest,
		string $importedBy,
		string $tenantId,
		array &$entries,
	): ?string {
		if ($format === self::FORMAT_COMMON_CARTRIDGE) {
			return $this->ccImporter->importManifest(
				dir: $dir,
				manifest: $manifest,
				importedBy: $importedBy,
				tenantId: $tenantId,
				entries: $entries
			);
		}

		return $this->moodleImporter->importManifest(
			dir: $dir,
			manifest: $manifest,
			importedBy: $importedBy,
			tenantId: $tenantId,
			entries: $entries
		);
	}//end materialise()

	/**
	 * Detect whether the uploaded archive is a ZIP (Common Cartridge), a
	 * gzipped tar (Moodle `.mbz`) or a scholiq-native JSON export by sniffing
	 * magic bytes — filename extension alone is not trustworthy.
	 *
	 * @param string $packagePath Absolute path to the uploaded archive.
	 * @param string $sourceFilename Original filename (used only as a hint for logging).
	 *
	 * @return string|null `'common-cartridge-1.3'`, `'moodle-backup'`, `'scholiq-json'`, or null when unrecognised.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-corrupt-or-unrecognised-archive-fails-loudly-not-silently
	 */
	private function detectFormat(string $packagePath, string $sourceFilename): ?string {
		$handle = fopen($packagePath, 'rb');
		if ($handle === false) {
			return null;
		}

		$magic = fread($handle, 4);
		fclose($handle);
		if ($magic === false || strlen($magic) < 2) {
			return null;
		}

		$format = $this->detectFormatFromMagic(magic: $magic);
		if ($format === null) {
			$this->logger->warning(
				'[CoursePackageImportService] Unrecognised archive magic bytes for {file}.',
				['file' => $sourceFilename]
			);
		}

		return $format;
	}//end detectFormat()

	/**
	 * Map an archive's leading magic bytes onto a supported source format.
	 *
	 * @param string $magic The first bytes read off the uploaded file (at least 2).
	 *
	 * @return string|null The detected format, or null when unrecognised.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-corrupt-or-unrecognised-archive-fails-loudly-not-silently
	 */
	private function detectFormatFromMagic(string $magic): ?string {
		// ZIP local-file-header signature: 'PK' (0x50 0x4B).
		if ($magic[0] === 'P' && $magic[1] === 'K') {
			return self::FORMAT_COMMON_CARTRIDGE;
		}

		// Gzip magic bytes: 0x1F 0x8B.
		if (ord($magic[0]) === 0x1F && ord($magic[1]) === 0x8B) {
			return self::FORMAT_MOODLE_BACKUP;
		}

		// Scholiq-native JSON export (design.md format-support matrix: "Yes (round-trip
		// of this change's own export)") — a plain JSON object, not an archive.
		$trimmed = ltrim($magic);
		if ($trimmed !== '' && $trimmed[0] === '{') {
			return self::FORMAT_SCHOLIQ_JSON;
		}

		return null;
	}//end detectFormatFromMagic()
}//end class
