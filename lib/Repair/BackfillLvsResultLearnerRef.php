<?php

/**
 * Repair step that back-fills LvsResult.learnerRef on rows written before the
 * server started stamping it.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/lvs-result-learner-ref/specs/data-exchange/spec.md#requirement-existing-lvsresults-are-back-filled-once
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Repair\Support\RepairRowWalk;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps `learnerRef` on every existing LvsResult that has a `learnerId` and
 * no `learnerRef`, so Cito, IEP, Boom and Dia results imported before
 * `LvsResultLearnerRefStamp` existed name their pupil by LearnerProfile too.
 *
 * Idempotent: rows that carry a `learnerRef` are skipped without a lookup, and
 * rows whose learner has no profile are left alone. A second run saves
 * nothing new. Runs without a session, so every read and write passes
 * `_rbac: false` and `_multitenancy: false`, and the profile is looked up in
 * the tenant the result carries.
 *
 * Only `learnerRef` is added: the score fields of a verified result stay as
 * they are, so the write passes LvsResultFreezeListener.
 *
 * @spec openspec/changes/lvs-result-learner-ref/specs/data-exchange/spec.md#requirement-existing-lvsresults-are-back-filled-once
 */
class BackfillLvsResultLearnerRef implements IRepairStep {

	private const LEARNIQ_REGISTER = 'learniq';
	private const LVS_SCHEMA = 'lvs-result';

	/**
	 * Pages through the schema this step back-fills.
	 *
	 * @var RepairRowWalk
	 */
	private readonly RepairRowWalk $rows;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param LearnerRefResolver $learnerRefs Nextcloud user id to LearnerProfile UUID.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LearnerRefResolver $learnerRefs,
		private readonly LoggerInterface $logger,
	) {
		$this->rows = new RepairRowWalk(objectService: $objectService, logger: $logger, label: 'BackfillLvsResultLearnerRef');
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/lvs-result-learner-ref/specs/data-exchange/spec.md#requirement-existing-lvsresults-are-back-filled-once
	 */
	public function getName(): string {
		return 'Link existing LVS results to the learner profile of their pupil';
	}//end getName()

	/**
	 * Page through every LvsResult and stamp the ones missing learnerRef.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lvs-result-learner-ref/specs/data-exchange/spec.md#requirement-existing-lvsresults-are-back-filled-once
	 */
	public function run(IOutput $output): void {
		$cache = [];
		$counts = $this->rows->walk(
			schema: self::LVS_SCHEMA,
			visit: function (array $row) use (&$cache): ?string {
				return $this->stampRow(row: $row, cache: $cache);
			}
		);

		$output->info(
			'BackfillLvsResultLearnerRef: ' . $counts->of(key: 'stamped') . ' stamped, ' . $counts->of(key: 'noProfile')
			. ' without a learner profile, ' . $counts->of(key: 'failed') . ' failed, of ' . $counts->of(key: 'scanned') . ' scanned.'
		);
	}//end run()

	/**
	 * Stamp one row when it needs it.
	 *
	 * @param array<string, mixed> $row The LvsResult.
	 * @param array<string, string|null> $cache Learner and tenant to profile UUID, per run.
	 *
	 * @return string|null Counter to bump, or null when the row needed nothing.
	 */
	private function stampRow(array $row, array &$cache): ?string {
		$candidate = $this->candidate(row: $row);
		if ($candidate === null) {
			return null;
		}

		[$learnerId, $uuid, $tenantId] = $candidate;
		$key = $tenantId . '|' . $learnerId;
		try {
			if (array_key_exists($key, $cache) === false) {
				$cache[$key] = $this->lookup(learnerId: $learnerId, tenantId: $tenantId);
			}

			if ($cache[$key] === null) {
				return 'noProfile';
			}

			$this->objectService->saveObject(
				object: array_merge($row, ['learnerRef' => $cache[$key]]),
				register: self::LEARNIQ_REGISTER,
				schema: self::LVS_SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[BackfillLvsResultLearnerRef] Could not stamp LVS result {id}: {msg}',
				['id' => $uuid, 'msg' => $exception->getMessage()]
			);
			return 'failed';
		}//end try

		return 'stamped';
	}//end stampRow()

	/**
	 * The profile UUID for a learner, in the result's tenant when it has one.
	 *
	 * @param string $learnerId Nextcloud user id.
	 * @param string $tenantId The result's tenant, empty when it has none.
	 *
	 * @return string|null
	 */
	private function lookup(string $learnerId, string $tenantId): ?string {
		if ($tenantId !== '') {
			return $this->learnerRefs->resolveInTenant(learnerId: $learnerId, tenantId: $tenantId);
		}

		return $this->learnerRefs->resolveAcrossTenants(learnerId: $learnerId);
	}//end lookup()

	/**
	 * The learnerId, uuid and tenant of a row that still needs a learnerRef,
	 * or null when it already has one or lacks a learnerId or uuid.
	 *
	 * @param array<string, mixed> $row The LvsResult.
	 *
	 * @return array{0: string, 1: string, 2: string}|null
	 */
	private function candidate(array $row): ?array {
		$existing = ($row['learnerRef'] ?? null);
		if (is_string($existing) === true && $existing !== '') {
			return null;
		}

		$learnerId = ($row['learnerId'] ?? null);
		$uuid = ($row['id'] ?? ($row['uuid'] ?? null));
		if (is_string($learnerId) === false || $learnerId === '' || is_string($uuid) === false || $uuid === '') {
			return null;
		}

		$tenantId = ($row['tenant_id'] ?? '');
		if (is_string($tenantId) === false) {
			$tenantId = '';
		}

		return [$learnerId, $uuid, $tenantId];
	}//end candidate()
}//end class
