<?php

/**
 * Learniq External Training Import
 *
 * Turns the rows of a provider's attendance list into ExternalTrainingRecord
 * objects. The browser parses the spreadsheet and posts plain rows; every row
 * passes this one validator, in a dry run for the preview and again on
 * confirm, so the preview and the import cannot disagree
 * (compliance-external-training-spreadsheet-upload design D1, D2).
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
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validates, matches and records the rows of an external-training upload.
 *
 * @psalm-api
 *
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */
class ExternalTrainingImport {

	/**
	 * OpenRegister register slug Learniq objects live in.
	 */
	private const REGISTER = 'learniq';

	/**
	 * Schema slugs this import reads and writes.
	 */
	private const RECORD_SCHEMA = 'external-training-record';

	/**
	 * The most rows one upload may carry.
	 */
	public const MAX_ROWS = 1000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object reads and writes.
	 * @param ExternalTrainingLearnerMatch $learnerMatch Finds the learner a row names, in the tenant.
	 * @param LoggerInterface $logger PSR logger.
	 * @param ExternalTrainingRowCheck $rowCheck The field rules of one row.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ExternalTrainingLearnerMatch $learnerMatch,
		private readonly LoggerInterface $logger,
		private readonly ExternalTrainingRowCheck $rowCheck = new ExternalTrainingRowCheck(),
	) {
	}//end __construct()

	/**
	 * Check every row and, unless this is a dry run, record the ready ones.
	 *
	 * Each row is reported, with `reason` and `reasonParams` when it is not ready, as `ready` (dry run) or `created`, `unmatched`
	 * (no learner of this tenant), `invalid` (a field is wrong), `duplicate`
	 * (an earlier row of the same file) or `skipped` (already recorded).
	 * All records of one upload share a `batchId`.
	 *
	 * @param array<int,mixed> $rows The parsed rows: learner, title, provider, kind,
	 *                               completedAt, validUntil, regulationSlug, evidenceNote.
	 * @param string $tenantId The caller's tenant; learners are matched in it only.
	 * @param string $submittedBy The caller's user id.
	 * @param bool $dryRun True to report without writing.
	 * @param DateTimeInterface|null $now Evaluation instant (injectable for tests).
	 *
	 * @return array{dryRun:bool,batchId:?string,summary:array<string,int>,rows:array<int,array<string,mixed>>} The report.
	 *
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-import-result-report
	 */
	public function import(
		array $rows,
		string $tenantId,
		string $submittedBy,
		bool $dryRun,
		?DateTimeInterface $now = null,
	): array {
		$now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$batchId = null;
		if ($dryRun === false) {
			$batchId = bin2hex(random_bytes(16));
		}

		$seen = [];
		$report = [];
		foreach (array_values($rows) as $index => $row) {
			if (is_array($row) === false) {
				$row = [];
			}

			$checked = $this->check(row: $row, tenantId: $tenantId, now: $now);
			$checked['row'] = ($index + 1);
			$checked = $this->againstEarlier(checked: $checked, seen: $seen, tenantId: $tenantId);

			if ($checked['status'] === 'ready' && $dryRun === false) {
				$checked = $this->record(checked: $checked, batchId: (string)$batchId, tenantId: $tenantId, submittedBy: $submittedBy);
			}

			unset($checked['record']);
			$report[] = $checked;
		}//end foreach

		$summary = ['ready' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'total' => count($report)];
		foreach ($report as $line) {
			$summary[self::bucket(status: $line['status'])]++;
		}

		if ($summary['created'] === 0) {
			$batchId = null;
		}

		return [
			'dryRun' => $dryRun,
			'batchId' => $batchId,
			'summary' => $summary,
			'rows' => $report,
		];
	}//end import()

	/**
	 * Validate one row's fields and match its learner.
	 *
	 * @param array<string,mixed> $row The row.
	 * @param string $tenantId The caller's tenant.
	 * @param DateTimeInterface $now Evaluation instant.
	 *
	 * @return array<string,mixed> status, reason, reasonParams, learner, learnerId, record.
	 */
	private function check(array $row, string $tenantId, DateTimeInterface $now): array {
		$fields = $this->rowCheck->check(row: $row, now: $now);
		$checked = ['status' => 'ready', 'reason' => null, 'reasonParams' => [], 'learner' => $fields['learner'], 'learnerId' => null, 'record' => []];
		if ($fields['reason'] !== null) {
			return $this->outcome(checked: $checked, status: 'invalid', reason: $fields['reason'], params: $fields['params']);
		}

		$match = $this->learnerMatch->match(reference: (string)$fields['learner'], tenantId: $tenantId);
		if ($match['reason'] !== null) {
			return $this->outcome(checked: $checked, status: 'unmatched', reason: $match['reason']);
		}

		$record = array_merge(['learnerId' => $match['id']], $fields['record']);
		if ($match['userId'] !== null) {
			$record['learnerUserId'] = $match['userId'];
		}

		$checked['learnerId'] = $match['id'];
		$checked['record'] = $record;
		return $checked;
	}//end check()

	/**
	 * Mark a ready row that repeats an earlier row of the file, or a record that already exists.
	 *
	 * @param array<string,mixed> $checked The checked row.
	 * @param array<string,int> $seen Learner, title and date of the ready rows so far, to their row number.
	 * @param string $tenantId The caller's tenant.
	 *
	 * @return array<string,mixed> The row, still ready or now duplicate or skipped.
	 */
	private function againstEarlier(array $checked, array &$seen, string $tenantId): array {
		if ($checked['status'] !== 'ready') {
			return $checked;
		}

		$key = $checked['learnerId'] . '|' . mb_strtolower($checked['record']['title']) . '|' . substr($checked['record']['completedAt'], 0, 10);
		if (isset($seen[$key]) === true) {
			return $this->outcome(
				checked: $checked,
				status: 'duplicate',
				reason: 'The same learner, training and date are on row {row}.',
				params: ['row' => $seen[$key]]
			);
		}

		$seen[$key] = $checked['row'];
		if ($this->alreadyRecorded(record: $checked['record'], tenantId: $tenantId) === true) {
			return $this->outcome(checked: $checked, status: 'skipped', reason: 'This training is already recorded for this learner on this date.');
		}

		return $checked;
	}//end againstEarlier()

	/**
	 * Whether the learner already has this training on this date, from any earlier upload or entry.
	 *
	 * @param array<string,string> $record The record about to be created.
	 * @param string $tenantId The caller's tenant.
	 *
	 * @return bool True when it is already recorded.
	 */
	private function alreadyRecorded(array $record, string $tenantId): bool {
		$existing = self::rows(
			objects: $this->objectService->findAll(
				config: [
					'filters' => [
						'learnerId' => $record['learnerId'],
						'tenant_id' => $tenantId,
						'register' => self::REGISTER,
						'schema' => self::RECORD_SCHEMA,
					],
				],
				_rbac: false,
				_multitenancy: false
			)
		);

		foreach ($existing as $row) {
			if (($row['learnerId'] ?? null) === $record['learnerId']
				&& mb_strtolower((string)($row['title'] ?? '')) === mb_strtolower($record['title'])
				&& substr((string)($row['completedAt'] ?? ''), 0, 10) === substr($record['completedAt'], 0, 10)
			) {
				return true;
			}
		}

		return false;
	}//end alreadyRecorded()

	/**
	 * Save one ready row.
	 *
	 * @param array<string,mixed> $checked The checked row.
	 * @param string $batchId The upload's batch.
	 * @param string $tenantId The caller's tenant.
	 * @param string $submittedBy The caller's user id.
	 *
	 * @return array<string,mixed> The row as created, or failed with the reason.
	 */
	private function record(array $checked, string $batchId, string $tenantId, string $submittedBy): array {
		$object = array_merge(
			$checked['record'],
			['submittedBy' => $submittedBy, 'batchId' => $batchId, 'tenant_id' => $tenantId]
		);

		try {
			// No lifecycle: OR starts the record in `submitted`, as bulkRecord does.
			$this->objectService->saveObject(register: self::REGISTER, schema: self::RECORD_SCHEMA, object: $object);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[ExternalTrainingImport] row {row} could not be saved: {message}',
				['row' => $checked['row'], 'message' => $failure->getMessage()]
			);
			return $this->outcome(checked: $checked, status: 'invalid', reason: 'The record could not be saved.');
		}

		$checked['status'] = 'created';
		return $checked;
	}//end record()

	/**
	 * A row with a final status and its reason.
	 *
	 * @param array<string,mixed> $checked The row.
	 * @param string $status The status.
	 * @param string $reason Why, with `{name}` placeholders the client fills from `reasonParams`.
	 * @param array<string,int|string> $params The placeholder values.
	 *
	 * @return array<string,mixed> The row.
	 */
	private function outcome(array $checked, string $status, string $reason, array $params = []): array {
		$checked['status'] = $status;
		$checked['reason'] = $reason;
		$checked['reasonParams'] = [];
		foreach ($params as $name => $value) {
			if (str_contains($reason, '{' . $name . '}') === true) {
				$checked['reasonParams'][$name] = $value;
			}
		}

		return $checked;
	}//end outcome()

	/**
	 * The summary bucket of a row status.
	 *
	 * @param string $status The row status.
	 *
	 * @return string ready, created, skipped or failed.
	 */
	private static function bucket(string $status): string {
		return match ($status) {
			'ready', 'created' => $status,
			'duplicate', 'skipped' => 'skipped',
			default => 'failed',
		};
	}//end bucket()

	/**
	 * OpenRegister answers with ObjectEntity instances; read them as arrays.
	 *
	 * @param array<int,mixed> $objects The objects.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 */
	private static function rows(array $objects): array {
		$rows = [];
		foreach ($objects as $object) {
			if (is_array($object) === true) {
				$rows[] = $object;
				continue;
			}

			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$rows[] = (array)$object->jsonSerialize();
			}
		}

		return $rows;
	}//end rows()
}//end class
