<?php

/**
 * Learniq Session Window Loader
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
 * @spec openspec/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Read-only OpenRegister access for the timetable conflict scan: the affected
 * Session window plus the Cohort, Room, Assessment and TimetableConflict
 * lookups it needs.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */
class SessionWindowLoader {

	private const LEARNIQ_REGISTER = 'learniq';
	private const SESSION_SCHEMA = 'session';
	private const COHORT_SCHEMA = 'cohort';
	private const ROOM_SCHEMA = 'room';
	private const ASSESSMENT_SCHEMA = 'exam';
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
	 * @spec openspec/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
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
	 * Load the scan window: every non-cancelled Session sharing a day bucket
	 * with the given Sessions (same tenant), merged with the input Sessions
	 * themselves (in case a freshly-saved row has not yet materialised its
	 * `sessionDayBucket`) — never a full-register scan.
	 *
	 * @param array<int,array<string,mixed>> $sessions The input Session data arrays.
	 * @param string                         $tenantId Tenant scope.
	 *
	 * @return array<string,array<string,mixed>> Window sessions keyed by id, lifecycle-filtered.
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-two-sessions-imported-for-the-same-room-at-overlapping-times-are-flagged-not-auto-moved
	 */
	public function loadWindow(array $sessions, string $tenantId): array {
		$window = self::liveById(rows: $sessions);
		foreach ($this->dayBuckets(sessions: $sessions) as $bucket) {
			$rows = $this->query(
				schema: self::SESSION_SCHEMA,
				query: ['filters' => self::tenantScoped(filters: ['sessionDayBucket' => $bucket], tenantId: $tenantId), 'limit' => 2000]
			);
			$window = array_replace($window, self::liveById(rows: $rows));
		}

		return $window;
	}//end loadWindow()

	/**
	 * Load every `open` TimetableConflict row for the tenant.
	 *
	 * @param string $tenantId Tenant scope.
	 *
	 * @return array<int,array<string,mixed>> The open conflict rows.
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-re-scanning-an-unchanged-window-does-not-create-duplicate-conflicts
	 */
	public function loadOpenConflicts(string $tenantId): array {
		$rows = $this->query(
			schema: self::TIMETABLE_CONFLICT_SCHEMA,
			query: ['filters' => self::tenantScoped(filters: ['lifecycle' => 'open'], tenantId: $tenantId), 'limit' => 5000]
		);

		return array_values($rows);
	}//end loadOpenConflicts()

	/**
	 * Whether a Session has at least one linked Assessment (Assessment.sessionId === this Session),
	 * cached on the scan.
	 *
	 * @param string       $sessionId Session UUID.
	 * @param ConflictScan $scan      The running scan (assessment cache mutated: a miss is cached as null).
	 *
	 * @return bool True when a linked Assessment exists.
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-an-exam-session-exceeding-room-capacity-is-flagged-as-room-capacity-exceeded
	 */
	public function hasLinkedAssessment(string $sessionId, ConflictScan $scan): bool {
		if ($sessionId === '') {
			return false;
		}

		if (array_key_exists($sessionId, $scan->assessmentCache) === false) {
			$assessment = $this->first(
				schema: self::ASSESSMENT_SCHEMA,
				query: ['filters' => self::tenantScoped(filters: ['sessionId' => $sessionId], tenantId: $scan->tenantId), 'limit' => 1]
			);
			$scan->assessmentCache[$sessionId] = self::assessmentRef(assessment: $assessment);
		}

		return $scan->assessmentCache[$sessionId] !== null;
	}//end hasLinkedAssessment()

	/**
	 * Load a Cohort by UUID, cached on the scan.
	 *
	 * @param string       $cohortId Cohort UUID.
	 * @param ConflictScan $scan     The running scan (cohort cache mutated: a miss is cached as null,
	 *                               so a repeat lookup short-circuits instead of re-querying).
	 *
	 * @return array<string,mixed>|null The cohort data, or null.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
	 */
	public function loadCohort(string $cohortId, ConflictScan $scan): ?array {
		if ($cohortId === '') {
			return null;
		}

		if (array_key_exists($cohortId, $scan->cohortCache) === false) {
			$scan->cohortCache[$cohortId] = $this->first(schema: self::COHORT_SCHEMA, query: self::byId(id: $cohortId, tenantId: $scan->tenantId));
		}

		return $scan->cohortCache[$cohortId];
	}//end loadCohort()

	/**
	 * Load a Room by UUID, cached on the scan.
	 *
	 * @param string       $roomId Room UUID.
	 * @param ConflictScan $scan   The running scan (room cache mutated: a miss is cached as null).
	 *
	 * @return array<string,mixed>|null The room data, or null.
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-an-exam-session-exceeding-room-capacity-is-flagged-as-room-capacity-exceeded
	 */
	public function loadRoom(string $roomId, ConflictScan $scan): ?array {
		if ($roomId === '') {
			return null;
		}

		if (array_key_exists($roomId, $scan->roomCache) === false) {
			$scan->roomCache[$roomId] = $this->first(schema: self::ROOM_SCHEMA, query: self::byId(id: $roomId, tenantId: $scan->tenantId));
		}

		return $scan->roomCache[$roomId];
	}//end loadRoom()

	/**
	 * Run a findAll on one learniq schema and normalise the rows.
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $query  The findAll config; its `filters` get the register and schema.
	 *
	 * @return array<int|string,array<string,mixed>> The rows, keys as findAll returned them.
	 */
	private function query(string $schema, array $query): array {
		$query['filters'] = array_merge($query['filters'], ['register' => self::LEARNIQ_REGISTER, 'schema' => $schema]);
		return array_map(
			fn (mixed $row): array => $this->normalise(row: $row),
			$this->objectService->findAll($query)
		);
	}//end query()

	/**
	 * The first row a findAll returns, or null when it returns none.
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $query  The findAll config.
	 *
	 * @return array<string,mixed>|null
	 */
	private function first(string $schema, array $query): ?array {
		$rows = $this->query(schema: $schema, query: $query);
		if (empty($rows) === true) {
			return null;
		}

		return $rows[0];
	}//end first()

	/**
	 * The findAll config for one object by UUID.
	 *
	 * @param string $id       The object UUID.
	 * @param string $tenantId Tenant scope.
	 *
	 * @return array<string,mixed>
	 */
	private static function byId(string $id, string $tenantId): array {
		return ['ids' => [$id], 'filters' => self::tenantScoped(filters: [], tenantId: $tenantId), 'limit' => 1];
	}//end byId()

	/**
	 * The filters, scoped to the tenant when there is one.
	 *
	 * @param array<string,mixed> $filters  The filters.
	 * @param string              $tenantId Tenant scope ('' for none).
	 *
	 * @return array<string,mixed>
	 */
	private static function tenantScoped(array $filters, string $tenantId): array {
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		return $filters;
	}//end tenantScoped()

	/**
	 * What the assessment cache holds for a lookup: the Assessment's id, or null for none.
	 *
	 * @param array<string,mixed>|null $assessment The first linked Assessment, if any.
	 *
	 * @return string|null
	 */
	private static function assessmentRef(?array $assessment): ?string {
		if ($assessment === null) {
			return null;
		}

		return (string)($assessment['id'] ?? ($assessment['uuid'] ?? 'unknown'));
	}//end assessmentRef()

	/**
	 * The rows that occupy a slot, keyed by id: rows without an id and cancelled rows are left out.
	 *
	 * @param iterable<array<string,mixed>> $rows Session rows.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function liveById(iterable $rows): array {
		$byId = [];
		foreach ($rows as $row) {
			$id = self::rowId(row: $row);
			if ($id !== '' && in_array(($row['lifecycle'] ?? ''), self::EXCLUDED_LIFECYCLES, true) === false) {
				$byId[$id] = $row;
			}
		}

		return $byId;
	}//end liveById()

	/**
	 * A row's id, else its uuid, else ''.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return string
	 */
	private static function rowId(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ''));
	}//end rowId()

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
