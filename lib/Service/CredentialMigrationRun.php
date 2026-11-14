<?php

/**
 * Learniq Credential Migration Run
 *
 * Brings certificates over from the system learniq replaces, with their old
 * number, old issuer and validity dates. Every row passes one check, in a dry
 * run for the preview and again on commit, so the preview and the result
 * cannot disagree (credentials-migrate-with-validity design D1). A migrated
 * certificate is not signed: learniq did not issue it (D2). A second run only
 * adds what is new, keyed on learner, course and old number (D3).
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Learniq\Support\ResultArrays;
use OCA\Learniq\Support\RowId;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One migration run: checks every row and writes the ready ones on commit.
 *
 * A row: `learner` (learner id, personal number or e-mail), `course` (course
 * code) or `regulation` (regulation slug), `legacyNumber`, `legacyIssuer`,
 * `issuedAt` and an optional `expiresAt` (both `YYYY-MM-DD`), and an optional
 * `kind` (default `certificate`).
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */
final class CredentialMigrationRun {

	/**
	 * The register.
	 */
	private const REGISTER = 'learniq';

	/**
	 * The credential schema.
	 */
	private const CREDENTIAL_SCHEMA = 'credential';

	/**
	 * The course, regulation and earlier-migration lookups.
	 *
	 * @var CredentialMigrationLookup
	 */
	private readonly CredentialMigrationLookup $lookup;

	/**
	 * Constructor.
	 *
	 * @param ObjectService                $objectService Reads courses, regulations and credentials; writes credentials.
	 * @param ExternalTrainingLearnerMatch $learnerMatch  Matches a row's learner, as the external-training upload does.
	 * @param LoggerInterface              $logger        Logs a failed write.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ExternalTrainingLearnerMatch $learnerMatch,
		private readonly LoggerInterface $logger,
	) {
		$this->lookup = new CredentialMigrationLookup(objectService: $objectService);
	}//end __construct()

	/**
	 * Check every row, and on commit write the ones that are ready.
	 *
	 * @param array<int, mixed>                                                      $rows The rows.
	 * @param array{tenantId: string, by: string, commit: bool, at: DateTimeImmutable} $run  The organisation migrating, the
	 *                                                                                       user committing, false for the
	 *                                                                                       preview, and the moment of the run.
	 *
	 * @return array{commit: bool, runId: string|null, summary: array<string, int>, rows: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
	 */
	public function migrate(array $rows, array $run): array {
		$run['id'] = self::runIdFor(commit: $run['commit']);

		$seen = [];
		$report = [];
		foreach (array_values($rows) as $index => $row) {
			$report[] = $this->reportRow(row: $row, line: ($index + 1), run: $run, seen: $seen);
		}

		$summary = self::summarise(report: $report);
		$runId = $run['id'];
		if ($summary['created'] === 0) {
			$runId = null;
		}

		return ['commit' => $run['commit'], 'runId' => $runId, 'summary' => $summary, 'rows' => $report];
	}//end migrate()

	/**
	 * A new run id when the run commits, else null.
	 *
	 * @param bool $commit Whether the run writes.
	 *
	 * @return string|null
	 */
	private static function runIdFor(bool $commit): ?string {
		if ($commit === false) {
			return null;
		}

		return bin2hex(random_bytes(16));
	}//end runIdFor()

	/**
	 * One row's report line: checked, compared with the earlier rows, and written when ready on commit.
	 *
	 * @param mixed                                                                                   $row  The row.
	 * @param int                                                                                     $line The row number.
	 * @param array{id: string|null, tenantId: string, by: string, commit: bool, at: DateTimeImmutable} $run  The run.
	 * @param array<int|string, int>                                                                  $seen The row number of each key seen so far.
	 *
	 * @return array<string, mixed>
	 */
	private function reportRow(mixed $row, int $line, array $run, array &$seen): array {
		if (is_array($row) === false) {
			$row = [];
		}

		$checked = $this->check(row: $row, tenantId: $run['tenantId']) + ['row' => $line];
		$checked = $this->againstEarlier(checked: $checked, line: $line, seen: $seen);
		if ($checked['status'] === 'ready' && $run['commit'] === true) {
			$checked = $this->write(checked: $checked, run: $run);
		}

		unset($checked['record'], $checked['key']);
		return $checked;
	}//end reportRow()

	/**
	 * The count of each outcome, of rows without an end date, and of all rows.
	 *
	 * @param array<int, array<string, mixed>> $report The report lines.
	 *
	 * @return array<string, int>
	 */
	private static function summarise(array $report): array {
		$summary = ['ready' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'withoutEndDate' => 0, 'total' => count($report)];
		foreach ($report as $line) {
			$summary[self::bucket(status: $line['status'])]++;
			if (in_array($line['status'], ['ready', 'created'], true) === true && $line['expiresAt'] === null) {
				$summary['withoutEndDate']++;
			}
		}

		return $summary;
	}//end summarise()

	/**
	 * One row's check: its fields, its learner, its course or regulation, and an earlier migration of it.
	 *
	 * @param array<string, mixed> $row      The row.
	 * @param string               $tenantId The organisation.
	 *
	 * @return array<string, mixed>
	 */
	private function check(array $row, string $tenantId): array {
		$fields = CredentialMigrationRow::fields(row: $row);
		$checked = [
			'status' => 'ready',
			'reason' => null,
			'learner' => $fields['learner'],
			'expiresAt' => $fields['expiresAt'],
			'record' => [],
			'key' => '',
		];
		if ($fields['reason'] !== null) {
			return ['status' => 'invalid', 'reason' => $fields['reason']] + $checked;
		}

		$match = $this->learnerMatch->match(reference: $fields['learner'], tenantId: $tenantId);
		if ($match['reason'] !== null) {
			return ['status' => 'unmatched', 'reason' => $match['reason']] + $checked;
		}

		$target = $this->lookup->target(fields: $fields, tenantId: $tenantId);
		if ($target === null) {
			return ['status' => 'unmatched', 'reason' => 'No course with this code and no regulation with this slug in your organisation.'] + $checked;
		}

		$checked['record'] = CredentialMigrationRow::recordFor(fields: $fields, match: $match, target: $target, tenantId: $tenantId);
		$checked['key'] = CredentialMigrationRow::keyOf(record: $checked['record']);
		if ($this->lookup->alreadyMigrated(record: $checked['record']) === true) {
			return ['status' => 'duplicate', 'reason' => 'This certificate was migrated before.'] + $checked;
		}

		return $checked;
	}//end check()

	/**
	 * Mark a ready row that repeats an earlier row of the same run.
	 *
	 * @param array<string, mixed>   $checked The row's check.
	 * @param int                    $line    The row number.
	 * @param array<int|string, int> $seen    The row number of each key seen so far.
	 *
	 * @return array<string, mixed>
	 */
	private function againstEarlier(array $checked, int $line, array &$seen): array {
		if ($checked['status'] !== 'ready') {
			return $checked;
		}

		if (isset($seen[$checked['key']]) === true) {
			return ['status' => 'duplicate', 'reason' => 'The same learner, course and old number are on row ' . $seen[$checked['key']] . '.'] + $checked;
		}

		$seen[$checked['key']] = $line;
		return $checked;
	}//end againstEarlier()

	/**
	 * Write a ready row as a migrated certificate.
	 *
	 * @param array<string, mixed>                                                                    $checked The row's check.
	 * @param array{id: string|null, tenantId: string, by: string, commit: bool, at: DateTimeImmutable} $run     The run.
	 *
	 * @return array<string, mixed>
	 */
	private function write(array $checked, array $run): array {
		$record = $checked['record'] + [
			'migratedAt' => $run['at']->format(DateTimeInterface::ATOM),
			'migratedBy' => $run['by'],
			'migrationRunId' => (string)$run['id'],
		];

		try {
			$saved = $this->objectService->saveObject(object: $record, register: self::REGISTER, schema: self::CREDENTIAL_SCHEMA);
		} catch (Throwable $e) {
			$this->logger->error(
				'Learniq: a migrated certificate could not be saved',
				['tenant' => $run['tenantId'], 'row' => $checked['row'], 'exception' => $e->getMessage()]
			);
			return ['status' => 'failed', 'reason' => 'The certificate could not be saved.'] + $checked;
		}

		$checked['status'] = 'created';
		$checked['credentialId'] = (new RowId())->idOf(row: (new ResultArrays())->arraysOf(objects: [$saved])[0] ?? []);

		return $checked;
	}//end write()

	/**
	 * The summary bucket of a status.
	 *
	 * @param string $status The status.
	 *
	 * @return string
	 */
	private static function bucket(string $status): string {
		return match ($status) {
			'ready' => 'ready',
			'created' => 'created',
			'failed' => 'failed',
			default => 'skipped',
		};
	}//end bucket()
}//end class
