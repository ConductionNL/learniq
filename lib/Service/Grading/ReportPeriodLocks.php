<?php

/**
 * Learniq Report Period Locks
 *
 * Answers whether a report period is locked, and which locked period governs
 * a grade entry. `isLocked` is declared as a materialised calculation on the
 * ReportPeriod schema, but OpenRegister returns it only on the create
 * response: a stored period reads `isLocked` null (live pass 2 Oct, D2), and
 * a period created before its lock date would keep a stale false. So the
 * answer is computed from `lockDate` every time, and a stored `true` counts
 * too. The publish guard, the compose guard and the published-grade freeze
 * all ask this one class, so they cannot disagree about a period.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Grading
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Grading;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Whether a report period is locked, and the locked period over a grade entry.
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */
class ReportPeriodLocks {

	private const REGISTER = 'learniq';

	private const SCHEMA = 'report-period';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objects,
	) {
	}//end __construct()

	/**
	 * Whether a report period is locked: its stored `isLocked` is true, or its
	 * `lockDate` is set and has passed.
	 *
	 * @param array<string,mixed> $period The report period as stored or returned.
	 * @param int|null $now The moment to compare with (unix time), now when null.
	 *
	 * @return bool True when locked.
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
	 */
	public function isLocked(array $period, ?int $now = null): bool {
		if (($period['isLocked'] ?? null) === true) {
			return true;
		}

		$lockDate = ($period['lockDate'] ?? null);
		if (is_string($lockDate) === false || trim($lockDate) === '') {
			return false;
		}

		$lockTimestamp = strtotime($lockDate);
		if ($lockTimestamp === false) {
			return false;
		}

		return $lockTimestamp < ($now ?? time());
	}//end isLocked()

	/**
	 * The report period that governs a grade entry: same `periodCode` as the
	 * entry's `period`, the entry's curriculum plan in `curriculumPlanIds`, same
	 * tenant. Null when the entry names no period or plan, or none matches.
	 *
	 * @param array<string,mixed> $entry The grade entry.
	 *
	 * @return array<string,mixed>|null The governing period.
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
	 */
	public function governing(array $entry): ?array {
		$period = (string)($entry['period'] ?? '');
		$curriculumPlanId = (string)($entry['curriculumPlanId'] ?? '');
		$tenantId = (string)($entry['tenant_id'] ?? '');
		if ($period === '' || $curriculumPlanId === '') {
			return null;
		}

		$filters = ['register' => self::REGISTER, 'schema' => self::SCHEMA, 'periodCode' => $period];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$candidates = $this->objects->findAll(['filters' => $filters, 'limit' => 500]);
		foreach ($candidates as $candidate) {
			$data = $candidate;
			if (is_array($candidate) === false) {
				$data = $candidate->jsonSerialize();
			}

			$planIds = ($data['curriculumPlanIds'] ?? []);
			if (is_array($planIds) === true && in_array($curriculumPlanId, $planIds, true) === true) {
				return $data;
			}
		}

		return null;
	}//end governing()

	/**
	 * The locked report period over a grade entry, or null when the entry is
	 * not in a locked period.
	 *
	 * @param array<string,mixed> $entry The grade entry.
	 *
	 * @return array<string,mixed>|null The locked period.
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
	 */
	public function lockedPeriodFor(array $entry): ?array {
		$period = $this->governing(entry: $entry);
		if ($period === null || $this->isLocked(period: $period) === false) {
			return null;
		}

		return $period;
	}//end lockedPeriodFor()
}//end class
