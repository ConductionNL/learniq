<?php

/**
 * Scholiq Rollover Service
 *
 * Plans and previews the annual jaarovergang (school-year rollover): a
 * default-mapping proposal (leerjaar increment) and a side-effect-free preview
 * of what the rollover would do. This half never writes. The write half —
 * creating to-year Cohorts, moving learners, archiving from-year Cohorts,
 * syncing NC groups, carrying Enrolments and queueing OSO outflow jobs — lives
 * in `RolloverExecutionService`, which consults this service for the shared
 * lookups so plan and execution agree by construction.
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
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-year-rollover/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Mapping-proposal and preview semantics for the school-year rollover.
 *
 * @spec openspec/changes/school-year-rollover/tasks.md
 */
class RolloverService {
	/**
	 * OpenRegister register slug.
	 */
	private const SCHOLIQ_REGISTER = 'learniq';

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
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Propose a default per-cohort mapping by incrementing the leerjaar.
	 *
	 * For each from-year cohort, parse the leading leerjaar digit from its name
	 * (e.g. "2A" → 2) and propose a `promote` action whose `toCohortName` carries
	 * the incremented digit ("3A"). A cohort whose name has no parseable leading
	 * digit yields `action: null` so the preview is blocked until a human resolves
	 * it — no silent guessing (D2).
	 *
	 * @param array<int,array<string,mixed>> $fromCohorts The from-year cohorts
	 *                                                    (each with id + name +
	 *                                                    programmeId).
	 *
	 * @return array<int,array<string,mixed>> Proposed mappings.
	 *
	 * @spec openspec/changes/school-year-rollover/tasks.md
	 */
	public function proposeDefaultMapping(array $fromCohorts): array {
		$mappings = [];
		foreach ($fromCohorts as $cohort) {
			$name = (string)($cohort['name'] ?? '');
			$id = (string)($cohort['id'] ?? ($cohort['uuid'] ?? ''));

			$mapping = [
				'fromCohortId' => $id,
				'action' => null,
				'toCohortName' => null,
				'toProgrammeId' => ($cohort['programmeId'] ?? null),
			];

			if (preg_match('/^(\d+)(.*)$/', $name, $matches) === 1) {
				$leerjaar = (int)$matches[1];
				$suffix = $matches[2];
				$mapping['action'] = 'promote';
				$mapping['toCohortName'] = ($leerjaar + 1) . $suffix;
			}

			$mappings[] = $mapping;
		}//end foreach

		return $mappings;
	}//end proposeDefaultMapping()

	/**
	 * Produce a side-effect-free preview report for a plan.
	 *
	 * Computes per-cohort promote/retain/graduate/outflow counts, the cohorts to
	 * create, the incomplete mandatory enrolments to carry over, and the NC groups
	 * to sync — WITHOUT writing anything. A mapping whose action is null makes the
	 * preview "blocked" (the plan cannot be executed until resolved).
	 *
	 * @param array<string,mixed> $plan The RolloverPlan object.
	 *
	 * @return array<string,mixed> The dry-run report.
	 *
	 * @spec openspec/changes/school-year-rollover/tasks.md
	 */
	public function preview(array $plan): array {
		$mappings = (array)($plan['mappings'] ?? []);
		$overrides = $this->indexOverrides(overrides: (array)($plan['learnerOverrides'] ?? []));

		$report = [
			'blocked' => false,
			'blockingCohorts' => [],
			'cohortsToCreate' => [],
			'counts' => ['promote' => 0, 'retain' => 0, 'graduate' => 0, 'outflow' => 0, 'dissolve' => 0],
			'enrolmentsToCarry' => 0,
			'ncGroupsToSync' => [],
		];

		foreach ($mappings as $mapping) {
			$fromCohortId = (string)($mapping['fromCohortId'] ?? '');
			$action = ($mapping['action'] ?? null);

			if ($action === null) {
				$report['blocked'] = true;
				$report['blockingCohorts'][] = $fromCohortId;
				continue;
			}

			$cohort = $this->loadCohort(cohortId: $fromCohortId);
			$members = (array)($cohort['learnerIds'] ?? []);

			if ($action === 'dissolve') {
				$report['counts']['dissolve']++;
				continue;
			}

			if ($action === 'graduate') {
				$report['counts']['graduate'] += count($members);
				continue;
			}

			// Promote: classify each member by override.
			$toCohortName = (string)($mapping['toCohortName'] ?? '');
			if ($toCohortName !== '') {
				$report['cohortsToCreate'][] = $toCohortName;
				$report['ncGroupsToSync'][] = $this->groupName(academicYear: (string)($plan['toAcademicYear'] ?? ''), cohortName: $toCohortName);
			}

			foreach ($members as $learnerId) {
				$learnerAction = ($overrides[$learnerId]['action'] ?? 'promote');
				$report['counts'][$learnerAction] = (($report['counts'][$learnerAction] ?? 0) + 1);
			}

			$report['enrolmentsToCarry'] += $this->countCarryableEnrolments(
				learnerIds: $members,
				overrides: $overrides,
				carryNonMandatory: (bool)($mapping['carryNonMandatory'] ?? false)
			);
		}//end foreach

		$report['cohortsToCreate'] = array_values(array_unique($report['cohortsToCreate']));
		$report['ncGroupsToSync'] = array_values(array_unique($report['ncGroupsToSync']));

		return $report;
	}//end preview()

	/**
	 * Deterministic NC group name for a to-year cohort.
	 *
	 * @param string $academicYear The to-year academic year.
	 * @param string $cohortName The to-year cohort name.
	 *
	 * @return string A stable group identifier.
	 *
	 * @spec openspec/changes/school-year-rollover/tasks.md
	 */
	public function groupName(string $academicYear, string $cohortName): string {
		$slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $academicYear . '-' . $cohortName) ?? '');
		return 'scholiq-cohort-' . trim($slug, '-');
	}//end groupName()

	/**
	 * Whether a plan's stored preview still matches its current mappings.
	 *
	 * Editing mappings after a preview must drop the plan back to draft (the dry
	 * run no longer matches). This compares the report's blocking set / create
	 * list against a fresh preview of the current mappings.
	 *
	 * @param array<string,mixed> $plan The RolloverPlan object.
	 *
	 * @return bool True when the stored dryRunReport matches the current mappings.
	 *
	 * @spec openspec/changes/school-year-rollover/tasks.md
	 */
	public function previewMatchesMappings(array $plan): bool {
		$stored = ($plan['dryRunReport'] ?? null);
		if (is_array($stored) === false) {
			return false;
		}

		$fresh = $this->preview(plan: $plan);

		return ($stored['blocked'] ?? null) === $fresh['blocked']
			&& ($stored['cohortsToCreate'] ?? []) === $fresh['cohortsToCreate']
			&& ($stored['counts'] ?? []) === $fresh['counts'];
	}//end previewMatchesMappings()

	/**
	 * Index learner overrides by learnerId for O(1) lookup.
	 *
	 * Shared with `RolloverExecutionService` so preview and execution classify
	 * the same learner the same way.
	 *
	 * @param array<int,array<string,mixed>> $overrides Raw overrides array.
	 *
	 * @return array<string,array<string,mixed>> Indexed overrides.
	 *
	 * @spec openspec/changes/school-year-rollover/tasks.md
	 */
	public function indexOverrides(array $overrides): array {
		$indexed = [];
		foreach ($overrides as $override) {
			$learnerId = (string)($override['learnerId'] ?? '');
			if ($learnerId !== '') {
				$indexed[$learnerId] = $override;
			}
		}

		return $indexed;
	}//end indexOverrides()

	/**
	 * Load a cohort by id as a plain array.
	 *
	 * Shared with `RolloverExecutionService` so both halves read a cohort the
	 * same way.
	 *
	 * @param string $cohortId Cohort UUID.
	 *
	 * @return array<string,mixed> The cohort, or an empty array when not found.
	 *
	 * @spec openspec/changes/school-year-rollover/tasks.md
	 */
	public function loadCohort(string $cohortId): array {
		if ($cohortId === '') {
			return [];
		}

		$obj = $this->objectService->find(id: $cohortId, register: self::SCHOLIQ_REGISTER, schema: 'cohort');
		if ($obj === null) {
			return [];
		}

		return $this->toArray(row: $obj);
	}//end loadCohort()

	/**
	 * Normalise an OR result (entity or array) to a plain array.
	 *
	 * Shared with `RolloverExecutionService`.
	 *
	 * @param mixed $row Entity with jsonSerialize() or a plain array.
	 *
	 * @return array<string,mixed> The row as an associative array.
	 *
	 * @spec openspec/changes/school-year-rollover/tasks.md
	 */
	public function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

	/**
	 * Count carryable enrolments for a member set (preview only — no writes).
	 *
	 * @param array<int,string> $learnerIds Members.
	 * @param array<string,array> $overrides Indexed overrides.
	 * @param bool $carryNonMandatory Whether non-mandatory counts.
	 *
	 * @return int Count of enrolments that would be carried over.
	 */
	private function countCarryableEnrolments(array $learnerIds, array $overrides, bool $carryNonMandatory): int {
		$count = 0;
		foreach ($learnerIds as $learnerId) {
			$learnerAction = ($overrides[$learnerId]['action'] ?? 'promote');
			if ($learnerAction !== 'promote' && $learnerAction !== 'retain') {
				continue;
			}

			$enrolments = $this->objectService->findAll(
				[
					'register' => self::SCHOLIQ_REGISTER,
					'schema' => 'enrolment',
					'filters' => ['learnerId' => $learnerId],
				]
			);

			foreach ($enrolments as $row) {
				$enrolment = $this->toArray(row: $row);
				if (in_array(($enrolment['lifecycle'] ?? ''), self::TERMINAL_ENROLMENT_STATES, true) === true) {
					continue;
				}

				if ((bool)($enrolment['mandatory'] ?? false) === true || $carryNonMandatory === true) {
					$count++;
				}
			}
		}//end foreach

		return $count;
	}//end countCarryableEnrolments()
}//end class
