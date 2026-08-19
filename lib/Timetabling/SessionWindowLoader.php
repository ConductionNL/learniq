<?php

/**
 * Scholiq Session Window Loader
 *
 * The OpenRegister read side of the conflict scan, split out of
 * {@see \OCA\Learniq\Timetabling\TimetableConflictDetector} so the detector
 * owns only the decision logic and the TimetableConflict writes.
 *
 * Everything here is a read: the affected date window (never a full-register
 * scan — only Sessions sharing one of the changed Sessions' day buckets), the
 * Cohort/Room lookups behind their per-scan caches, the Assessment link probe,
 * and the tenant's currently `open` TimetableConflict rows. Nothing in this
 * class ever creates, updates or deletes an object.
 *
 * The per-scan caches are passed in by reference rather than held as state, so
 * two concurrent scans in one request can never see each other's entries.
 *
 * @category Service
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Read-only OpenRegister access for the timetable conflict scan: the affected
 * Session window plus the Cohort, Room, Assessment and TimetableConflict
 * lookups it needs.
 *
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */
class SessionWindowLoader {

	private const SCHOLIQ_REGISTER = 'learniq';
	private const SESSION_SCHEMA = 'session';
	private const COHORT_SCHEMA = 'cohort';
	private const ROOM_SCHEMA = 'room';
	private const ASSESSMENT_SCHEMA = 'assessment';
	private const TIMETABLE_CONFLICT_SCHEMA = 'timetable-conflict';

	/**
	 * Lifecycle states excluded from the pairwise scan — a cancelled Session
	 * no longer occupies its slot.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_LIFECYCLES = ['cancelled'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Collect the distinct `sessionDayBucket` values (or a computed
	 * fallback derived from `startsAt`'s calendar date) present in the given
	 * Sessions.
	 *
	 * @param array<int,array<string,mixed>> $sessions Session data arrays.
	 *
	 * @return array<int,int|string> Distinct day-bucket values.
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
	 */
	public function dayBuckets(array $sessions): array {
		$buckets = [];
		foreach ($sessions as $session) {
			if (isset($session['sessionDayBucket']) === true) {
				$buckets[$session['sessionDayBucket']] = true;
				continue;
			}

			$startsAt = (string)($session['startsAt'] ?? '');
			$stamp = strtotime($startsAt);
			if ($stamp === false) {
				continue;
			}

			$buckets[gmdate('Y-m-d', $stamp)] = true;
		}

		return array_keys($buckets);
	}//end dayBuckets()

	/**
	 * Load the scan window: every non-cancelled Session sharing one of the
	 * given day buckets (same tenant), merged with the input Sessions
	 * themselves (in case a freshly-saved row has not yet materialised its
	 * `sessionDayBucket`) — never a full-register scan.
	 *
	 * @param array<int,array<string,mixed>> $sessions The input Session data arrays.
	 * @param array<int,int|string> $buckets Distinct day-bucket values.
	 * @param string $tenantId Tenant scope.
	 *
	 * @return array<string,array<string,mixed>> Window sessions keyed by id, lifecycle-filtered.
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-two-sessions-imported-for-the-same-room-at-overlapping-times-are-flagged-not-auto-moved
	 */
	public function loadWindow(array $sessions, array $buckets, string $tenantId): array {
		$window = [];

		foreach ($sessions as $session) {
			$id = (string)($session['id'] ?? ($session['uuid'] ?? ''));
			if ($id === '' || in_array(($session['lifecycle'] ?? ''), self::EXCLUDED_LIFECYCLES, true) === true) {
				continue;
			}

			$window[$id] = $session;
		}

		foreach ($buckets as $bucket) {
			$filters = ['sessionDayBucket' => $bucket];
			if ($tenantId !== '') {
				$filters['tenant_id'] = $tenantId;
			}

			$results = $this->objectService->findAll(
				[
					'register' => self::SCHOLIQ_REGISTER,
					'schema' => self::SESSION_SCHEMA,
					'filters' => $filters,
					'limit' => 2000,
				]
			);

			foreach ($results as $row) {
				$data = $this->normalise(row: $row);
				$id = (string)($data['id'] ?? ($data['uuid'] ?? ''));
				if ($id === '' || in_array(($data['lifecycle'] ?? ''), self::EXCLUDED_LIFECYCLES, true) === true) {
					continue;
				}

				$window[$id] = $data;
			}
		}//end foreach

		return $window;
	}//end loadWindow()

	/**
	 * Load every `open` TimetableConflict row for the tenant.
	 *
	 * @param string $tenantId Tenant scope.
	 *
	 * @return array<int,array<string,mixed>> The open conflict rows.
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-re-scanning-an-unchanged-window-does-not-create-duplicate-conflicts
	 */
	public function loadOpenConflicts(string $tenantId): array {
		$filters = ['lifecycle' => 'open'];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::TIMETABLE_CONFLICT_SCHEMA,
				'filters' => $filters,
				'limit' => 5000,
			]
		);

		$rows = [];
		foreach ($results as $row) {
			$rows[] = $this->normalise(row: $row);
		}

		return $rows;
	}//end loadOpenConflicts()

	/**
	 * Whether a Session has at least one linked Assessment (Assessment.sessionId === this Session).
	 *
	 * @param string $sessionId Session UUID.
	 * @param string $tenantId Tenant scope.
	 * @param array<string,string|null> $cache Per-scan cache, keyed by sessionId (mutated: entries added).
	 *
	 * @return bool True when a linked Assessment exists.
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-an-exam-session-exceeding-room-capacity-is-flagged-as-room-capacity-exceeded
	 */
	public function hasLinkedAssessment(string $sessionId, string $tenantId, array &$cache): bool {
		if ($sessionId === '') {
			return false;
		}

		if (array_key_exists($sessionId, $cache) === true) {
			return $cache[$sessionId] !== null;
		}

		$filters = ['sessionId' => $sessionId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::ASSESSMENT_SCHEMA,
				'filters' => $filters,
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			$cache[$sessionId] = null;
			return false;
		}

		$assessment = $this->normalise(row: $results[0]);
		$cache[$sessionId] = (string)($assessment['id'] ?? ($assessment['uuid'] ?? 'unknown'));

		return true;
	}//end hasLinkedAssessment()

	/**
	 * Load a Cohort by UUID, cached per scan.
	 *
	 * @param string $cohortId Cohort UUID.
	 * @param string $tenantId Tenant scope.
	 * @param array<string,array<string,mixed>> $cache Per-scan cache (mutated: entries added).
	 *
	 * @return array<string,mixed>|null The cohort data, or null.
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
	 */
	public function loadCohort(string $cohortId, string $tenantId, array &$cache): ?array {
		if ($cohortId === '') {
			return null;
		}

		if (array_key_exists($cohortId, $cache) === true) {
			return $cache[$cohortId];
		}

		$filters = ['id' => $cohortId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::COHORT_SCHEMA,
				'filters' => $filters,
				'limit' => 1,
			]
		);

		$cohort = null;
		if (empty($results) === false) {
			$cohort = $this->normalise(row: $results[0]);
		}

		$cache[$cohortId] = $cohort;

		return $cohort;
	}//end loadCohort()

	/**
	 * Load a Room by UUID, cached per scan.
	 *
	 * @param string $roomId Room UUID.
	 * @param string $tenantId Tenant scope.
	 * @param array<string,array<string,mixed>> $cache Per-scan cache (mutated: entries added).
	 *
	 * @return array<string,mixed>|null The room data, or null.
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-an-exam-session-exceeding-room-capacity-is-flagged-as-room-capacity-exceeded
	 */
	public function loadRoom(string $roomId, string $tenantId, array &$cache): ?array {
		if ($roomId === '') {
			return null;
		}

		if (array_key_exists($roomId, $cache) === true) {
			return $cache[$roomId];
		}

		$filters = ['id' => $roomId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::ROOM_SCHEMA,
				'filters' => $filters,
				'limit' => 1,
			]
		);

		$room = null;
		if (empty($results) === false) {
			$room = $this->normalise(row: $results[0]);
		}

		$cache[$roomId] = $room;

		return $room;
	}//end loadRoom()

	/**
	 * Normalise an ObjectService row to a plain array.
	 *
	 * @param mixed $row Raw row from ObjectService::findAll().
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		return $row->jsonSerialize();
	}//end normalise()
}//end class
