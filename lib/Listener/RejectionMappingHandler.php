<?php

/**
 * Learniq Rejection Mapping Handler
 *
 * IEventListener for DataExchangeJob lifecycle → succeeded | partial | failed
 * (the OR ObjectTransitionedEvent with schema=data-exchange-job, to IN
 * {succeeded, partial, failed}).
 *
 * Algorithm:
 * 1. Resubmission-outcome path: if ANY ExchangeRejection references this job
 *    as its resubmittedJobId, this job IS a resubmission — for each such
 *    rejection, check whether its source record still appears (by
 *    _scholiqRecordId, echoed back as validationReport[i].recordId) in this
 *    job's result.validationReport. Still present → reopen (fresh
 *    errorCode/errorMessage). Absent → accept. No new ExchangeRejection rows
 *    are created on this path.
 * 2. First-pass path (no rejection references this job): walk
 *    result.validationReport; for each entry with a recordId, resolve
 *    sourceKind from the job's own scope.schema (the schema slug IS the
 *    sourceKind enum value for every currently-supported source — see the
 *    SOURCE_KIND_FIELD_MAP whitelist below) and create one ExchangeRejection
 *    per entry, idempotent on (dataExchangeJobId, recordId). Best-effort
 *    resolves errorCodeRef against the ExchangeErrorCode catalogue by
 *    (code, target); leaves it null on no match (fail-open, never blocks
 *    rejection creation).
 *
 * recordId IS the source object's own id — buildPayload() stamps
 * `_scholiqRecordId = sourceObject.id` onto every outbound record (see
 * DataExchangeRunHandler::buildPayload()), so no re-query of the source
 * object is needed here to learn its id; only the job's scope.schema is
 * needed to learn what KIND of object it is.
 *
 * This is the ADR-031 "external-system bridge" exception, mirroring
 * DataExchangeRunHandler's single-responsibility shape: orchestrate mapping
 * a job's rejected records onto Learniq's own object graph. No protocol code
 * lives here.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Service\ExchangeRejectionContract;
use OCA\Learniq\Service\RejectionResubmissionResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Maps a finished DataExchangeJob's result.validationReport onto ExchangeRejection rows.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#requirement-resolve-a-jobs-rejected-records-to-their-scholiq-source-object
 */
class RejectionMappingHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const JOB_SCHEMA = 'data-exchange-job';
	private const REJECTION_SCHEMA = 'exchange-rejection';
	private const ERROR_CODE_SCHEMA = 'exchange-error-code';

	/**
	 * Job lifecycle end-states this handler reacts to.
	 *
	 * @var string[]
	 */
	private const TERMINAL_STATES = ['succeeded', 'partial', 'failed'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param LoggerInterface $logger PSR logger.
	 * @param RejectionResubmissionResolver $resubmission Accepts/reopens rejections a resubmission job just answered.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly RejectionResubmissionResolver $resubmission,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($event->getRegister() !== self::LEARNIQ_REGISTER) {
			return;
		}

		if ($event->getSchema() !== self::JOB_SCHEMA) {
			return;
		}

		if (in_array($event->getTo(), self::TERMINAL_STATES, strict: true) === false) {
			return;
		}

		$this->mapJob(event: $event);

	}//end handle()

	/**
	 * Route a finished DataExchangeJob to either the resubmission-outcome path
	 * or the first-pass rejection-creation path.
	 *
	 * @param ObjectTransitionedEvent $event The succeeded/partial/failed transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 */
	private function mapJob(ObjectTransitionedEvent $event): void {
		$job = $event->getObject()->jsonSerialize();
		$jobId = $job['id'] ?? ($job['uuid'] ?? '');

		if ($jobId === '') {
			$this->logger->error('[RejectionMappingHandler] DataExchangeJob has no id — cannot map rejections.');
			return;
		}

		$tenantId = (string)($job['tenant_id'] ?? '');

		$linkedRejections = $this->resubmission->findRejectionsByResubmittedJobId(jobId: $jobId, tenantId: $tenantId);

		if (empty($linkedRejections) === false) {
			$this->resubmission->handleResubmissionOutcome(job: $job, rejections: $linkedRejections);
			return;
		}

		$this->createRejectionsFromValidationReport(job: $job, jobId: $jobId, tenantId: $tenantId);

	}//end mapJob()

	/**
	 * First-pass path: create one ExchangeRejection per validationReport entry
	 * not already mapped for this job.
	 *
	 * @param array<string,mixed> $job The finished DataExchangeJob data.
	 * @param string $jobId UUID of the job.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-rejected-record-is-persisted-with-its-source-object-reference
	 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-idempotent-mapping-on-repeated-handler-invocation
	 */
	private function createRejectionsFromValidationReport(array $job, string $jobId, string $tenantId): void {
		$scope = $job['scope'] ?? [];
		$sourceKind = (string)($scope['schema'] ?? '');

		if (isset(ExchangeRejectionContract::SOURCE_KIND_FIELD_MAP[$sourceKind]) === false) {
			$this->logger->info(
				'[RejectionMappingHandler] Job {id} scope.schema "{schema}" is not a supported ExchangeRejection '
				. 'sourceKind — skipping rejection mapping.',
				['id' => $jobId, 'schema' => $sourceKind]
			);
			return;
		}

		$sourceField = ExchangeRejectionContract::SOURCE_KIND_FIELD_MAP[$sourceKind];
		$target = (string)($job['target'] ?? '');
		$validationReport = $job['result']['validationReport'] ?? [];

		if (is_array($validationReport) === false || empty($validationReport) === true) {
			return;
		}

		$existingRecordIds = $this->findExistingRecordIds(jobId: $jobId, tenantId: $tenantId);

		foreach ($validationReport as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$recordId = $entry['recordId'] ?? null;
			if (is_string($recordId) === false || $recordId === '') {
				continue;
			}

			// Idempotent on (dataExchangeJobId, recordId) — a redelivered event
			// must not create a second row for the same rejected record.
			if (isset($existingRecordIds[$recordId]) === true) {
				continue;
			}

			$this->createRejection(
				jobId: $jobId,
				tenantId: $tenantId,
				target: $target,
				sourceKind: $sourceKind,
				sourceField: $sourceField,
				recordId: $recordId,
				entry: $entry
			);

			// Guard against duplicate rows within the SAME validationReport too
			// (a malformed connector response repeating a recordId).
			$existingRecordIds[$recordId] = true;
		}//end foreach

	}//end createRejectionsFromValidationReport()

	/**
	 * Persist one ExchangeRejection row for a single validationReport entry.
	 *
	 * @param string $jobId UUID of the originating DataExchangeJob.
	 * @param string $tenantId Tenant ID.
	 * @param string $target The job's target slug (used for errorCodeRef matching).
	 * @param string $sourceKind Resolved sourceKind enum value.
	 * @param string $sourceField Name of the typed $ref id field for this sourceKind.
	 * @param string $recordId The rejected record's id (the source object's own id).
	 * @param array<string,mixed> $entry The validationReport entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 */
	private function createRejection(
		string $jobId,
		string $tenantId,
		string $target,
		string $sourceKind,
		string $sourceField,
		string $recordId,
		array $entry,
	): void {
		$errorCode = (string)($entry['errorCode'] ?? '');
		$errorMessage = (string)($entry['errorMessage'] ?? '');

		$offendingFields = $this->normaliseOffendingFields(field: $entry['field'] ?? null);
		$errorCodeRef = $this->resolveErrorCodeRef(errorCode: $errorCode, target: $target, tenantId: $tenantId);

		$rejection = [
			'dataExchangeJobId' => $jobId,
			'errorCode' => $errorCode,
			'errorMessage' => $errorMessage,
			'errorCodeRef' => $errorCodeRef,
			'offendingFields' => $offendingFields,
			'sourceKind' => $sourceKind,
			$sourceField => $recordId,
			// ASSUMPTION (documented in design.md): the assumed validationReport
			// item shape is {recordId, errorCode, errorMessage, field?} — it does
			// NOT carry the rejected record's own payload, so rawRecord has no
			// data source yet. Left null rather than fabricated.
			'rawRecord' => null,
			'status' => 'open',
			'detectedAt' => date('c'),
			'correctedBy' => null,
			'correctedAt' => null,
			'resubmittedJobId' => null,
			'waivedBy' => null,
			'waivedAt' => null,
			'waiveReason' => null,
			'correctionDeadlineAt' => null,
			'tenant_id' => $tenantId,
		];

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::REJECTION_SCHEMA,
			object: $rejection
		);

		$this->logger->info(
			'[RejectionMappingHandler] Created ExchangeRejection for job {job}, record {rec} '
			. '(sourceKind={kind}, errorCode={code}).',
			['job' => $jobId, 'rec' => $recordId, 'kind' => $sourceKind, 'code' => $errorCode]
		);

	}//end createRejection()

	/**
	 * Normalise a validationReport entry's `field` value (string, array, or absent) into
	 * ExchangeRejection.offendingFields' array<string> shape.
	 *
	 * @param mixed $field Raw `field` value from the validationReport entry.
	 *
	 * @return string[] Normalised offending field list; empty when not derivable.
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 */
	private function normaliseOffendingFields(mixed $field): array {
		if (is_string($field) === true && $field !== '') {
			return [$field];
		}

		if (is_array($field) === true) {
			return array_values(
				array_filter(
					$field,
					static fn ($f): bool => is_string($f) === true && $f !== ''
				)
			);
		}

		return [];
	}//end normaliseOffendingFields()

	/**
	 * Best-effort resolve an ExchangeErrorCode catalogue match by (code, target).
	 * Prefers an exact target match; falls back to a generic (target=null) entry;
	 * never blocks rejection creation on failure to match.
	 *
	 * @param string $errorCode The rejection's errorCode.
	 * @param string $target The job's target slug.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return string|null UUID of the matched ExchangeErrorCode, or null when no match exists.
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-known-error-code-resolves-to-its-catalogue-entry
	 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-an-unknown-error-code-does-not-block-rejection-creation
	 */
	private function resolveErrorCodeRef(string $errorCode, string $target, string $tenantId): ?string {
		if ($errorCode === '') {
			return null;
		}

		$filters = ['code' => $errorCode];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::ERROR_CODE_SCHEMA,
				'filters' => $filters,
				'limit' => 100,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		$match = $this->bestErrorCodeMatch(results: $results, target: $target);
		if ($match === null) {
			return null;
		}

		$matchId = $match['id'] ?? ($match['uuid'] ?? null);

		if (is_string($matchId) === false || $matchId === '') {
			return null;
		}

		return $matchId;
	}//end resolveErrorCodeRef()

	/**
	 * Pick the ErrorCode that best describes a rejection for a given target.
	 *
	 * A code declared for this exact target wins outright. Failing that, a
	 * target-agnostic code (`target` is null) is used, so a generic code still
	 * describes the rejection rather than leaving it unmapped. A code belonging
	 * to a DIFFERENT target is never used.
	 *
	 * @param array<int,mixed> $results Candidate ErrorCode rows from OR.
	 * @param string $target The data-exchange target the rejection came from.
	 *
	 * @return array<string,mixed>|null The best match, or null when none applies.
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 */
	private function bestErrorCodeMatch(array $results, string $target): ?array {
		$genericMatch = null;

		foreach ($results as $result) {
			$candidate = $result;
			if (is_array($candidate) === false) {
				$candidate = $candidate->jsonSerialize();
			}

			$candidateTarget = ($candidate['target'] ?? null);

			if ($candidateTarget === $target) {
				return (array)$candidate;
			}

			if ($candidateTarget === null && $genericMatch === null) {
				$genericMatch = $candidate;
			}
		}

		if ($genericMatch === null) {
			return null;
		}

		return (array)$genericMatch;
	}//end bestErrorCodeMatch()

	/**
	 * Load the set of recordIds already mapped as ExchangeRejection rows for this job.
	 *
	 * @param string $jobId UUID of the DataExchangeJob.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return array<string,bool> Set (map recordId => true) of already-mapped record ids.
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.2
	 */
	private function findExistingRecordIds(string $jobId, string $tenantId): array {
		$filters = ['dataExchangeJobId' => $jobId];
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

		$existing = [];

		foreach ($results as $item) {
			$row = $item;
			if (is_array($item) === false) {
				$row = $item->jsonSerialize();
			}

			$sourceKind = $row['sourceKind'] ?? '';
			$sourceField = ExchangeRejectionContract::SOURCE_KIND_FIELD_MAP[$sourceKind] ?? null;
			if ($sourceField === null) {
				continue;
			}

			$recordId = $row[$sourceField] ?? null;
			if (is_string($recordId) === true && $recordId !== '') {
				$existing[$recordId] = true;
			}
		}

		return $existing;
	}//end findExistingRecordIds()
}//end class
