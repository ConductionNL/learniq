<?php

/**
 * Learniq Session Overlap Evaluator
 *
 * The pure decision half of the conflict scan, split out of
 * {@see \OCA\Learniq\Timetabling\TimetableConflictDetector} so the detector
 * owns only orchestration and the TimetableConflict writes.
 *
 * Given two already-fetched Session data arrays (and their already-resolved
 * Cohorts), this class answers two questions and nothing else:
 *   - do their [startsAt, endsAt) intervals overlap?
 *   - which pairwise conflict kinds does that overlap exhibit, and which
 *     shared identity (teacher, room, cohort or learner id) evidences each?
 * It additionally answers the single-Session capacity question: does the
 * Session's cohort have more learners than its Room admits?
 *
 * Every method is a pure function of its arguments: no OpenRegister access, no
 * logging, no state. Detection, not optimisation — nothing here ever proposes
 * a different room or time.
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

/**
 * Pure overlap and capacity rules over Session/Cohort/Room data arrays.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */
class SessionOverlapEvaluator {
	/**
	 * Whether two Sessions' [startsAt, endsAt) intervals overlap.
	 *
	 * @param array<string,mixed> $sessionA Session A.
	 * @param array<string,mixed> $sessionB Session B.
	 *
	 * @return bool True when the intervals overlap.
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-two-sessions-imported-for-the-same-room-at-overlapping-times-are-flagged-not-auto-moved
	 */
	public function overlaps(array $sessionA, array $sessionB): bool {
		$first = $this->interval(session: $sessionA);
		$second = $this->interval(session: $sessionB);
		if ($first === null || $second === null) {
			return false;
		}

		return ($first[0] < $second[1] && $second[0] < $first[1]);
	}//end overlaps()

	/**
	 * A Session's start and end as timestamps, or null when either cannot be read.
	 *
	 * @param array<string,mixed> $session The Session.
	 *
	 * @return array{0:int,1:int}|null
	 */
	private function interval(array $session): ?array {
		$start = strtotime((string)($session['startsAt'] ?? ''));
		$end = strtotime((string)($session['endsAt'] ?? ''));
		if ($start === false || $end === false) {
			return null;
		}

		return [$start, $end];
	}//end interval()

	/**
	 * Determine which pairwise overlap kinds two Sessions exhibit.
	 *
	 * Each kind maps to the reference that evidences it (the shared teacher,
	 * room, cohort or learner id), which is what the resulting TimetableConflict
	 * row carries as its `scopeRef`.
	 *
	 * @param array<string,mixed> $sessionA Session A.
	 * @param array<string,mixed> $sessionB Session B.
	 * @param array<string,mixed>|null $cohortA Session A's Cohort, when resolvable.
	 * @param array<string,mixed>|null $cohortB Session B's Cohort, when resolvable.
	 *
	 * @return array<string,string|null> Map of conflict kind => evidencing reference.
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-two-sessions-imported-for-the-same-room-at-overlapping-times-are-flagged-not-auto-moved
	 */
	public function overlapKinds(array $sessionA, array $sessionB, ?array $cohortA, ?array $cohortB): array {
		$kinds = [
			'teacher-double-booking' => $this->sharedTeacherRef(sessions: [$sessionA, $sessionB], cohorts: [$cohortA, $cohortB]),
			'room-double-booking' => $this->sharedRoomRef(sessionA: $sessionA, sessionB: $sessionB),
			'cohort-double-booking' => $this->sharedCohortRef(sessionA: $sessionA, sessionB: $sessionB),
			'learner-double-booking' => $this->sharedLearnerRef(sessions: [$sessionA, $sessionB], cohorts: [$cohortA, $cohortB]),
		];

		return array_filter($kinds, static fn (mixed $reference): bool => $reference !== null);
	}//end overlapKinds()

	/**
	 * Whether a Session's cohort has more learners than its Room admits.
	 *
	 * A Room with no positive `capacity` declares no limit, so it can never be
	 * exceeded.
	 *
	 * @param array<string,mixed>|null $cohort The Session's Cohort, when resolvable.
	 * @param array<string,mixed>|null $room The Session's Room, when resolvable.
	 *
	 * @return bool True when the cohort's learner count exceeds the room capacity.
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-an-exam-session-exceeding-room-capacity-is-flagged-as-room-capacity-exceeded
	 */
	public function exceedsCapacity(?array $cohort, ?array $room): bool {
		$capacity = (int)($room['capacity'] ?? 0);
		if ($capacity <= 0) {
			return false;
		}

		$candidateCount = count($this->stringList(value: ($cohort['learnerIds'] ?? [])));

		return ($candidateCount > $capacity);
	}//end exceedsCapacity()

	/**
	 * A teacher assigned to both Sessions.
	 *
	 * @param array{0:array<string,mixed>,1:array<string,mixed>}             $sessions The two Sessions.
	 * @param array{0:array<string,mixed>|null,1:array<string,mixed>|null} $cohorts  Their Cohorts, when resolvable.
	 *
	 * @return int|string|null A teacher id (an all-digit id from a cohort's list is an int), or null.
	 */
	private function sharedTeacherRef(array $sessions, array $cohorts): int|string|null {
		$shared = array_intersect(
			$this->assignedTeacherIds(session: $sessions[0], cohort: $cohorts[0]),
			$this->assignedTeacherIds(session: $sessions[1], cohort: $cohorts[1])
		);

		return (array_values($shared)[0] ?? null);
	}//end sharedTeacherRef()

	/**
	 * The cohort two Sessions share, if any.
	 *
	 * @param array<string,mixed> $sessionA Session A.
	 * @param array<string,mixed> $sessionB Session B.
	 *
	 * @return string|null The cohort id, or null.
	 */
	private function sharedCohortRef(array $sessionA, array $sessionB): ?string {
		$cohortId = (string)($sessionA['cohortId'] ?? '');
		if ($cohortId === '' || $cohortId !== (string)($sessionB['cohortId'] ?? '')) {
			return null;
		}

		return $cohortId;
	}//end sharedCohortRef()

	/**
	 * Find a learner enrolled in both Sessions' cohorts.
	 *
	 * Only meaningful across DIFFERENT cohorts: two Sessions on the same cohort
	 * are already a cohort-double-booking, and reporting every one of their
	 * learners again would be noise rather than a second finding.
	 *
	 * @param array{0:array<string,mixed>,1:array<string,mixed>}             $sessions The two Sessions.
	 * @param array{0:array<string,mixed>|null,1:array<string,mixed>|null} $cohorts  Their Cohorts, when resolvable.
	 *
	 * @return string|null A learner id present in both cohorts, or null.
	 */
	private function sharedLearnerRef(array $sessions, array $cohorts): ?string {
		$cohortIdA = (string)($sessions[0]['cohortId'] ?? '');
		$cohortIdB = (string)($sessions[1]['cohortId'] ?? '');
		if ($cohortIdA === '' || $cohortIdB === '' || $cohortIdA === $cohortIdB) {
			return null;
		}

		$shared = array_intersect(
			$this->stringList(value: ($cohorts[0]['learnerIds'] ?? [])),
			$this->stringList(value: ($cohorts[1]['learnerIds'] ?? []))
		);
		if ($shared === []) {
			return null;
		}

		return (string)array_values($shared)[0];
	}//end sharedLearnerRef()

	/**
	 * The room two Sessions share, if any: the same `roomId`, or, when
	 * neither has a Room row (a lesson from the planninq timetable source),
	 * the same school `roomReference` code (sessions-from-planninq).
	 *
	 * @param array<string,mixed> $sessionA Session A.
	 * @param array<string,mixed> $sessionB Session B.
	 *
	 * @return string|null The shared room id or code, or null.
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-conflict-detection-runs-on-the-adapters-lessons-req-004
	 */
	private function sharedRoomRef(array $sessionA, array $sessionB): ?string {
		$roomA = (string)($sessionA['roomId'] ?? '');
		$roomB = (string)($sessionB['roomId'] ?? '');
		if ($roomA === '' && $roomB === '') {
			return $this->sharedRoomCode(sessionA: $sessionA, sessionB: $sessionB);
		}

		if ($roomA === $roomB) {
			return $roomA;
		}

		return null;
	}//end sharedRoomRef()

	/**
	 * The school room code two Sessions share, if any.
	 *
	 * @param array<string,mixed> $sessionA Session A.
	 * @param array<string,mixed> $sessionB Session B.
	 *
	 * @return string|null The code, or null.
	 */
	private function sharedRoomCode(array $sessionA, array $sessionB): ?string {
		$code = (string)($sessionA['roomReference'] ?? '');
		if ($code !== '' && $code === (string)($sessionB['roomReference'] ?? '')) {
			return $code;
		}

		return null;
	}//end sharedRoomCode()

	/**
	 * Resolve the "assigned teacher" identity set for a Session: the
	 * substitute teacher once assigned, else the lesson's own teacher account
	 * (a planninq lesson carries one, sessions-from-planninq), else the
	 * Cohort's teacherIds.
	 *
	 * @param array<string,mixed> $session Session data.
	 * @param array<string,mixed>|null $cohort The Session's Cohort data, or null.
	 *
	 * @return array<int,int|string> The assigned teacher Nextcloud user ids.
	 */
	private function assignedTeacherIds(array $session, ?array $cohort): array {
		$substituteId = (string)($session['substituteTeacherId'] ?? '');
		if ($substituteId !== '') {
			return [$substituteId];
		}

		$ownTeacher = (string)($session['teacherUserId'] ?? '');
		if ($ownTeacher !== '') {
			return [$ownTeacher];
		}

		if ($cohort === null) {
			return [];
		}

		return $this->stringList(value: ($cohort['teacherIds'] ?? []));
	}//end assignedTeacherIds()

	/**
	 * Coerce a schema array-of-strings value into a de-duplicated string list.
	 *
	 * Shared with TimetableConflictDetector, which normalises a stored
	 * TimetableConflict's `sessionIds` through it before building the row's
	 * idempotency key — the two must agree on what "the same id list" means.
	 *
	 * @param mixed $value The raw property value.
	 *
	 * @return array<int,int|string> The list; an all-digit id comes back from array_keys() as an int.
	 */
	public function stringList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $item) {
			if (is_string($item) === true && $item !== '') {
				$out[$item] = true;
			}
		}

		return array_keys($out);
	}//end stringList()
}//end class
