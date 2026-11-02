<?php

/**
 * Learniq Conflict Scan
 *
 * The state of one conflict scan: its tenant, its lookup caches, the open
 * conflicts it must not repeat, and the conflict rows it has queued.
 *
 * @category Timetabling
 * @package  OCA\Learniq\Timetabling
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
 * @spec openspec/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling;

/**
 * The state one TimetableConflictDetector scan carries from pair to pair.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */
final class ConflictScan {

	/**
	 * Cohort cache, keyed by cohortId. Null is a cached miss.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	public array $cohortCache = [];

	/**
	 * Room cache, keyed by roomId. Null is a cached miss.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	public array $roomCache = [];

	/**
	 * Session id to linked Assessment id. Null is a cached miss.
	 *
	 * @var array<string,string|null>
	 */
	public array $assessmentCache = [];

	/**
	 * TimetableConflict rows to persist, in queue order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $toCreate = [];

	/**
	 * Constructor.
	 *
	 * @param string             $tenantId     Tenant scope.
	 * @param array<string,true> $existingOpen Keys of the open (sessionIds, kind) conflicts.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $tenantId,
		public readonly array $existingOpen,
	) {
	}//end __construct()
}//end class
