<?php

/**
 * Learniq Credential Migration
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
use DateTimeZone;
use OCA\Learniq\Support\ResultArrays;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks and writes migrated certificates.
 *
 * A row: `learner` (learner id, personal number or e-mail), `course` (course
 * code) or `regulation` (regulation slug), `legacyNumber`, `legacyIssuer`,
 * `issuedAt` and an optional `expiresAt` (both `YYYY-MM-DD`), and an optional
 * `kind` (default `certificate`).
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */
class CredentialMigrationService {

	/**
	 * The register.
	 */
	private const REGISTER = 'learniq';

	/**
	 * The credential schema.
	 */
	private const CREDENTIAL_SCHEMA = 'credential';

	/**
	 * The credential kinds a migrated row may carry.
	 */
	private const KINDS = ['diploma', 'certificate', 'badge', 'microcredential'];

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
	}//end __construct()

	/**
	 * Check every row, and on commit write the ones that are ready.
	 *
	 * @param array<int, mixed>      $rows       The rows.
	 * @param string                 $tenantId   The organisation migrating.
	 * @param string                 $migratedBy The user committing.
	 * @param bool                   $commit     False for the preview: nothing is written.
	 * @param DateTimeImmutable|null $now        The moment of the run.
	 *
	 * @return array{commit: bool, runId: string|null, summary: array<string, int>, rows: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
	 */
	public function migrate(array $rows, string $tenantId, string $migratedBy, bool $commit, ?DateTimeImmutable $now = null): array {
		$run = [
			'id' => self::runIdFor(commit: $commit),
			'by' => $migratedBy,
			'at' => ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'))),
		];

		$seen = [];
		$report = [];
		foreach (array_values($rows) as $index => $row) {
			$report[] = $this->reportRow(row: $row, line: ($index + 1), tenantId: $tenantId, run: $run, commit: $commit, seen: $seen);
		}

		$summary = self::summarise(report: $report);
		$runId = $run['id'];
		if ($summary['created'] === 0) {
			$runId = null;
		}

		return ['commit' => $commit, 'runId' => $runId, 'summary' => $summary, 'rows' => $report];
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
	 * @param mixed                                                   $row      The row.
	 * @param int                                                     $line     The row number.
	 * @param string                                                  $tenantId The organisation.
	 * @param array{id: string|null, by: string, at: DateTimeImmutable} $run      The run.
	 * @param bool                                                    $commit   False for the preview.
	 * @param array<int|string, int>                                  $seen     The row number of each key seen so far.
	 *
	 * @return array<string, mixed>
	 */
	private function reportRow(mixed $row, int $line, string $tenantId, array $run, bool $commit, array &$seen): array {
		if (is_array($row) === false) {
			$row = [];
		}

		$checked = $this->check(row: $row, tenantId: $tenantId) + ['row' => $line];
		$checked = $this->againstEarlier(checked: $checked, line: $line, seen: $seen);
		if ($checked['status'] === 'ready' && $commit === true) {
			$checked = $this->write(checked: $checked, run: ['id' => (string)$run['id'], 'by' => $run['by'], 'at' => $run['at']], tenantId: $tenantId);
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
		$fields = self::fields(row: $row);
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

		$target = $this->target(fields: $fields, tenantId: $tenantId);
		if ($target === null) {
			return ['status' => 'unmatched', 'reason' => 'No course with this code and no regulation with this slug in your organisation.'] + $checked;
		}

		$checked['record'] = self::recordFor(fields: $fields, match: $match, target: $target, tenantId: $tenantId);
		$checked['key'] = self::keyOf(record: $checked['record']);
		if ($this->alreadyMigrated(record: $checked['record']) === true) {
			return ['status' => 'duplicate', 'reason' => 'This certificate was migrated before.'] + $checked;
		}

		return $checked;
	}//end check()

	/**
	 * The certificate record a checked row writes.
	 *
	 * @param array<string, mixed>                                   $fields   The row's fields.
	 * @param array<string, mixed>                                   $match    The matched learner.
	 * @param array{courseId: string|null, regulationSlug: string|null} $target   The course or regulation.
	 * @param string                                                 $tenantId The organisation.
	 *
	 * @return array<string, mixed>
	 */
	private static function recordFor(array $fields, array $match, array $target, string $tenantId): array {
		$record = [
			'learnerId' => $match['id'],
			'courseId' => $target['courseId'],
			'regulationSlug' => $target['regulationSlug'],
			'kind' => $fields['kind'],
			'issuedAt' => $fields['issuedAt'],
			'expiresAt' => $fields['expiresAt'],
			'source' => 'migrated',
			'legacyNumber' => $fields['legacyNumber'],
			'legacyIssuer' => $fields['legacyIssuer'],
			'tenant_id' => $tenantId,
		];
		if ($match['userId'] !== null) {
			$record['learnerUserId'] = $match['userId'];
		}

		return $record;
	}//end recordFor()

	/**
	 * The key that makes a record a repeat: learner, course or regulation, and old number.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string
	 */
	private static function keyOf(array $record): string {
		return $record['learnerId'] . '|' . ($record['courseId'] ?? 'regulation:' . $record['regulationSlug']) . '|' . $record['legacyNumber'];
	}//end keyOf()

	/**
	 * The row's fields, or why it cannot be read.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private static function fields(array $row): array {
		$text = static fn (string $key): string => self::text(row: $row, key: $key);
		$fields = [
			'learner' => $text('learner'),
			'course' => $text('course'),
			'regulation' => $text('regulation'),
			'legacyNumber' => $text('legacyNumber'),
			'legacyIssuer' => $text('legacyIssuer'),
			'kind' => $text('kind'),
			'issuedAt' => self::date(value: $text('issuedAt')),
			'expiresAt' => null,
			'reason' => null,
		];

		if ($fields['kind'] === '') {
			$fields['kind'] = 'certificate';
		}

		$expires = $text('expiresAt');
		if ($expires !== '') {
			$fields['expiresAt'] = self::date(value: $expires);
		}

		$fields['reason'] = (self::identityReason(fields: $fields) ?? self::dateReason(fields: $fields, expires: $expires));

		return $fields;
	}//end fields()

	/**
	 * A row's value as trimmed text; empty when it is missing or not a scalar.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string               $key The column.
	 *
	 * @return string
	 */
	private static function text(array $row, string $key): string {
		$value = ($row[$key] ?? null);
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()

	/**
	 * Why the row's learner, target, old number or kind cannot be used, or null.
	 *
	 * @param array<string, mixed> $fields The row's fields.
	 *
	 * @return string|null
	 */
	private static function identityReason(array $fields): ?string {
		return self::firstFailed(
			checks: [
				'The learner is missing.' => $fields['learner'] === '',
				'Give a course code or a regulation.' => ($fields['course'] === '' && $fields['regulation'] === ''),
				'The old certificate number is missing.' => $fields['legacyNumber'] === '',
				'The kind is not one of diploma, certificate, badge or microcredential.' => in_array($fields['kind'], self::KINDS, true) === false,
			]
		);
	}//end identityReason()

	/**
	 * Why the row's issue or end date cannot be used, or null.
	 *
	 * @param array<string, mixed> $fields  The row's fields.
	 * @param string               $expires The end date as given.
	 *
	 * @return string|null
	 */
	private static function dateReason(array $fields, string $expires): ?string {
		return self::firstFailed(
			checks: [
				'The issue date is not a date (YYYY-MM-DD).' => $fields['issuedAt'] === null,
				'The end date is not a date (YYYY-MM-DD).' => ($expires !== '' && $fields['expiresAt'] === null),
				'The end date is before the issue date.' => ($fields['expiresAt'] !== null && $fields['expiresAt'] < $fields['issuedAt']),
			]
		);
	}//end dateReason()

	/**
	 * The message of the first failed check, in order, or null when all pass.
	 *
	 * @param array<string, bool> $checks Each message with whether its check failed.
	 *
	 * @return string|null
	 */
	private static function firstFailed(array $checks): ?string {
		foreach ($checks as $message => $failed) {
			if ($failed === true) {
				return $message;
			}
		}

		return null;
	}//end firstFailed()

	/**
	 * A `YYYY-MM-DD` date as an ISO 8601 timestamp at midnight UTC, or null.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 */
	private static function date(string $value): ?string {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
		if ($date === false || $date->format('Y-m-d') !== $value) {
			return null;
		}

		return $date->format(DateTimeInterface::ATOM);
	}//end date()

	/**
	 * The course or regulation the certificate is for, or null when neither is known.
	 *
	 * @param array<string, mixed> $fields   The row's fields.
	 * @param string               $tenantId The organisation.
	 *
	 * @return array{courseId: string|null, regulationSlug: string|null}|null
	 */
	private function target(array $fields, string $tenantId): ?array {
		if ($fields['course'] !== '') {
			return $this->courseTarget(code: $fields['course'], tenantId: $tenantId);
		}

		if ($this->first(schema: 'regulation', filters: ['slug' => $fields['regulation'], 'tenant_id' => $tenantId]) === null) {
			return null;
		}

		return ['courseId' => null, 'regulationSlug' => $fields['regulation']];
	}//end target()

	/**
	 * The course with this code and its regulation, or null when the organisation has no such course.
	 *
	 * @param string $code     The course code.
	 * @param string $tenantId The organisation.
	 *
	 * @return array{courseId: string, regulationSlug: string|null}|null
	 */
	private function courseTarget(string $code, string $tenantId): ?array {
		$course = $this->first(schema: 'course', filters: ['code' => $code, 'tenant_id' => $tenantId]);
		if ($course === null) {
			return null;
		}

		$regulation = ($course['regulationSlug'] ?? null);
		if (is_string($regulation) === false || $regulation === '') {
			$regulation = null;
		}

		return ['courseId' => self::idOf(row: $course), 'regulationSlug' => $regulation];
	}//end courseTarget()

	/**
	 * Whether a migrated certificate with this learner, course or regulation and old number exists.
	 *
	 * @param array<string, mixed> $record The record to write.
	 *
	 * @return bool
	 */
	private function alreadyMigrated(array $record): bool {
		$filters = [
			'learnerId' => $record['learnerId'],
			'legacyNumber' => $record['legacyNumber'],
			'source' => 'migrated',
			'tenant_id' => $record['tenant_id'],
		];
		if ($record['courseId'] !== null) {
			$filters['courseId'] = $record['courseId'];
		} else {
			$filters['regulationSlug'] = $record['regulationSlug'];
		}

		return $this->first(schema: self::CREDENTIAL_SCHEMA, filters: $filters) !== null;
	}//end alreadyMigrated()

	/**
	 * Mark a ready row that repeats an earlier row of the same run.
	 *
	 * @param array<string, mixed> $checked The row's check.
	 * @param int                  $line    The row number.
	 * @param array<int|string, int> $seen  The row number of each key seen so far.
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
	 * @param array<string, mixed>                                $checked  The row's check.
	 * @param array{id: string, by: string, at: DateTimeImmutable} $run      The run.
	 * @param string                                              $tenantId The organisation.
	 *
	 * @return array<string, mixed>
	 */
	private function write(array $checked, array $run, string $tenantId): array {
		$record = $checked['record'] + [
			'migratedAt' => $run['at']->format(DateTimeInterface::ATOM),
			'migratedBy' => $run['by'],
			'migrationRunId' => $run['id'],
		];

		try {
			$saved = $this->objectService->saveObject(object: $record, register: self::REGISTER, schema: self::CREDENTIAL_SCHEMA);
		} catch (Throwable $e) {
			$this->logger->error(
				'Learniq: a migrated certificate could not be saved',
				['tenant' => $tenantId, 'row' => $checked['row'], 'exception' => $e->getMessage()]
			);
			return ['status' => 'failed', 'reason' => 'The certificate could not be saved.'] + $checked;
		}

		$checked['status'] = 'created';
		$checked['credentialId'] = self::idOf(row: (new ResultArrays())->arraysOf(objects: [$saved])[0] ?? []);

		return $checked;
	}//end write()

	/**
	 * The first object of a schema matching the filters, as an array, or null.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first(string $schema, array $filters): ?array {
		$found = $this->objectService->findAll(
			config: ['filters' => array_merge($filters, ['register' => self::REGISTER, 'schema' => $schema]), 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);

		return ((new ResultArrays())->arraysOf(objects: ($found['results'] ?? $found))[0] ?? null);
	}//end first()

	/**
	 * A row's id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		$id = ($row['id'] ?? $row['uuid'] ?? ($row['@self']['id'] ?? null));

		if (is_string($id) === false) {
			return '';
		}

		return $id;
	}//end idOf()

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
