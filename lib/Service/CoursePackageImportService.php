<?php

/**
 * Learniq Course Package Import Service
 *
 * Orchestrates course-package import: it sniffs the uploaded archive's format,
 * hands extraction/parsing and materialisation to the matching format importer
 * (`CommonCartridgeCourseImporter`, `MoodleCourseImporter` or
 * `LearniqJsonCourseImporter`), and persists the resulting
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
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Service\CoursePackage\ArchivePackage;
use OCA\Learniq\Service\CoursePackage\CommonCartridgeCourseImporter;
use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Learniq\Service\CoursePackage\ImportOrigin;
use OCA\Learniq\Service\CoursePackage\ImportReportOutcome;
use OCA\Learniq\Service\CoursePackage\MoodleCourseImporter;
use OCA\Learniq\Service\CoursePackage\PackageContext;
use OCA\Learniq\Service\CoursePackage\LearniqJsonCourseImporter;
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
	 * @param LearniqJsonCourseImporter $jsonImporter Scholiq-native JSON round-trip import.
	 * @param CoursePackageFileWriter $fileWriter Temp-directory cleanup after every import.
	 * @param CoursePackageImportReporter $reporter Builds and persists the import report.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CommonCartridgeCourseImporter $ccImporter,
		private readonly MoodleCourseImporter $moodleImporter,
		private readonly LearniqJsonCourseImporter $jsonImporter,
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
	 * @spec openspec/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
	 */
	public function import(string $packagePath, string $sourceFilename, string $importedBy, string $tenantId = ''): array {
		$origin = new ImportOrigin(
			sourceFilename: $sourceFilename,
			importedBy: $importedBy,
			importedAt: $this->reporter->now(),
			tenantId: $tenantId,
		);
		$tmpDir = sys_get_temp_dir() . '/learniq_coursepkg_' . bin2hex(random_bytes(8));

		try {
			$format = $this->detectFormat(packagePath: $packagePath, sourceFilename: $sourceFilename);
			if ($format === null) {
				return $this->reporter->persistReport(
					origin: $origin,
					outcome: new ImportReportOutcome(
						sourceFormat: self::FORMAT_COMMON_CARTRIDGE,
						courseId: null,
						entries: [],
						errorMessage: 'Archive is not a recognised IMS Common Cartridge (ZIP), Moodle backup '
							. '(.mbz / gzipped tar), or scholiq-native JSON package.',
					),
				);
			}

			// The scholiq-native JSON format is a single file, not an archive to
			// extract — it is this change's own lossless export target (design.md
			// format-support matrix), read and walked directly.
			if ($format === self::FORMAT_SCHOLIQ_JSON) {
				return $this->jsonImporter->importPackage(packagePath: $packagePath, origin: $origin);
			}

			mkdir(directory: $tmpDir, permissions: 0700, recursive: true);

			return $this->importArchive(
				package: new ArchivePackage(format: $format, packagePath: $packagePath, tmpDir: $tmpDir),
				origin: $origin,
			);
		} finally {
			$this->fileWriter->removeDirectory(dir: $tmpDir);
		}//end try
	}//end import()

	/**
	 * Extract, parse and materialise an archive-shaped package (Common
	 * Cartridge or Moodle backup), then persist its report.
	 *
	 * @param ArchivePackage $package The archive, its format and its extraction directory.
	 * @param ImportOrigin   $origin  Who imported which file, when, for which tenant.
	 *
	 * @return array<string, mixed> The persisted `CoursePackageImportReport`.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	private function importArchive(ArchivePackage $package, ImportOrigin $origin): array {
		try {
			$manifest = $this->parseArchive(package: $package);
		} catch (\Throwable $e) {
			return $this->reporter->persistReport(
				origin: $origin,
				outcome: new ImportReportOutcome(
					sourceFormat: $package->format,
					courseId: null,
					entries: [],
					errorMessage: 'Could not extract or parse the package: ' . $package->withoutPaths(message: $e->getMessage()),
				),
			);
		}

		$entries = [];
		$courseId = $this->materialise(
			format: $package->format,
			manifest: $manifest,
			package: new PackageContext(dir: $package->tmpDir, importedBy: $origin->importedBy, tenantId: $origin->tenantId),
			entries: $entries,
		);

		return $this->reporter->persistReport(
			origin: $origin,
			outcome: new ImportReportOutcome(sourceFormat: $package->format, courseId: $courseId, entries: $entries, errorMessage: null),
		);
	}//end importArchive()

	/**
	 * Extract the archive into its extraction directory and parse its
	 * manifest with the format's own parser.
	 *
	 * @param ArchivePackage $package The archive, its format and its extraction directory.
	 *
	 * @return array<string, mixed> The parsed manifest.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-corrupt-or-unrecognised-archive-fails-loudly-not-silently
	 */
	private function parseArchive(ArchivePackage $package): array {
		if ($package->format === self::FORMAT_COMMON_CARTRIDGE) {
			return $this->ccImporter->extractAndParse(packagePath: $package->packagePath, targetDir: $package->tmpDir);
		}

		return $this->moodleImporter->extractAndParse(packagePath: $package->packagePath, targetDir: $package->tmpDir);
	}//end parseArchive()

	/**
	 * Materialise a parsed manifest with the format's own importer.
	 *
	 * @param string $format Detected source format.
	 * @param array<string, mixed> $manifest The parsed manifest.
	 * @param PackageContext $package The extracted package: its directory, the importing user (nc:files owner) and the tenant.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return string|null UUID of the top-level Course, or null if none was created.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	private function materialise(string $format, array $manifest, PackageContext $package, array &$entries): ?string {
		if ($format === self::FORMAT_COMMON_CARTRIDGE) {
			return $this->ccImporter->importManifest(manifest: $manifest, package: $package, entries: $entries);
		}

		return $this->moodleImporter->importManifest(manifest: $manifest, package: $package, entries: $entries);
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
	 * @spec openspec/specs/course-management/spec.md#scenario-a-corrupt-or-unrecognised-archive-fails-loudly-not-silently
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
	 * @spec openspec/specs/course-management/spec.md#scenario-a-corrupt-or-unrecognised-archive-fails-loudly-not-silently
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
