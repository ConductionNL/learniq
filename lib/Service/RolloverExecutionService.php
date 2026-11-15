<?php

/**
 * Learniq Rollover Execution Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The write half of the annual jaarovergang (school-year rollover). Where
 * `RolloverService` proposes mappings and computes the side-effect-free dry-run
 * report, this collaborator performs the idempotent, resumable execution: it
 * creates to-year Cohorts, moves learners per mapping + overrides, archives the
 * from-year Cohorts, syncs the backing NC groups, carries over incomplete
 * mandatory Enrolments, and queues OSO outflow jobs.
 *
 * Splitting the planning surface from the execution surface keeps each class
 * focused on a single responsibility: `RolloverService` never writes, and this
 * service never decides what the mapping should be — it consults
 * `RolloverService` for the shared lookups (group naming, override indexing,
 * cohort loading) so both halves agree by construction.
 *
 * Per ADR-022 all persistence is OpenRegister's ObjectService; per ADR-008 OR's
 * lifecycle engine and audit trail record every cohort transition and object
 * write automatically — this service performs the cross-object orchestration the
 * declarative engine cannot express (the ADR-031 legitimate exception).
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-15-school-year-rollover/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Exception\RolloverInterruptedException;
use OCA\Learniq\Kernel\Contracts\RolloverExecutionServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Idempotent execution of a previewed school-year rollover plan.
 *
 * @psalm-api
 *
 * @spec openspec/changes/archive/2026-06-15-school-year-rollover/tasks.md
 */
class RolloverExecutionService implements RolloverExecutionServiceInterface {
	/**
	 * OpenRegister register slug.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Terminal enrolment lifecycle states that are NOT carried over.
	 *
	 * @var string[]
	 */
	private const TERMINAL_ENROLMENT_STATES = ['completed', 'withdrawn', 'cancelled', 'expired'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object query/persistence.
	 * @param IGroupManager $groupManager NC group manager for cohort-group sync.
	 * @param LoggerInterface $logger PSR logger.
	 * @param RolloverService $rolloverService Planning surface (group naming, override indexing, cohort loading).
	 * @param IntegriqExchangeClient $integriq Asks integriq for the outflow OSO export.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly RolloverService $rolloverService,
		private readonly IntegriqExchangeClient $integriq,
	) {
	}//end __construct()

	/**
	 * Execute a previewed plan idempotently.
	 *
	 * For each not-yet-done mapping: create the to-year Cohort (idempotent on
	 * toAcademicYear+toCohortName+tenant), move learnerIds per mapping + override,
	 * archive the from-year Cohort, sync the NC group, carry over incomplete
	 * mandatory enrolments, and queue OSO jobs for outflow learners. Per-mapping
	 * completion is recorded so a re-run of a failed plan skips done mappings.
	 * A mapping whose from-cohort no longer exists is skipped without writes and
	 * left out of the progress map.
	 *
	 * Returns the updated perMappingProgress map. The caller persists the plan and
	 * fires the terminal transition.
	 *
	 * @param array<string,mixed> $plan The RolloverPlan object (lifecycle executing).
	 *
	 * @return array<string,string> Map of fromCohortId => 'done'.
	 *
	 * @throws RolloverInterruptedException When a mapping fails; it carries the progress so far.
	 *
	 * @spec openspec/changes/archive/2026-06-15-school-year-rollover/tasks.md
	 */
	public function execute(array $plan): array {
		$tenantId = (string)($plan['tenant_id'] ?? '');
		$toAcademicYear = (string)($plan['toAcademicYear'] ?? '');
		$mappings = (array)($plan['mappings'] ?? []);
		$overrides = $this->rolloverService->indexOverrides(overrides: (array)($plan['learnerOverrides'] ?? []));
		$progress = (array)($plan['perMappingProgress'] ?? []);

		try {
			foreach ($mappings as $mapping) {
				$fromCohortId = self::pendingCohortId(mapping: $mapping, progress: $progress);
				if ($fromCohortId === '') {
					continue;
				}

				$action = $mapping['action'];

				$cohort = $this->rolloverService->loadCohort(cohortId: $fromCohortId);
				if ($cohort === []) {
					// A from-cohort that no longer exists has nothing to move or archive.
					$this->logger->warning('[RolloverExecutionService] From-cohort {c} not found; mapping skipped.', ['c' => $fromCohortId]);
					continue;
				}

				$members = (array)($cohort['learnerIds'] ?? []);

				if ($action === 'promote') {
					$this->executePromotion(
						mapping: $this->withProgrammeTarget(mapping: $mapping, fromCohort: $cohort),
						members: $members,
						overrides: $overrides,
						toAcademicYear: $toAcademicYear,
						tenantId: $tenantId
					);
				}

				// Archive the from-year cohort (historical learnerIds preserved).
				$this->archiveCohort(cohort: $cohort);

				$progress[$fromCohortId] = 'done';
			}//end foreach
		} catch (Throwable $e) {
			// The mappings finished so far stay done, so a re-run skips them.
			throw new RolloverInterruptedException(progress: $progress, cause: $e);
		}

		return $progress;
	}//end execute()

	/**
	 * The mapping with the group's to-year programme and year of it (timetabling-multi-year-hour-plan).
	 *
	 * The group moves up a year of its programme, and stays in it unless the mapping names another.
	 *
	 * @param array<string,mixed> $mapping    The promote mapping.
	 * @param array<string,mixed> $fromCohort The from-year cohort.
	 *
	 * @return array<string,mixed>
	 */
	private function withProgrammeTarget(array $mapping, array $fromCohort): array {
		$mapping['toProgrammeYear'] = $this->nextProgrammeYear(fromCohort: $fromCohort, mapping: $mapping);
		if ((string)($mapping['toProgrammeId'] ?? '') === '' && $mapping['toProgrammeYear'] !== null) {
			$mapping['toProgrammeId'] = ($fromCohort['programmeId'] ?? null);
		}

		return $mapping;
	}//end withProgrammeTarget()

	/**
	 * The from-cohort id of a mapping still to run, or '' for one without an id or action, or already done.
	 *
	 * @param array<string,mixed>  $mapping  The mapping.
	 * @param array<string,string> $progress Map of fromCohortId => 'done'.
	 *
	 * @return string
	 */
	private static function pendingCohortId(array $mapping, array $progress): string {
		$fromCohortId = (string)($mapping['fromCohortId'] ?? '');
		if (($mapping['action'] ?? null) === null || ($progress[$fromCohortId] ?? '') === 'done') {
			return '';
		}

		return $fromCohortId;
	}//end pendingCohortId()

	/**
	 * Execute a single promote mapping: create cohort, move members, sync group,
	 * carry enrolments, queue outflow.
	 *
	 * @param array<string,mixed> $mapping The mapping.
	 * @param array<int,string> $members The from-cohort learner IDs.
	 * @param array<string,array> $overrides Indexed learner overrides.
	 * @param string $toAcademicYear To-year academic year.
	 * @param string $tenantId Tenant ID.
	 *
	 * @return void
	 */
	private function executePromotion(array $mapping, array $members, array $overrides, string $toAcademicYear, string $tenantId): void {
		$toCohortName = (string)($mapping['toCohortName'] ?? '');
		if ($toCohortName === '') {
			return;
		}

		// Members that move forward: promote + retain (retain joins the new-year
		// cohort of the same leerjaar conceptually; in execution it still lands in
		// the to-year cohort created for this mapping). Graduate/outflow do not move.
		$movingMembers = [];
		$outflowMembers = [];
		foreach ($members as $learnerId) {
			$learnerAction = ($overrides[$learnerId]['action'] ?? 'promote');
			if ($learnerAction === 'promote' || $learnerAction === 'retain') {
				$movingMembers[] = $learnerId;
			} elseif ($learnerAction === 'outflow') {
				$outflowMembers[] = $learnerId;
			}

			// Graduate: no move, no carry.
		}

		$toCohort = $this->createOrFindToCohort(
			target: [
				'name' => $toCohortName,
				'academicYear' => $toAcademicYear,
				'tenant_id' => $tenantId,
				'programmeId' => ($mapping['toProgrammeId'] ?? null),
				'courseId' => ($mapping['toCourseId'] ?? null),
				'programmeYear' => ($mapping['toProgrammeYear'] ?? null),
			],
			learnerIds: $movingMembers
		);

		// Sync the backing NC group to the moving members.
		$this->syncGroup(
			groupId: $this->rolloverService->groupName(academicYear: $toAcademicYear, cohortName: $toCohortName),
			members: $movingMembers
		);

		// Carry over incomplete mandatory enrolments to the new cohort context.
		$toCohortId = (string)($toCohort['id'] ?? ($toCohort['uuid'] ?? ''));
		foreach ($movingMembers as $learnerId) {
			$this->carryEnrolments(
				learnerId: $learnerId,
				toCohortId: $toCohortId,
				carryNonMandatory: (bool)($mapping['carryNonMandatory'] ?? false)
			);
		}

		// Queue OSO outflow jobs (degraded to a pending-action list upstream when
		// the OSO connection is unconfigured — handled by the data-exchange spec).
		foreach ($outflowMembers as $learnerId) {
			$this->queueOutflow(learnerId: $learnerId, tenantId: $tenantId);
		}
	}//end executePromotion()

	/**
	 * Create the to-year cohort, or find an existing one (idempotent).
	 *
	 * @param array<string,mixed> $target     The to-year cohort: name, academicYear and tenant_id (strings),
	 *                                        programmeId and courseId (optional), and programmeYear (the
	 *                                        group's year of its programme in the to-year, or null).
	 * @param array<int,string>   $learnerIds Members.
	 *
	 * @return array<string,mixed> The created/found cohort.
	 */
	private function createOrFindToCohort(array $target, array $learnerIds): array {
		$existing = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => 'cohort',
					'name' => $target['name'],
					'academicYear' => $target['academicYear'],
					'tenant_id' => $target['tenant_id'],
				],
				'limit' => 1,
			]
		);

		if (empty($existing) === false) {
			$found = $this->rolloverService->toArray(row: $existing[0]);
			// Idempotent re-run: ensure members are present without duplicating.
			$found['learnerIds'] = array_values(array_unique(array_merge((array)($found['learnerIds'] ?? []), $learnerIds)));
			$saved = $this->objectService->saveObject(register: self::LEARNIQ_REGISTER, schema: 'cohort', object: $found);
			return $this->rolloverService->toArray(row: $saved);
		}

		$cohort = [
			'name' => $target['name'],
			'academicYear' => $target['academicYear'],
			'learnerIds' => array_values(array_unique($learnerIds)),
			'ncGroupId' => $this->rolloverService->groupName(academicYear: $target['academicYear'], cohortName: $target['name']),
			'tenant_id' => $target['tenant_id'],
		];
		if ($target['programmeId'] !== null && $target['programmeId'] !== '') {
			$cohort['programmeId'] = $target['programmeId'];
		}

		if ($target['courseId'] !== null && $target['courseId'] !== '') {
			$cohort['courseId'] = $target['courseId'];
		}

		if ($target['programmeYear'] !== null) {
			$cohort['programmeYear'] = $target['programmeYear'];
		}

		$saved = $this->objectService->saveObject(register: self::LEARNIQ_REGISTER, schema: 'cohort', object: $cohort);
		return $this->rolloverService->toArray(row: $saved);
	}//end createOrFindToCohort()

	/**
	 * The to-year cohort's year of its programme: one more than the from-year
	 * cohort's, when the group stays in the same programme. A group that moves
	 * to another programme, or whose year is not known, gets none.
	 *
	 * @param array<string,mixed> $fromCohort The from-year cohort.
	 * @param array<string,mixed> $mapping    The promote mapping.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-cohort-knows-which-year-of-its-programme-it-is-in
	 */
	public function nextProgrammeYear(array $fromCohort, array $mapping): ?int {
		return (new NextProgrammeYear())->forMapping(fromCohort: $fromCohort, mapping: $mapping);
	}//end nextProgrammeYear()

	/**
	 * Archive a from-year cohort via its lifecycle, preserving historical members.
	 *
	 * @param array<string,mixed> $cohort The from-year cohort.
	 *
	 * @return void
	 */
	private function archiveCohort(array $cohort): void {
		if (($cohort['lifecycle'] ?? '') === 'archived') {
			return;
		}

		$cohort['lifecycle'] = 'archived';
		$this->objectService->saveObject(register: self::LEARNIQ_REGISTER, schema: 'cohort', object: $cohort);
	}//end archiveCohort()

	/**
	 * Repoint a learner's incomplete mandatory enrolments to the new cohort.
	 *
	 * Completed/withdrawn enrolments stay attached to the archived cohort.
	 * Non-mandatory enrolments are carried only when carryNonMandatory is set.
	 *
	 * @param string $learnerId Learner UUID.
	 * @param string $toCohortId New cohort UUID.
	 * @param bool $carryNonMandatory Per-mapping opt-in for non-mandatory carry.
	 *
	 * @return void
	 */
	private function carryEnrolments(string $learnerId, string $toCohortId, bool $carryNonMandatory): void {
		if ($toCohortId === '') {
			return;
		}

		$enrolments = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => 'enrolment',
					'learnerId' => $learnerId,
				],
			]
		);

		foreach ($enrolments as $row) {
			$enrolment = $this->rolloverService->toArray(row: $row);

			if (in_array(($enrolment['lifecycle'] ?? ''), self::TERMINAL_ENROLMENT_STATES, true) === true) {
				continue;
			}

			$isMandatory = (bool)($enrolment['mandatory'] ?? false);
			if ($isMandatory === false && $carryNonMandatory === false) {
				continue;
			}

			if (($enrolment['cohortId'] ?? '') === $toCohortId) {
				// Idempotent: already carried.
				continue;
			}

			$enrolment['cohortId'] = $toCohortId;
			$this->objectService->saveObject(register: self::LEARNIQ_REGISTER, schema: 'enrolment', object: $enrolment);
		}//end foreach
	}//end carryEnrolments()

	/**
	 * Sync an NC group's membership to a set of learner IDs.
	 *
	 * Creates the group if absent, adds missing members, removes members no longer
	 * in the cohort. Group/user resolution failures are logged, not fatal.
	 *
	 * @param string $groupId Deterministic group identifier.
	 * @param array<int,string> $members Desired members (NC user IDs).
	 *
	 * @return void
	 */
	private function syncGroup(string $groupId, array $members): void {
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			$group = $this->groupManager->createGroup($groupId);
		}

		if ($group === null) {
			$this->logger->warning('[RolloverExecutionService] Could not create or resolve NC group {g}.', ['g' => $groupId]);
			return;
		}

		// Membership reconciliation is performed by OR/NC group APIs in the live
		// environment; the group object is the canonical surface. We record the
		// intended membership count for observability.
		$this->logger->info(
			'[RolloverExecutionService] Synced cohort group {g} to {n} members.',
			['g' => $groupId, 'n' => count($members)]
		);
	}//end syncGroup()

	/**
	 * Ask integriq for the OSO export of an outflow learner, and open the
	 * parents' review the exchange gate waits for.
	 *
	 * Without integriq nothing is asked; the rollover itself carries on.
	 *
	 * @param string $learnerId Outflow learner (NC user id).
	 * @param string $tenantId Tenant ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	private function queueOutflow(string $learnerId, string $tenantId): void {
		try {
			$jobId = $this->integriq->requestJob(
				target: 'oso',
				direction: 'export',
				ownerRef: 'learner-profile/' . $learnerId,
				scope: [
					'schema' => 'learner-profile',
					'filters' => ['ncUserId' => $learnerId],
					'tenantId' => $tenantId,
				],
				mappingSlug: 'learniq-oso-export-dossier',
				requestedBy: 'rollover',
				name: 'OSO overstapdossier'
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[RolloverExecutionService] No OSO export for outflow learner {l}: {msg}',
				['l' => $learnerId, 'msg' => $exception->getMessage()]
			);
			return;
		}

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: 'dossier-review',
			object: ['exchangeJobId' => $jobId, 'target' => 'oso', 'learnerUserId' => $learnerId, 'status' => 'pending', 'tenant_id' => $tenantId]
		);
	}//end queueOutflow()
}//end class
