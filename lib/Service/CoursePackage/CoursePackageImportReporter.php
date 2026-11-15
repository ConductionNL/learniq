<?php

/**
 * Learniq Course Package Import Reporter
 *
 * Builds the per-resource report rows every course-package importer emits and
 * persists the resulting `CoursePackageImportReport`. Extracted out of
 * `CoursePackageImportService` so the report contract lives in exactly one
 * place, shared by the Common Cartridge, Moodle and scholiq-native JSON
 * importers.
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
 * @spec openspec/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Assembles and persists a `CoursePackageImportReport`.
 */
class CoursePackageImportReporter {

	private const LEARNIQ_REGISTER = 'learniq';
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
	 * @spec openspec/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
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
	 * @param array{outcome: string, targetType: string|null, targetId: string|null, reason: string|null} $result The outcome
	 *        (`imported`|`degraded`|`dropped`, or the internal `pending-qti` marker, resolved before persisting), the created
	 *        Learniq schema and object UUID when applicable, and the human-readable reason, required for non-`imported` outcomes.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
	 */
	public function entry(string $resourceIdentifier, string $resourceType, string $title, array $result): array {
		return [
			'resourceIdentifier' => $resourceIdentifier,
			'resourceType' => $resourceType,
			'title' => $title,
			'outcome' => $result['outcome'],
			'targetType' => $result['targetType'],
			'targetId' => $result['targetId'],
			'reason' => $result['reason'],
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
	 * @spec openspec/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
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
	 * @param ImportOrigin        $origin  Who imported which file, when, for which tenant.
	 * @param ImportReportOutcome $outcome The format, course, entries and error message.
	 *
	 * @return array<string, mixed> The persisted report.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
	 */
	public function persistReport(ImportOrigin $origin, ImportReportOutcome $outcome): array {
		$entries = $outcome->entries;
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
			register: self::LEARNIQ_REGISTER,
			schema: self::REPORT_SCHEMA,
			object: [
				'sourceFormat' => $outcome->sourceFormat,
				'sourceFilename' => $origin->sourceFilename,
				'courseId' => $outcome->courseId,
				'importedBy' => $origin->importedBy,
				'importedAt' => $origin->importedAt,
				'lifecycle' => $this->lifecycleOf(outcome: $outcome),
				'resourcesTotal' => count($entries),
				'resourcesImported' => $imported,
				'resourcesDegraded' => $degraded,
				'resourcesDropped' => $dropped,
				'errorMessage' => $outcome->errorMessage,
				'entries' => $entries,
				'tenant_id' => $origin->tenantId,
			]
		);

		return $saved->jsonSerialize();
	}//end persistReport()

	/**
	 * The report's lifecycle: `failed` when the outcome carries an error
	 * message, otherwise resolved from its entries.
	 *
	 * @param ImportReportOutcome $outcome The import's outcome.
	 *
	 * @return string `succeeded`, `partial` or `failed`.
	 */
	private function lifecycleOf(ImportReportOutcome $outcome): string {
		if ($outcome->errorMessage !== null) {
			return 'failed';
		}

		return $this->resolveLifecycle(entries: $outcome->entries);
	}//end lifecycleOf()
}//end class
