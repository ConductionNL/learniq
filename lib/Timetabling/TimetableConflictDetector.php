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
 * create/update) and by TimetableImportHandler (batch, once a
 * timetable-import DataExchangeJob succeeds).
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

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Pairwise overlap scan over Session objects scoped to an affected date
 * window, writing idempotent TimetableConflict rows.
 *
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
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
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-two-sessions-imported-for-the-same-room-at-overlapping-times-are-flagged-not-auto-moved
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-re-scanning-an-unchanged-window-does-not-create-duplicate-conflicts
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-an-exam-session-exceeding-room-capacity-is-flagged-as-room-capacity-exceeded
	 */
	public function scan(array $sessions): void {
		if (empty($sessions) === true) {
			return;
		}

		$tenantId = (string)($sessions[0]['tenant_id'] ?? '');
		$buckets = $this->loader->dayBuckets(sessions: $sessions);

		$window = $this->loader->loadWindow(sessions: $sessions, buckets: $buckets, tenantId: $tenantId);
		if (count($window) < 1) {
			return;
		}

		$cohortCache = [];
		$roomCache = [];
		$assessmentCache = [];

		$existingOpen = $this->existingOpenKeys(tenantId: $tenantId);
		$toCreate = [];

		$ids = array_keys($window);
		$count = count($ids);

		for ($i = 0; $i < $count; $i++) {
			for ($j = ($i + 1); $j < $count; $j++) {
				$sessionA = $window[$ids[$i]];
				$sessionB = $window[$ids[$j]];

				if ($this->evaluator->overlaps(sessionA: $sessionA, sessionB: $sessionB) === false) {
					continue;
				}

				$this->evaluatePair(
					sessionA: $sessionA,
					sessionB: $sessionB,
					tenantId: $tenantId,
					cohortCache: $cohortCache,
					assessmentCache: $assessmentCache,
					existingOpen: $existingOpen,
					toCreate: $toCreate
				);
			}
		}//end for

		foreach ($window as $session) {
			$this->evaluateCapacity(
				session: $session,
				tenantId: $tenantId,
				cohortCache: $cohortCache,
				roomCache: $roomCache,
				assessmentCache: $assessmentCache,
				existingOpen: $existingOpen,
				toCreate: $toCreate
			);
		}

		foreach ($toCreate as $conflict) {
			$this->objectService->saveObject(
				register: self::LEARNIQ_REGISTER,
				schema: self::TIMETABLE_CONFLICT_SCHEMA,
				object: $conflict
			);
		}

		if (count($toCreate) > 0) {
			$this->logger->info(
				'[TimetableConflictDetector] {n} conflict(s) created for a window of {w} session(s).',
				['n' => count($toCreate), 'w' => count($window)]
			);
		}

	}//end scan()

	/**
	 * Evaluate the pairwise overlap kinds for one Session pair, appending any
	 * finding to `$toCreate` (idempotent against `$existingOpen`).
	 *
	 * @param array<string,mixed> $sessionA Session A.
	 * @param array<string,mixed> $sessionB Session B.
	 * @param string $tenantId Tenant scope.
	 * @param array<string,array<string,mixed>> $cohortCache Cohort cache, keyed by cohortId (mutated: entries added).
	 * @param array<string,string|null> $assessmentCache Session -> linked Assessment id cache (mutated: entries added).
	 * @param array<string,true> $existingOpen Existing open (sessionIds,kind) keys.
	 * @param array<int,array<string,mixed>> $toCreate Accumulator of TimetableConflict rows to persist (mutated: rows appended).
	 *
	 * @return void
	 */
	private function evaluatePair(
		array $sessionA,
		array $sessionB,
		string $tenantId,
		array &$cohortCache,
		array &$assessmentCache,
		array $existingOpen,
		array &$toCreate,
	): void {
		$idA = (string)($sessionA['id'] ?? ($sessionA['uuid'] ?? ''));
		$idB = (string)($sessionB['id'] ?? ($sessionB['uuid'] ?? ''));
		if ($idA === '' || $idB === '' || $idA === $idB) {
			return;
		}

		$kinds = $this->evaluator->overlapKinds(
			sessionA: $sessionA,
			sessionB: $sessionB,
			cohortA: $this->cohortFor(session: $sessionA, tenantId: $tenantId, cache: $cohortCache),
			cohortB: $this->cohortFor(session: $sessionB, tenantId: $tenantId, cache: $cohortCache),
		);

		if (empty($kinds) === true) {
			return;
		}

		// Exam-clash: any overlap kind above, when at least one Session has a linked Assessment.
		$hasAssessment = $this->loader->hasLinkedAssessment(sessionId: $idA, tenantId: $tenantId, cache: $assessmentCache)
			|| $this->loader->hasLinkedAssessment(sessionId: $idB, tenantId: $tenantId, cache: $assessmentCache);
		if ($hasAssessment === true) {
			$kinds['exam-clash'] = null;
		}

		foreach ($kinds as $kind => $scopeRef) {
			$this->queueConflict(
				sessionIds: [$idA, $idB],
				kind: (string)$kind,
				scopeRef: $scopeRef,
				tenantId: $tenantId,
				existingOpen: $existingOpen,
				toCreate: $toCreate
			);
		}

	}//end evaluatePair()

	/**
	 * Evaluate the single-Session room-capacity-exceeded kind.
	 *
	 * @param array<string,mixed> $session The Session.
	 * @param string $tenantId Tenant scope.
	 * @param array<string,array<string,mixed>> $cohortCache Cohort cache (mutated: entries added).
	 * @param array<string,array<string,mixed>> $roomCache Room cache (mutated: entries added).
	 * @param array<string,string|null> $assessmentCache Assessment-link cache (mutated: entries added).
	 * @param array<string,true> $existingOpen Existing open (sessionIds,kind) keys.
	 * @param array<int,array<string,mixed>> $toCreate Accumulator of rows to persist (mutated: rows appended).
	 *
	 * @return void
	 */
	private function evaluateCapacity(
		array $session,
		string $tenantId,
		array &$cohortCache,
		array &$roomCache,
		array &$assessmentCache,
		array $existingOpen,
		array &$toCreate,
	): void {
		$sessionId = (string)($session['id'] ?? ($session['uuid'] ?? ''));
		$roomId = (string)($session['roomId'] ?? '');
		if ($sessionId === '' || $roomId === '') {
			return;
		}

		$linked = $this->loader->hasLinkedAssessment(sessionId: $sessionId, tenantId: $tenantId, cache: $assessmentCache);
		if ($linked === false) {
			return;
		}

		$room = $this->loader->loadRoom(roomId: $roomId, tenantId: $tenantId, cache: $roomCache);
		if ($room === null) {
			return;
		}

		$cohort = $this->cohortFor(session: $session, tenantId: $tenantId, cache: $cohortCache);
		if ($this->evaluator->exceedsCapacity(cohort: $cohort, room: $room) === false) {
			return;
		}

		$this->queueConflict(
			sessionIds: [$sessionId],
			kind: 'room-capacity-exceeded',
			scopeRef: $roomId,
			tenantId: $tenantId,
			existingOpen: $existingOpen,
			toCreate: $toCreate
		);

	}//end evaluateCapacity()

	/**
	 * Resolve a Session's Cohort through the per-scan cache.
	 *
	 * @param array<string,mixed> $session The Session data.
	 * @param string $tenantId Tenant scope.
	 * @param array<string,array<string,mixed>> $cache Cohort cache (mutated: entries added).
	 *
	 * @return array<string,mixed>|null The cohort data, or null.
	 */
	private function cohortFor(array $session, string $tenantId, array &$cache): ?array {
		return $this->loader->loadCohort(
			cohortId: (string)($session['cohortId'] ?? ''),
			tenantId: $tenantId,
			cache: $cache
		);

	}//end cohortFor()

	/**
	 * Queue a TimetableConflict row for creation unless an `open` row already
	 * exists for the same (sessionIds, kind) pair — idempotent by design.
	 *
	 * @param array<int,string> $sessionIds Session UUIDs involved.
	 * @param string $kind Conflict kind.
	 * @param string|null $scopeRef Shared identity in conflict, or null.
	 * @param string $tenantId Tenant scope.
	 * @param array<string,true> $existingOpen Existing open (sessionIds,kind) keys.
	 * @param array<int,array<string,mixed>> $toCreate Accumulator of rows to persist (mutated: rows appended).
	 *
	 * @return void
	 */
	private function queueConflict(
		array $sessionIds,
		string $kind,
		?string $scopeRef,
		string $tenantId,
		array $existingOpen,
		array &$toCreate,
	): void {
		$key = $this->conflictKey(sessionIds: $sessionIds, kind: $kind);
		if (isset($existingOpen[$key]) === true) {
			return;
		}

		// Also skip a duplicate within the same scan pass.
		foreach ($toCreate as $queued) {
			if ($this->conflictKey(sessionIds: $queued['sessionIds'], kind: $queued['kind']) === $key) {
				return;
			}
		}

		$toCreate[] = [
			'kind' => $kind,
			'sessionIds' => $sessionIds,
			'scopeRef' => $scopeRef,
			'severity' => 'error',
			'detectedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
			'resolutionNote' => null,
			'tenant_id' => $tenantId,
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
