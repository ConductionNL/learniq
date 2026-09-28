<?php

/**
 * Learniq Exchange Gate Service
 *
 * Decides whether an integriq exchange job owned by learniq may run, and
 * which records may leave: the domain gates decision D7 keeps in learniq.
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
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-the-gate-enforces-partner-approval-teldatum-confirmation-and-flag-handling
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTime;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The exchange gate (design D1).
 *
 * Five conditions, checked in order; the first refusal wins. The records are
 * composed only when every other condition passed, so a refused job never
 * reads a pupil record. The same decision answers integriq's gate event and
 * the `/api/exchange-gates/{jobId}` route; only the event gets the records.
 *
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-the-gate-enforces-partner-approval-teldatum-confirmation-and-flag-handling
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ExchangeGateService {

	public const DECISION_ALLOW = 'allow';
	public const DECISION_REFUSE = 'refuse';

	private const LEARNIQ_REGISTER = 'learniq';
	private const INTEGRIQ_REGISTER = 'integriq';
	private const INTEGRIQ_JOB_SCHEMA = 'job';
	private const DOSSIER_REVIEW_SCHEMA = 'dossier-review';
	private const PARTNER_APPROVAL_SCHEMA = 'exchange-partner-approval';
	private const TELDATUM_CHECK_SCHEMA = 'teldatum-check';
	private const ATTENDANCE_FLAG_SCHEMA = 'attendance-flag';

	/**
	 * Targets whose file a parent reviews before it leaves.
	 *
	 * @var array<int, string>
	 */
	private const REVIEWED_TARGETS = ['oso', 'swv'];

	/**
	 * Attendance flag states in which a person has taken the flag up.
	 *
	 * @var array<int, string>
	 */
	private const FLAG_HANDLED = ['in-handling', 'reported'];

	/**
	 * How many record references a completeness refusal names at most.
	 */
	private const MAX_NAMED_REFERENCES = 5;

	/**
	 * Constructor.
	 *
	 * @param ObjectService              $objectService OR object access.
	 * @param DataExchangePayloadBuilder $builder       Composes what may leave.
	 * @param ExchangeDisclosure         $disclosure    Field lists and statutory rules.
	 * @param ExchangeImportInput        $importInput   Reads an import job's file.
	 * @param IL10N                      $l10n          Translates the refusal reasons.
	 * @param LoggerInterface            $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly DataExchangePayloadBuilder $builder,
		private readonly ExchangeDisclosure $disclosure,
		private readonly ExchangeImportInput $importInput,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Decide for one integriq job.
	 *
	 * @param string               $jobId     The integriq job's uuid.
	 * @param string               $target    The exchange target.
	 * @param string               $direction export, import or sync.
	 * @param string               $ownerRef  The row that caused the job, `<schema>/<uuid>`.
	 * @param array<string, mixed> $scope     The job's scope.
	 *
	 * @return array{decision: string, code: string, reason: string, checkedAt: string, records: array<int, array<string, mixed>>}
	 *     The decision.
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-the-gate-refuses-an-oso-or-swv-file-until-a-parent-approved-it
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-the-gate-enforces-partner-approval-teldatum-confirmation-and-flag-handling
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
	 * @spec openspec/changes/import-records-in-gate-answer/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq
	 */
	public function evaluate(
		string $jobId,
		string $target,
		string $direction,
		string $ownerRef,
		array $scope,
	): array {
		$refusal = $this->flagRefusal(target: $target, ownerRef: $ownerRef)
			?? $this->parentReviewRefusal(jobId: $jobId, target: $target, direction: $direction)
			?? $this->partnerRefusal(target: $target)
			?? $this->teldatumRefusal(target: $target, scope: $scope);
		if ($refusal !== null) {
			return $this->refuse(code: $refusal['code'], reason: $refusal['reason']);
		}

		if ($direction === 'import') {
			return $this->importAnswer(jobId: $jobId, target: $target, scope: $scope);
		}

		$mappingSlug = $this->mappingOf(jobId: $jobId);
		if ($this->disclosure->fieldsFor(mappingSlug: $mappingSlug) === null && $this->disclosure->isStatutory(target: $target) === true) {
			return $this->refuse(
				code: 'disclosure-undefined',
				reason: $this->l10n->t('Learniq has no list of what may leave for this %s job, so nothing is sent.', [$target])
			);
		}

		$tenantId = (string)($scope['tenantId'] ?? '');
		try {
			$records = $this->builder->composeRecords(target: $target, mappingSlug: $mappingSlug, scope: $scope, tenantId: $tenantId);
		} catch (Throwable $exception) {
			$this->logger->warning('[ExchangeGateService] could not compose the records of job ' . $jobId . ': ' . $exception->getMessage());
			return $this->refuse(code: 'records-unavailable', reason: $exception->getMessage());
		}

		$incomplete = $this->completenessRefusal(target: $target, mappingSlug: $mappingSlug, records: $records);
		if ($incomplete !== null) {
			return $this->refuse(code: $incomplete['code'], reason: $incomplete['reason']);
		}

		return $this->allow(records: $records);
	}//end evaluate()

	/**
	 * Condition 1: a leerplicht report waits until a person took up the flag.
	 *
	 * @param string $target   The exchange target.
	 * @param string $ownerRef The row that caused the job.
	 *
	 * @return array{code: string, reason: string}|null The refusal, or null.
	 */
	private function flagRefusal(string $target, string $ownerRef): ?array {
		if ($target !== 'leerplicht') {
			return null;
		}

		$flag = $this->findById(schema: self::ATTENDANCE_FLAG_SCHEMA, uuid: $this->uuidOf(ownerRef: $ownerRef));
		if ($flag !== null && in_array((string)($flag['lifecycle'] ?? ''), self::FLAG_HANDLED, true) === true) {
			return null;
		}

		return [
			'code' => 'flag-not-in-handling',
			'reason' => $this->l10n->t('Nobody has taken up this attendance flag yet. Start handling it, then the report is sent.'),
		];
	}//end flagRefusal()

	/**
	 * Condition 2: an OSO or SWV file waits for a parent.
	 *
	 * @param string $jobId     The integriq job's uuid.
	 * @param string $target    The exchange target.
	 * @param string $direction The direction.
	 *
	 * @return array{code: string, reason: string}|null The refusal, or null.
	 */
	private function parentReviewRefusal(string $jobId, string $target, string $direction): ?array {
		if ($direction !== 'export' || in_array($target, self::REVIEWED_TARGETS, true) === false) {
			return null;
		}

		$review = $this->findOne(schema: self::DOSSIER_REVIEW_SCHEMA, filters: ['exchangeJobId' => $jobId]);
		$status = (string)($review['status'] ?? 'pending');
		if ($status === 'approved') {
			return null;
		}

		if ($status === 'rejected') {
			return [
				'code' => 'parent-review-rejected',
				'reason' => $this->l10n->t('A parent rejected this file, so it does not leave the school.'),
			];
		}

		return [
			'code' => 'parent-review-pending',
			'reason' => $this->l10n->t('This file leaves the school only after a parent approved it.'),
		];
	}//end parentReviewRefusal()

	/**
	 * Condition 3: a target with a partner link needs that link approved.
	 *
	 * @param string $target The exchange target.
	 *
	 * @return array{code: string, reason: string}|null The refusal, or null.
	 */
	private function partnerRefusal(string $target): ?array {
		$approvals = $this->findMany(schema: self::PARTNER_APPROVAL_SCHEMA, filters: ['target' => $target], limit: 50);
		if ($approvals === []) {
			return null;
		}

		foreach ($approvals as $approval) {
			if (($approval['status'] ?? '') === 'approved') {
				return null;
			}
		}

		return [
			'code' => 'partner-approval-missing',
			'reason' => $this->l10n->t('The partner link for %s is not approved yet.', [$target]),
		];
	}//end partnerRefusal()

	/**
	 * Condition 4: a job that names a teldatum waits for its confirmed count.
	 *
	 * @param string               $target The exchange target.
	 * @param array<string, mixed> $scope  The job's scope.
	 *
	 * @return array{code: string, reason: string}|null The refusal, or null.
	 */
	private function teldatumRefusal(string $target, array $scope): ?array {
		$teldatum = (string)($scope['teldatumDate'] ?? '');
		if ($teldatum === '') {
			return null;
		}

		$check = $this->findOne(schema: self::TELDATUM_CHECK_SCHEMA, filters: ['teldatumDate' => $teldatum, 'target' => $target]);
		if (($check['status'] ?? '') === 'confirmed') {
			return null;
		}

		return [
			'code' => 'teldatum-unconfirmed',
			'reason' => $this->l10n->t('The teldatum count for %s is not confirmed yet.', [$teldatum]),
		];
	}//end teldatumRefusal()

	/**
	 * Condition 5: every record of a statutory target carries its required fields.
	 *
	 * Names fields and record references, never values: a ROD record carries
	 * the pupil's personal number.
	 *
	 * @param string                           $target      The exchange target.
	 * @param string|null                      $mappingSlug The job's integriq mapping.
	 * @param array<int, array<string, mixed>> $records     The composed records.
	 *
	 * @return array{code: string, reason: string}|null The refusal, or null.
	 */
	private function completenessRefusal(string $target, ?string $mappingSlug, array $records): ?array {
		$required = $this->disclosure->requiredFor(target: $target, mappingSlug: $mappingSlug);
		if ($required === []) {
			return null;
		}

		$missingFields = [];
		$references = [];
		foreach ($records as $record) {
			$data = (array)($record['data'] ?? []);
			$missing = array_filter($required, static fn (string $field): bool => (($data[$field] ?? null) === null || $data[$field] === ''));
			if ($missing === []) {
				continue;
			}

			$missingFields = array_unique(array_merge($missingFields, array_values($missing)));
			$references[] = trim((string)($record['sourceKind'] ?? '') . '/' . (string)($record['recordId'] ?? ''), '/');
		}

		if ($references === []) {
			return null;
		}

		return [
			'code' => 'statutory-incomplete',
			'reason' => $this->l10n->t(
				'%1$s record(s) miss a required field (%2$s): %3$s.',
				[(string)count($references), implode(', ', $missingFields), implode(', ', array_slice($references, 0, self::MAX_NAMED_REFERENCES))]
			),
		];
	}//end completenessRefusal()

	/**
	 * An import job's answer: the rows of the file it names, or a refusal.
	 * Targets learniq does not land are allowed without records.
	 *
	 * Logs the job id and the refusal code only, never a row or a file name.
	 *
	 * @param string               $jobId  The integriq job's uuid.
	 * @param string               $target The exchange target.
	 * @param array<string, mixed> $scope  The job's scope (`fileId`).
	 *
	 * @return array{decision: string, code: string, reason: string, checkedAt: string, records: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/import-records-in-gate-answer/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq
	 */
	private function importAnswer(string $jobId, string $target, array $scope): array {
		$requestedBy = (string)($this->jobRow(jobId: $jobId)['requestedBy'] ?? '');
		$input = $this->importInput->read(target: $target, scope: $scope, requestedBy: $requestedBy);
		if ($input['refusal'] === null) {
			return $this->allow(records: $input['records']);
		}

		$this->logger->info('[ExchangeGateService] import job ' . $jobId . ' refused: ' . $input['refusal']);
		return $this->refuse(code: $input['refusal'], reason: $input['reason']);
	}//end importAnswer()

	/**
	 * The integriq job's mapping slug, read from integriq's own row.
	 *
	 * @param string $jobId The integriq job's uuid.
	 *
	 * @return string|null The slug, or null when the job cannot be read.
	 */
	private function mappingOf(string $jobId): ?string {
		$slug = ($this->jobRow(jobId: $jobId)['exchangeMapping'] ?? null);
		if (is_string($slug) === false || $slug === '') {
			return null;
		}

		return $slug;
	}//end mappingOf()

	/**
	 * Integriq's own row of a job, as an array.
	 *
	 * @param string $jobId The integriq job's uuid.
	 *
	 * @return array<string, mixed> The row, or empty when it cannot be read.
	 */
	private function jobRow(string $jobId): array {
		try {
			$job = $this->objectService->find(
				id: $jobId,
				register: self::INTEGRIQ_REGISTER,
				schema: self::INTEGRIQ_JOB_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->info('[ExchangeGateService] integriq job ' . $jobId . ' could not be read: ' . $exception->getMessage());
			return [];
		}

		return (array)($job?->jsonSerialize() ?? []);
	}//end jobRow()

	/**
	 * One learniq row by uuid, as an array.
	 *
	 * @param string $schema The schema slug.
	 * @param string $uuid   The row's uuid.
	 *
	 * @return array<string, mixed>|null The row, or null when absent or unreadable.
	 */
	private function findById(string $schema, string $uuid): ?array {
		if ($uuid === '') {
			return null;
		}

		try {
			$row = $this->objectService->find(
				id: $uuid,
				register: self::LEARNIQ_REGISTER,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			unset($exception);
			return null;
		}

		if ($row === null) {
			return null;
		}

		return (array)$row->jsonSerialize();
	}//end findById()

	/**
	 * The first learniq row matching filters, as an array.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	private function findOne(string $schema, array $filters): ?array {
		$rows = $this->findMany(schema: $schema, filters: $filters, limit: 1);
		return ($rows[0] ?? null);
	}//end findOne()

	/**
	 * Learniq rows matching filters, as arrays; empty on a read failure.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 * @param int                  $limit   The most rows to read.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function findMany(string $schema, array $filters, int $limit): array {
		try {
			$results = $this->objectService->findAll(
				config: [
					'filters' => array_merge($filters, ['register' => self::LEARNIQ_REGISTER, 'schema' => $schema]),
					'limit' => $limit,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('[ExchangeGateService] ' . $schema . ' could not be read: ' . $exception->getMessage());
			return [];
		}

		$rows = [];
		foreach ($results as $row) {
			if (is_array($row) === false) {
				$row = $row->jsonSerialize();
			}

			$rows[] = $row;
		}

		return $rows;
	}//end findMany()

	/**
	 * The uuid part of an owner reference `<schema>/<uuid>`.
	 *
	 * @param string $ownerRef The reference.
	 *
	 * @return string The uuid, or the reference itself when it has no slash.
	 */
	private function uuidOf(string $ownerRef): string {
		// The leading slash makes a reference without one come back whole.
		return substr((string)strrchr('/' . $ownerRef, '/'), 1);
	}//end uuidOf()

	/**
	 * Build an allow.
	 *
	 * @param array<int, array<string, mixed>> $records What may leave.
	 *
	 * @return array{decision: string, code: string, reason: string, checkedAt: string, records: array<int, array<string, mixed>>}
	 */
	private function allow(array $records): array {
		return [
			'decision' => self::DECISION_ALLOW,
			'code' => '',
			'reason' => '',
			'checkedAt' => (new DateTime())->format('c'),
			'records' => $records,
		];
	}//end allow()

	/**
	 * Build a refusal.
	 *
	 * @param string $code   The refusal code.
	 * @param string $reason The reason people read.
	 *
	 * @return array{decision: string, code: string, reason: string, checkedAt: string, records: array<int, array<string, mixed>>}
	 */
	private function refuse(string $code, string $reason): array {
		return [
			'decision' => self::DECISION_REFUSE,
			'code' => $code,
			'reason' => $reason,
			'checkedAt' => (new DateTime())->format('c'),
			'records' => [],
		];
	}//end refuse()
}//end class
