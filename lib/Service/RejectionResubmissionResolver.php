<?php

/**
 * Learniq Rejection Resubmission Resolver
 *
 * The resubmission-outcome half of ExchangeRejection mapping, extracted from
 * `RejectionMappingHandler` so each class carries one cohesive responsibility:
 * this one answers "what did the resubmission of an already-known rejection do?"
 * — load the ExchangeRejection rows whose `resubmittedJobId` points at a
 * finished DataExchangeJob, then per row accept it when its record no longer
 * appears in that job's `validationReport`, or refresh its
 * `errorCode`/`errorMessage` and reopen it when it still does. The first-pass
 * path (creating rejections from a validationReport) stays in the handler.
 *
 * A single unresolvable rejection is logged, never thrown, so it cannot abort
 * the rest of the batch.
 *
 * Consumed by:
 *   - RejectionMappingHandler (constructor injection)
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
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-resubmitted-record-that-duo-now-accepts-closes-its-rejection
 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-resubmitted-record-duo-rejects-again-reopens-its-rejection
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Accepts or reopens ExchangeRejections based on a resubmission job's outcome.
 */
class RejectionResubmissionResolver {

	private const LEARNIQ_REGISTER = 'learniq';
	private const REJECTION_SCHEMA = 'exchange-rejection';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param TransitionEngine $transitionEngine OR lifecycle engine for rejection state transitions.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TransitionEngine $transitionEngine,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load ExchangeRejection rows whose resubmittedJobId points at the given job.
	 *
	 * @param string $jobId UUID of the (possibly resubmission) DataExchangeJob.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return array<int,array<string,mixed>> The referencing ExchangeRejection rows.
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 */
	public function findRejectionsByResubmittedJobId(string $jobId, string $tenantId): array {
		$filters = ['resubmittedJobId' => $jobId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::REJECTION_SCHEMA,
				'filters' => $filters,
				'limit' => ExchangeRejectionContract::MAX_REJECTIONS_PER_JOB,
			]
		);

		return array_map(
			static function ($item) {
				if (is_array($item) === true) {
					return $item;
				}

				return $item->jsonSerialize();
			},
			$results
		);

	}//end findRejectionsByResubmittedJobId()

	/**
	 * Resubmission-outcome path: for each ExchangeRejection referencing this job,
	 * accept it when its recordId no longer appears in this job's validationReport,
	 * or reopen it (with refreshed errorCode/errorMessage) when it still does.
	 *
	 * @param array<string,mixed> $job The finished (resubmission) DataExchangeJob data.
	 * @param array<int,array<string,mixed>> $rejections ExchangeRejection rows referencing this job.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-resubmitted-record-that-duo-now-accepts-closes-its-rejection
	 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-resubmitted-record-duo-rejects-again-reopens-its-rejection
	 */
	public function handleResubmissionOutcome(array $job, array $rejections): void {
		$entriesByRecordId = $this->indexValidationEntries(
			validationReport: ($job['result']['validationReport'] ?? [])
		);

		foreach ($rejections as $rejection) {
			$rejectionId = $rejection['id'] ?? ($rejection['uuid'] ?? '');
			if ($rejectionId === '') {
				continue;
			}

			$recordId = $this->recordIdOf(rejection: $rejection);

			if (is_string($recordId) === true && isset($entriesByRecordId[$recordId]) === true) {
				$this->reopen(rejectionId: $rejectionId, entry: $entriesByRecordId[$recordId]);
				continue;
			}

			$this->attemptTransition(rejectionId: $rejectionId, action: 'accept');
		}//end foreach

	}//end handleResubmissionOutcome()

	/**
	 * Read the rejected record's id off an ExchangeRejection row via its sourceKind's typed $ref field.
	 *
	 * @param array<string,mixed> $rejection The ExchangeRejection row.
	 *
	 * @return mixed The record id, or null when the sourceKind is unsupported or the field is absent.
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 */
	private function recordIdOf(array $rejection): mixed {
		$sourceKind = $rejection['sourceKind'] ?? '';
		$sourceField = ExchangeRejectionContract::SOURCE_KIND_FIELD_MAP[$sourceKind] ?? null;

		if ($sourceField === null) {
			return null;
		}

		return $rejection[$sourceField] ?? null;
	}//end recordIdOf()

	/**
	 * Refresh a still-rejected record's errorCode/errorMessage, then reopen it.
	 *
	 * @param string $rejectionId UUID of the ExchangeRejection.
	 * @param array<string,mixed> $entry The validationReport entry that still names this record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-resubmitted-record-duo-rejects-again-reopens-its-rejection
	 */
	private function reopen(string $rejectionId, array $entry): void {
		$this->saveRejectionFields(
			rejectionId: $rejectionId,
			fields: [
				'errorCode' => (string)($entry['errorCode'] ?? ''),
				'errorMessage' => (string)($entry['errorMessage'] ?? ''),
			]
		);

		$this->attemptTransition(rejectionId: $rejectionId, action: 'reopen');

	}//end reopen()

	/**
	 * Index a job's validationReport entries by the record they are about.
	 *
	 * An entry with no usable `recordId` cannot be matched back to a rejection,
	 * so it is dropped rather than being attached to an arbitrary one.
	 *
	 * @param mixed $validationReport The job result's validationReport, whatever shape it arrived in.
	 *
	 * @return array<string,array<string,mixed>> Map of recordId => validation entry.
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 */
	private function indexValidationEntries(mixed $validationReport): array {
		if (is_array($validationReport) === false) {
			return [];
		}

		$entriesByRecordId = [];
		foreach ($validationReport as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$recordId = ($entry['recordId'] ?? null);
			if (is_string($recordId) === true && $recordId !== '') {
				$entriesByRecordId[$recordId] = $entry;
			}
		}

		return $entriesByRecordId;
	}//end indexValidationEntries()

	/**
	 * Attempt an ExchangeRejection lifecycle transition, logging (not throwing)
	 * on failure — a single unresolvable rejection must not abort the rest of
	 * the resubmission-outcome batch.
	 *
	 * @param string $rejectionId UUID of the ExchangeRejection.
	 * @param string $action Transition action name ('accept' or 'reopen').
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 */
	private function attemptTransition(string $rejectionId, string $action): void {
		try {
			$this->transitionEngine->transition($rejectionId, $action);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[RejectionResubmissionResolver] Could not transition ExchangeRejection {id} via {action}: {msg}',
				['id' => $rejectionId, 'action' => $action, 'msg' => $e->getMessage()]
			);
		}

	}//end attemptTransition()

	/**
	 * Persist updated fields on an ExchangeRejection without triggering a
	 * lifecycle event loop (mirrors DataExchangeRunHandler::saveJobFields()).
	 *
	 * @param string $rejectionId UUID of the ExchangeRejection.
	 * @param array<string,mixed> $fields Fields to update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 */
	private function saveRejectionFields(string $rejectionId, array $fields): void {
		$existing = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::REJECTION_SCHEMA,
				'filters' => ['id' => $rejectionId],
				'limit' => 1,
			]
		);

		if (empty($existing) === true) {
			$this->logger->warning(
				'[RejectionResubmissionResolver] ExchangeRejection {id} not found for field update.',
				['id' => $rejectionId]
			);
			return;
		}

		$current = $existing[0];
		if (is_array($existing[0]) === false) {
			$current = $existing[0]->jsonSerialize();
		}

		$updated = array_merge($current, $fields);

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::REJECTION_SCHEMA,
			object: $updated
		);

	}//end saveRejectionFields()
}//end class
