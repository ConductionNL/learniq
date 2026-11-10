<?php

/**
 * Learniq Timetable Conflict Detector
 *
 * Detection, not optimisation (design.md "Conflict-detection algorithm").
 * Given a set of Session ids that just changed (created/updated, or
 * upserted by a `timetable-import` DataExchangeJob), scans the affected
 * date window — never a full-register scan — for:
 *   - teacher-double-booking : overlapping Sessions whose assigned teacher
 *     (substituteTeacherId when set, else Cohort.teacherIds) intersect.
 *   - room-double-booking    : overlapping Sessions sharing the same non-null roomId.
 *   - cohort-double-booking  : overlapping Sessions sharing the same cohortId.
 *   - learner-double-booking : overlapping Sessions across different cohorts
 *     whose Cohort.learnerIds intersect.
 *   - room-capacity-exceeded : a single Session with roomId + a linked
 *     Assessment whose cohort's learnerIds count exceeds Room.capacity.
 *   - exam-clash             : any overlap kind above where at least one of
 *     the two Sessions has a linked Assessment.
 *
 * This class is the orchestrator of that scan and the only thing that writes:
 * the OpenRegister reads live in {@see SessionWindowLoader} and the pairwise
 * and capacity rules live in {@see SessionOverlapEvaluator}, so the decision
 * logic is testable without a register and the register access is testable
 * without the rules.
 *
 * Each finding is persisted as a `TimetableConflict` row, idempotent by
 * (sessionIds, kind) against any existing `open` row — a re-scan of an
 * unchanged window never spams duplicates. This class NEVER edits, cancels,
 * or reassigns a Session; it only ever creates `TimetableConflict` objects.
 *
 * ADR-031 legitimate exception: cross-object write bridge — a conflict is a
 * relationship BETWEEN two or more Session rows, not a property
 * materialisable on one row via a declared x-openregister-calculations
 * expression. The same exception class as ConferenceScheduleGenerator.
 *
 * Invoked by SessionConflictListener (OR-event-driven, on Session
 * create/update) and by PlanninqTimetableImport (batch, once planninq
 * took a timetable delivery, requested through TimetableImportController).
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

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Pairwise overlap scan over Session objects scoped to an affected date
 * window, writing idempotent TimetableConflict rows.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */
class TimetableConflictDetector {

	private const LEARNIQ_REGISTER = 'learniq';
	private const TIMETABLE_CONFLICT_SCHEMA = 'timetable-conflict';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param SessionWindowLoader $loader Read-only OR access for the scan window and its lookups.
	 * @param SessionOverlapEvaluator $evaluator Pure overlap and capacity rules.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly SessionWindowLoader $loader,
		private readonly SessionOverlapEvaluator $evaluator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Scan the affected window around the given Session ids for conflicts.
	 *
	 * @param array<int,array<string,mixed>> $sessions The freshly changed/imported Session objects (already-fetched data arrays).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-two-sessions-imported-for-the-same-room-at-overlapping-times-are-flagged-not-auto-moved
	 * @spec openspec/specs/timetabling/spec.md#scenario-re-scanning-an-unchanged-window-does-not-create-duplicate-conflicts
	 * @spec openspec/specs/timetabling/spec.md#scenario-an-exam-session-exceeding-room-capacity-is-flagged-as-room-capacity-exceeded
	 */
	public function scan(array $sessions): void {
		if (empty($sessions) === true) {
			return;
		}

		$tenantId = (string)($sessions[0]['tenant_id'] ?? '');
		$window = $this->loader->loadWindow(sessions: $sessions, tenantId: $tenantId);
		if (count($window) < 1) {
			return;
		}

		$this->scanSessions(window: $window, tenantId: $tenantId);

	}//end scan()

	/**
	 * Scan exactly the given lessons for conflicts, without loading a window.
	 *
	 * For a caller that already holds the lessons from a timetable source,
	 * such as the planninq lessons a timetable-import job just delivered
	 * (sessions-from-planninq). Cancelled lessons are left out, the same rule
	 * the Session window applies. Conflict rows are written under `$tenantId`,
	 * because a planninq lesson carries no learniq tenant of its own.
	 *
	 * @param array<int,array<string,mixed>> $sessions Lessons in learniq's session shape.
	 * @param string                         $tenantId Tenant the conflict rows belong to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-conflict-detection-runs-on-the-adapters-lessons-req-004
	 */
	public function scanWindow(array $sessions, string $tenantId): void {
		$window = [];
		foreach ($sessions as $session) {
			$id = (string)($session['id'] ?? ($session['uuid'] ?? ''));
			if ($id === '' || ($session['lifecycle'] ?? '') === 'cancelled') {
				continue;
			}

			$window[$id] = $session;
		}

		if (count($window) < 2) {
			return;
		}

		$this->scanSessions(window: $window, tenantId: $tenantId);

	}//end scanWindow()

	/**
	 * Pairwise overlap and capacity scan over a loaded window, writing new
	 * TimetableConflict rows idempotently.
	 *
	 * @param array<string,array<string,mixed>> $window   Lessons keyed by id.
	 * @param string                            $tenantId Tenant scope.
	 *
	 * @return void
	 */
	private function scanSessions(array $window, string $tenantId): void {
		$scan = new ConflictScan(tenantId: $tenantId, existingOpen: $this->existingOpenKeys(tenantId: $tenantId));

		$ids = array_keys($window);
		$count = count($ids);

		for ($i = 0; $i < $count; $i++) {
			for ($j = ($i + 1); $j < $count; $j++) {
				$pair = new SessionPair(sessionA: $window[$ids[$i]], sessionB: $window[$ids[$j]]);

				if ($this->evaluator->overlaps(sessionA: $pair->sessionA, sessionB: $pair->sessionB) === false) {
					continue;
				}

				$this->evaluatePair(scan: $scan, pair: $pair);
			}
		}

		foreach ($window as $session) {
			$this->evaluateCapacity(scan: $scan, session: $session);
		}

		foreach ($scan->toCreate as $conflict) {
			$this->objectService->saveObject(
				object: $conflict,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::LEARNIQ_REGISTER, schema: self::TIMETABLE_CONFLICT_SCHEMA)
				)
			);
		}

		if (count($scan->toCreate) > 0) {
			$this->logger->info(
				'[TimetableConflictDetector] {n} conflict(s) created for a window of {w} session(s).',
				['n' => count($scan->toCreate), 'w' => count($window)]
			);
		}

	}//end scanSessions()

	/**
	 * Evaluate the pairwise overlap kinds for one Session pair, queueing any
	 * finding on the scan (idempotent against its open conflicts).
	 *
	 * @param ConflictScan $scan The running scan (caches and queue mutated).
	 * @param SessionPair  $pair The overlapping Sessions.
	 *
	 * @return void
	 */
	private function evaluatePair(ConflictScan $scan, SessionPair $pair): void {
		$ids = [self::sessionId(session: $pair->sessionA), self::sessionId(session: $pair->sessionB)];
		if (in_array('', $ids, true) === true || $ids[0] === $ids[1]) {
			return;
		}

		foreach ($this->pairKinds(scan: $scan, pair: $pair) as $kind => $scopeRef) {
			$this->queueConflict(scan: $scan, finding: new ConflictFinding(sessionIds: $ids, kind: (string)$kind, scopeRef: $scopeRef));
		}

	}//end evaluatePair()

	/**
	 * The overlap kinds of one Session pair, plus exam-clash when there is an
	 * overlap and at least one Session has a linked Assessment.
	 *
	 * @param ConflictScan $scan The running scan (caches mutated).
	 * @param SessionPair  $pair The overlapping Sessions.
	 *
	 * @return array<string,string|null> Kind to scope reference.
	 */
	private function pairKinds(ConflictScan $scan, SessionPair $pair): array {
		$kinds = $this->evaluator->overlapKinds(
			sessionA: $pair->sessionA,
			sessionB: $pair->sessionB,
			cohortA: $this->cohortFor(scan: $scan, session: $pair->sessionA),
			cohortB: $this->cohortFor(scan: $scan, session: $pair->sessionB),
		);

		if (empty($kinds) === false && $this->eitherHasAssessment(scan: $scan, pair: $pair) === true) {
			$kinds['exam-clash'] = null;
		}

		return $kinds;
	}//end pairKinds()

	/**
	 * Whether either Session of the pair has a linked Assessment.
	 *
	 * @param ConflictScan $scan The running scan (assessment cache mutated).
	 * @param SessionPair  $pair The Sessions.
	 *
	 * @return bool
	 */
	private function eitherHasAssessment(ConflictScan $scan, SessionPair $pair): bool {
		return $this->loader->hasLinkedAssessment(sessionId: self::sessionId(session: $pair->sessionA), scan: $scan)
			|| $this->loader->hasLinkedAssessment(sessionId: self::sessionId(session: $pair->sessionB), scan: $scan);
	}//end eitherHasAssessment()

	/**
	 * Evaluate the single-Session room-capacity-exceeded kind.
	 *
	 * @param ConflictScan        $scan    The running scan (caches and queue mutated).
	 * @param array<string,mixed> $session The Session.
	 *
	 * @return void
	 */
	private function evaluateCapacity(ConflictScan $scan, array $session): void {
		if ($this->exceedsRoomCapacity(scan: $scan, session: $session) === false) {
			return;
		}

		$this->queueConflict(
			scan: $scan,
			finding: new ConflictFinding(
				sessionIds: [self::sessionId(session: $session)],
				kind: 'room-capacity-exceeded',
				scopeRef: self::roomId(session: $session)
			)
		);

	}//end evaluateCapacity()

	/**
	 * Whether a Session with a linked Assessment holds more learners than its Room's capacity.
	 *
	 * @param ConflictScan        $scan    The running scan (caches mutated).
	 * @param array<string,mixed> $session The Session.
	 *
	 * @return bool
	 */
	private function exceedsRoomCapacity(ConflictScan $scan, array $session): bool {
		$sessionId = self::sessionId(session: $session);
		$roomId    = self::roomId(session: $session);
		if ($sessionId === '' || $roomId === '' || $this->loader->hasLinkedAssessment(sessionId: $sessionId, scan: $scan) === false) {
			return false;
		}

		$room = $this->loader->loadRoom(roomId: $roomId, scan: $scan);
		if ($room === null) {
			return false;
		}

		return $this->evaluator->exceedsCapacity(cohort: $this->cohortFor(scan: $scan, session: $session), room: $room);
	}//end exceedsRoomCapacity()

	/**
	 * A Session's id, else its uuid, else ''.
	 *
	 * @param array<string,mixed> $session The Session.
	 *
	 * @return string
	 */
	private static function sessionId(array $session): string {
		return (string)($session['id'] ?? ($session['uuid'] ?? ''));
	}//end sessionId()

	/**
	 * A Session's room id, or ''.
	 *
	 * @param array<string,mixed> $session The Session.
	 *
	 * @return string
	 */
	private static function roomId(array $session): string {
		return (string)($session['roomId'] ?? '');
	}//end roomId()

	/**
	 * Resolve a Session's Cohort through the scan's cache.
	 *
	 * @param ConflictScan        $scan    The running scan (cohort cache mutated).
	 * @param array<string,mixed> $session The Session data.
	 *
	 * @return array<string,mixed>|null The cohort data, or null.
	 */
	private function cohortFor(ConflictScan $scan, array $session): ?array {
		return $this->loader->loadCohort(cohortId: (string)($session['cohortId'] ?? ''), scan: $scan);

	}//end cohortFor()

	/**
	 * Queue a TimetableConflict row for creation unless an `open` row already
	 * exists for the same (sessionIds, kind) pair — idempotent by design.
	 *
	 * @param ConflictScan    $scan    The running scan (queue mutated).
	 * @param ConflictFinding $finding The conflict found.
	 *
	 * @return void
	 */
	private function queueConflict(ConflictScan $scan, ConflictFinding $finding): void {
		$key = $this->conflictKey(sessionIds: $finding->sessionIds, kind: $finding->kind);
		if (isset($scan->existingOpen[$key]) === true) {
			return;
		}

		// Also skip a duplicate within the same scan pass.
		foreach ($scan->toCreate as $queued) {
			if ($this->conflictKey(sessionIds: $queued['sessionIds'], kind: $queued['kind']) === $key) {
				return;
			}
		}

		$scan->toCreate[] = [
			'kind' => $finding->kind,
			'sessionIds' => $finding->sessionIds,
			'scopeRef' => $finding->scopeRef,
			'severity' => 'error',
			'detectedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
			'resolutionNote' => null,
			'tenant_id' => $scan->tenantId,
			'lifecycle' => 'open',
		];

	}//end queueConflict()

	/**
	 * Build the idempotency key for a (sessionIds, kind) pair — order-independent.
	 *
	 * @param array<int,string> $sessionIds Session UUIDs.
	 * @param string $kind Conflict kind.
	 *
	 * @return string The composite key.
	 */
	private function conflictKey(array $sessionIds, string $kind): string {
		$sorted = $sessionIds;
		sort($sorted);
		return $kind . '|' . implode(',', $sorted);
	}//end conflictKey()

	/**
	 * Build the (sessionIds, kind) key set of every `open` TimetableConflict
	 * for the tenant, so an unchanged window never re-creates a finding.
	 *
	 * @param string $tenantId Tenant scope.
	 *
	 * @return array<string,true> Map of composite key -> true.
	 */
	private function existingOpenKeys(string $tenantId): array {
		$keys = [];
		foreach ($this->loader->loadOpenConflicts(tenantId: $tenantId) as $data) {
			$sessionIds = $data['sessionIds'] ?? [];
			if (is_array($sessionIds) === false) {
				continue;
			}

			$ids = $this->evaluator->stringList(value: $sessionIds);
			$key = $this->conflictKey(sessionIds: $ids, kind: (string)($data['kind'] ?? ''));

			$keys[$key] = true;
		}

		return $keys;
	}//end existingOpenKeys()
}//end class
