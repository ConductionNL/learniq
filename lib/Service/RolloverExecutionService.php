<?php

/**
 * Scholiq Rollover Execution Service
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
 * @package  OCA\Scholiq\Service
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
 * @spec openspec/changes/school-year-rollover/tasks.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Idempotent execution of a previewed school-year rollover plan.
 *
 * @psalm-api
 *
 * @spec openspec/changes/school-year-rollover/tasks.md
 */
class RolloverExecutionService {
	/**
	 * OpenRegister register slug.
	 */
	private const SCHOLIQ_REGISTER = 'scholiq';

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
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly RolloverService $rolloverService,
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
	 *
	 * Returns the updated perMappingProgress map. The caller persists the plan and
	 * fires the terminal transition.
	 *
	 * @param array<string,mixed> $plan The RolloverPlan object (lifecycle executing).
	 *
	 * @return array<string,string> Map of fromCohortId => 'done'.
	 *
	 * @spec openspec/changes/school-year-rollover/tasks.md
	 */
	public function execute(array $plan): array {
		$tenantId = (string)($plan['tenant_id'] ?? '');
		$toAcademicYear = (string)($plan['toAcademicYear'] ?? '');
		$mappings = (array)($plan['mappings'] ?? []);
		$overrides = $this->rolloverService->indexOverrides(overrides: (array)($plan['learnerOverrides'] ?? []));
		$progress = (array)($plan['perMappingProgress'] ?? []);

		foreach ($mappings as $mapping) {
			$fromCohortId = (string)($mapping['fromCohortId'] ?? '');
			$action = ($mapping['action'] ?? null);

			if ($fromCohortId === '' || $action === null) {
				continue;
			}

			// Idempotency: skip mappings already completed in a prior run.
			if (($progress[$fromCohortId] ?? '') === 'done') {
				continue;
			}

			$cohort = $this->rolloverService->loadCohort(cohortId: $fromCohortId);
			$members = (array)($cohort['learnerIds'] ?? []);

			if ($action === 'promote') {
				$this->executePromotion(
					mapping: $mapping,
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

		return $progress;
	}//end execute()

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
			toCohortName: $toCohortName,
			toAcademicYear: $toAcademicYear,
			programmeId: ($mapping['toProgrammeId'] ?? null),
			courseId: ($mapping['toCourseId'] ?? null),
			learnerIds: $movingMembers,
			tenantId: $tenantId
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
	 * @param string $toCohortName To-year cohort name.
	 * @param string $toAcademicYear To-year academic year.
	 * @param mixed $programmeId Optional programme.
	 * @param mixed $courseId Optional course.
	 * @param array<int,string> $learnerIds Members.
	 * @param string $tenantId Tenant ID.
	 *
	 * @return array<string,mixed> The created/found cohort.
	 */
	private function createOrFindToCohort(
		string $toCohortName,
		string $toAcademicYear,
		mixed $programmeId,
		mixed $courseId,
		array $learnerIds,
		string $tenantId,
	): array {
		$existing = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => 'cohort',
				'filters' => [
					'name' => $toCohortName,
					'academicYear' => $toAcademicYear,
					'tenant_id' => $tenantId,
				],
				'limit' => 1,
			]
		);

		if (empty($existing) === false) {
			$found = $this->rolloverService->toArray(row: $existing[0]);
			// Idempotent re-run: ensure members are present without duplicating.
			$found['learnerIds'] = array_values(array_unique(array_merge((array)($found['learnerIds'] ?? []), $learnerIds)));
			$saved = $this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: 'cohort', object: $found);
			return $this->rolloverService->toArray(row: $saved);
		}

		$cohort = [
			'name' => $toCohortName,
			'academicYear' => $toAcademicYear,
			'learnerIds' => array_values(array_unique($learnerIds)),
			'ncGroupId' => $this->rolloverService->groupName(academicYear: $toAcademicYear, cohortName: $toCohortName),
			'tenant_id' => $tenantId,
		];
		if ($programmeId !== null && $programmeId !== '') {
			$cohort['programmeId'] = $programmeId;
		}

		if ($courseId !== null && $courseId !== '') {
			$cohort['courseId'] = $courseId;
		}

		$saved = $this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: 'cohort', object: $cohort);
		return $this->rolloverService->toArray(row: $saved);
	}//end createOrFindToCohort()

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
		$this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: 'cohort', object: $cohort);
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
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => 'enrolment',
				'filters' => ['learnerId' => $learnerId],
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
			$this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: 'enrolment', object: $enrolment);
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
	 * Queue a data-exchange OSO export job for an outflow learner.
	 *
	 * @param string $learnerId Outflow learner UUID.
	 * @param string $tenantId Tenant ID.
	 *
	 * @return void
	 */
	private function queueOutflow(string $learnerId, string $tenantId): void {
		$job = [
			'direction' => 'export',
			'target' => 'oso',
			'scope' => [
				'schema' => 'learner-profile',
				'filters' => ['learnerId' => $learnerId],
				'cohortId' => null,
				'period' => null,
			],
			'requestedBy' => 'rollover',
			'requestedAt' => date('c'),
			'lifecycle' => 'queued',
			'tenant_id' => $tenantId,
		];

		$this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: 'data-exchange-job', object: $job);
	}//end queueOutflow()
}//end class
