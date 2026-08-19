<?php

/**
 * Scholiq External Training CSV Builder
 *
 * Builds the separately-labelled external-training evidence artefact for the
 * compliance audit pack: training a learner completed OUTSIDE the LMS
 * (classroom, third-party, conference) that an officer verified.
 *
 * Only `verified` records matching the regulation and whose `completedAt` falls
 * inside the pack's date range are included. The `evidence_files` column
 * references the OR file attachments on each record; the bytes are exported by
 * OR's attachment API, not duplicated here.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/external-training-recording/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Renders verified external-training records as the audit pack's evidence CSV.
 *
 * @psalm-api
 *
 * @spec openspec/changes/external-training-recording/tasks.md
 */
class ExternalTrainingCsvBuilder {

	/**
	 * The CSV header, also returned verbatim when there is nothing to export.
	 *
	 * @var string
	 */
	private const HEADER = 'learner_id,title,provider,kind,regulation_slug,completed_at,'
		. "valid_until,verified_by,verified_at,credential_id,evidence_files\n";

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object service for the record query.
	 * @param CsvCellSanitizer $sanitizer CSV formula-injection neutraliser.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CsvCellSanitizer $sanitizer,
	) {

	}//end __construct()

	/**
	 * Render verified external-training records for the regulation as CSV.
	 *
	 * @param string $regulationSlug Regulation slug to filter (matches the pack).
	 * @param string $dateFrom ISO date lower bound (inclusive) on completedAt.
	 * @param string $dateTo ISO date upper bound (inclusive) on completedAt.
	 * @param string $tenantId Tenant scope so other tenants are never exported.
	 *
	 * @return string CSV string (header-only when there are no matching records).
	 *
	 * @spec openspec/changes/external-training-recording/tasks.md
	 */
	public function build(
		string $regulationSlug,
		string $dateFrom,
		string $dateTo,
		string $tenantId,
	): string {
		$rows = $this->objectService->findAll(
			[
				'register' => 'learniq',
				'schema' => 'external-training-record',
				'filters' => [
					'regulationSlug' => $regulationSlug,
					'lifecycle' => 'verified',
					'tenant_id' => $tenantId,
				],
				'sort' => ['completedAt' => 'ASC'],
			]
		);

		if (empty($rows) === true) {
			return self::HEADER;
		}

		$handle = fopen('php://memory', 'r+');
		if ($handle === false) {
			return self::HEADER;
		}

		fputcsv(
			$handle,
			[
				'learner_id',
				'title',
				'provider',
				'kind',
				'regulation_slug',
				'completed_at',
				'valid_until',
				'verified_by',
				'verified_at',
				'credential_id',
				'evidence_files',
			]
		);

		foreach ($rows as $row) {
			$rec = $this->normaliseRecord(row: $row);

			$completedAt = (string)($rec['completedAt'] ?? '');
			if ($this->completedAtInRange(completedAt: $completedAt, dateFrom: $dateFrom, dateTo: $dateTo) === false) {
				continue;
			}

			fputcsv($handle, $this->csvRow(rec: $rec, completedAt: $completedAt));
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		// Header-only file when every row was filtered out by the date range.
		if (trim($csv) === '' || str_starts_with($csv, 'learner_id') === false) {
			return self::HEADER . $csv;
		}

		return $csv;
	}//end build()

	/**
	 * Normalise one external-training row to a plain array.
	 *
	 * `ObjectService::findAll()` may hand back either entities or already-rendered
	 * arrays depending on the render mode, so both shapes are accepted here.
	 *
	 * @param mixed $row One row as returned by the OR object service.
	 *
	 * @return array<string,mixed> The record as a plain array.
	 *
	 * @spec openspec/changes/external-training-recording/tasks.md
	 */
	private function normaliseRecord(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		return (array)$row->jsonSerialize();
	}//end normaliseRecord()

	/**
	 * Decide whether a record's `completedAt` falls inside the pack's date range.
	 *
	 * A lexical comparison is valid for ISO-8601 timestamps. An empty
	 * `completedAt` is kept rather than dropped, so a record is never silently
	 * excluded for lacking the field.
	 *
	 * @param string $completedAt Record completion timestamp ('' when absent).
	 * @param string $dateFrom ISO date lower bound (inclusive).
	 * @param string $dateTo ISO date upper bound (inclusive, end of day).
	 *
	 * @return bool True when the record belongs in this pack.
	 *
	 * @spec openspec/changes/external-training-recording/tasks.md
	 */
	private function completedAtInRange(string $completedAt, string $dateFrom, string $dateTo): bool {
		if ($completedAt === '') {
			return true;
		}

		if ($completedAt < $dateFrom) {
			return false;
		}

		return $completedAt <= ($dateTo . 'T23:59:59Z');
	}//end completedAtInRange()

	/**
	 * Collect the display names of the OR file attachments on a record.
	 *
	 * The bytes stay in OR's attachment API — the pack only references them by
	 * name, so an attachment is listed whether it arrives as a bare string or as
	 * a descriptor array.
	 *
	 * @param array<string,mixed> $rec The normalised external-training record.
	 *
	 * @return array<int,string> Attachment names in record order.
	 *
	 * @spec openspec/changes/external-training-recording/tasks.md
	 */
	private function evidenceFileNames(array $rec): array {
		$files = ($rec['@self']['files'] ?? ($rec['files'] ?? []));
		if (is_array($files) === false) {
			return [];
		}

		$fileNames = [];
		foreach ($files as $file) {
			if (is_array($file) === false) {
				$fileNames[] = (string)$file;
				continue;
			}

			$fileNames[] = (string)($file['name'] ?? ($file['title'] ?? ($file['id'] ?? '')));
		}

		return $fileNames;
	}//end evidenceFileNames()

	/**
	 * Render one external-training record as a sanitised CSV cell list.
	 *
	 * @param array<string,mixed> $rec The normalised external-training record.
	 * @param string $completedAt Completion timestamp already coerced by the caller.
	 *
	 * @return array<int,string> Cells in the order of the CSV header.
	 *
	 * @spec openspec/changes/external-training-recording/tasks.md
	 */
	private function csvRow(array $rec, string $completedAt): array {
		return [
			$this->sanitizer->sanitize(value: (string)($rec['learnerId'] ?? '')),
			$this->sanitizer->sanitize(value: (string)($rec['title'] ?? '')),
			$this->sanitizer->sanitize(value: (string)($rec['provider'] ?? '')),
			$this->sanitizer->sanitize(value: (string)($rec['kind'] ?? '')),
			$this->sanitizer->sanitize(value: (string)($rec['regulationSlug'] ?? '')),
			$this->sanitizer->sanitize(value: $completedAt),
			$this->sanitizer->sanitize(value: (string)($rec['validUntil'] ?? '')),
			$this->sanitizer->sanitize(value: (string)($rec['verifiedBy'] ?? '')),
			$this->sanitizer->sanitize(value: (string)($rec['verifiedAt'] ?? '')),
			$this->sanitizer->sanitize(value: (string)($rec['credentialId'] ?? '')),
			$this->sanitizer->sanitize(value: implode('; ', $this->evidenceFileNames(rec: $rec))),
		];

	}//end csvRow()
}//end class
