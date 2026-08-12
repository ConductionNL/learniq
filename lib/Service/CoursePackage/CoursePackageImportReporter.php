<?php

/**
 * Scholiq Course Package Import Reporter
 *
 * Builds the per-resource report rows every course-package importer emits and
 * persists the resulting `CoursePackageImportReport`. Extracted out of
 * `CoursePackageImportService` so the report contract lives in exactly one
 * place, shared by the Common Cartridge, Moodle and scholiq-native JSON
 * importers.
 *
 * @category Service
 * @package  OCA\Scholiq\Service\CoursePackage
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
 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service\CoursePackage;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Assembles and persists a `CoursePackageImportReport`.
 */
class CoursePackageImportReporter {

	private const SCHOLIQ_REGISTER = 'scholiq';
	private const REPORT_SCHEMA = 'course-package-import-report';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object service for persisting the report.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The import timestamp stamped on a report, in ISO-8601 (UTC).
	 *
	 * @return string ISO-8601 timestamp.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
	 */
	public function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
	}//end now()

	/**
	 * Build one report entry row.
	 *
	 * @param string $resourceIdentifier Source resource identifier.
	 * @param string $resourceType Source resource/module type string.
	 * @param string $title Resource title.
	 * @param string $outcome `imported`|`degraded`|`dropped` (or the internal `pending-qti` marker, resolved before persisting).
	 * @param string|null $targetType Created scholiq schema name, when applicable.
	 * @param string|null $targetId Created object UUID, when applicable.
	 * @param string|null $reason Human-readable reason, required for non-`imported` outcomes.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
	 */
	public function entry(
		string $resourceIdentifier,
		string $resourceType,
		string $title,
		string $outcome,
		?string $targetType,
		?string $targetId,
		?string $reason,
	): array {
		return [
			'resourceIdentifier' => $resourceIdentifier,
			'resourceType' => $resourceType,
			'title' => $title,
			'outcome' => $outcome,
			'targetType' => $targetType,
			'targetId' => $targetId,
			'reason' => $reason,
		];
	}//end entry()

	/**
	 * Resolve the report's final `lifecycle` from its entries, per the report
	 * requirement's rule: `succeeded` only when every entry is `imported`,
	 * otherwise `partial`.
	 *
	 * @param array<int, array<string,mixed>> $entries Report entries.
	 *
	 * @return string `succeeded` or `partial`.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
	 */
	public function resolveLifecycle(array $entries): string {
		foreach ($entries as $entry) {
			if ($entry['outcome'] !== 'imported') {
				return 'partial';
			}
		}

		return 'succeeded';
	}//end resolveLifecycle()

	/**
	 * Persist the `CoursePackageImportReport` with its resolved per-outcome counts.
	 *
	 * @param string $sourceFormat `common-cartridge-1.3`, `moodle-backup` or `scholiq-json`.
	 * @param string $sourceFilename Original filename.
	 * @param string $importedBy NC user id.
	 * @param string $importedAt ISO-8601 timestamp.
	 * @param string $tenantId Tenant UUID.
	 * @param string|null $courseId Top-level Course UUID, or null.
	 * @param string $lifecycle Resolved lifecycle (`succeeded`|`partial`|`failed`).
	 * @param array<int, array<string, mixed>> $entries Report entries.
	 * @param string|null $errorMessage Failure reason, only when `lifecycle: failed`.
	 *
	 * @return array<string, mixed> The persisted report.
	 *
	 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
	 */
	public function persistReport(
		string $sourceFormat,
		string $sourceFilename,
		string $importedBy,
		string $importedAt,
		string $tenantId,
		?string $courseId,
		string $lifecycle,
		array $entries,
		?string $errorMessage,
	): array {
		$imported = 0;
		$degraded = 0;
		$dropped = 0;
		foreach ($entries as $entry) {
			match ($entry['outcome']) {
				'imported' => $imported++,
				'degraded' => $degraded++,
				'dropped' => $dropped++,
				default => null,
			};
		}

		$saved = $this->objectService->saveObject(
			register: self::SCHOLIQ_REGISTER,
			schema: self::REPORT_SCHEMA,
			object: [
				'sourceFormat' => $sourceFormat,
				'sourceFilename' => $sourceFilename,
				'courseId' => $courseId,
				'importedBy' => $importedBy,
				'importedAt' => $importedAt,
				'lifecycle' => $lifecycle,
				'resourcesTotal' => count($entries),
				'resourcesImported' => $imported,
				'resourcesDegraded' => $degraded,
				'resourcesDropped' => $dropped,
				'errorMessage' => $errorMessage,
				'entries' => $entries,
				'tenant_id' => $tenantId,
			]
		);

		return $saved->jsonSerialize();
	}//end persistReport()
}//end class
