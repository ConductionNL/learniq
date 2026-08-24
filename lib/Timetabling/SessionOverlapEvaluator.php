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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling;

/**
 * Pure overlap and capacity rules over Session/Cohort/Room data arrays.
 *
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
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
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-two-sessions-imported-for-the-same-room-at-overlapping-times-are-flagged-not-auto-moved
	 */
	public function overlaps(array $sessionA, array $sessionB): bool {
		$startA = strtotime((string)($sessionA['startsAt'] ?? ''));
		$endA = strtotime((string)($sessionA['endsAt'] ?? ''));
		$startB = strtotime((string)($sessionB['startsAt'] ?? ''));
		$endB = strtotime((string)($sessionB['endsAt'] ?? ''));

		if ($startA === false || $endA === false || $startB === false || $endB === false) {
			return false;
		}

		return ($startA < $endB && $startB < $endA);
	}//end overlaps()

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
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-two-sessions-imported-for-the-same-room-at-overlapping-times-are-flagged-not-auto-moved
	 */
	public function overlapKinds(array $sessionA, array $sessionB, ?array $cohortA, ?array $cohortB): array {
		$kinds = [];

		// Teacher-double-booking.
		$sharedTeachers = array_values(
			array_intersect(
				$this->assignedTeacherIds(session: $sessionA, cohort: $cohortA),
				$this->assignedTeacherIds(session: $sessionB, cohort: $cohortB)
			)
		);
		if (count($sharedTeachers) > 0) {
			$kinds['teacher-double-booking'] = $sharedTeachers[0];
		}

		// Room-double-booking.
		$roomA = (string)($sessionA['roomId'] ?? '');
		if ($roomA !== '' && $roomA === (string)($sessionB['roomId'] ?? '')) {
			$kinds['room-double-booking'] = $roomA;
		}

		// Cohort-double-booking.
		$cohortIdA = (string)($sessionA['cohortId'] ?? '');
		$cohortIdB = (string)($sessionB['cohortId'] ?? '');
		if ($cohortIdA !== '' && $cohortIdA === $cohortIdB) {
			$kinds['cohort-double-booking'] = $cohortIdA;
		}

		$sharedLearner = $this->sharedLearnerRef(
			cohortIdA: $cohortIdA,
			cohortIdB: $cohortIdB,
			cohortA: $cohortA,
			cohortB: $cohortB
		);
		if ($sharedLearner !== null) {
			$kinds['learner-double-booking'] = $sharedLearner;
		}

		return $kinds;
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
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-an-exam-session-exceeding-room-capacity-is-flagged-as-room-capacity-exceeded
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
	 * Find a learner enrolled in both Sessions' cohorts.
	 *
	 * Only meaningful across DIFFERENT cohorts: two Sessions on the same cohort
	 * are already a cohort-double-booking, and reporting every one of their
	 * learners again would be noise rather than a second finding.
	 *
	 * @param string $cohortIdA Session A's cohort id.
	 * @param string $cohortIdB Session B's cohort id.
	 * @param array<string,mixed>|null $cohortA Session A's Cohort, when resolvable.
	 * @param array<string,mixed>|null $cohortB Session B's Cohort, when resolvable.
	 *
	 * @return string|null A learner id present in both cohorts, or null.
	 */
	private function sharedLearnerRef(string $cohortIdA, string $cohortIdB, ?array $cohortA, ?array $cohortB): ?string {
		if ($cohortIdA === '' || $cohortIdB === '' || $cohortIdA === $cohortIdB) {
			return null;
		}

		$sharedLearners = array_values(
			array_intersect(
				$this->stringList(value: ($cohortA['learnerIds'] ?? [])),
				$this->stringList(value: ($cohortB['learnerIds'] ?? []))
			)
		);

		if (count($sharedLearners) === 0) {
			return null;
		}

		return (string)$sharedLearners[0];
	}//end sharedLearnerRef()

	/**
	 * Resolve the "assigned teacher" identity set for a Session: the
	 * substitute teacher once assigned, else the Cohort's teacherIds.
	 *
	 * @param array<string,mixed> $session Session data.
	 * @param array<string,mixed>|null $cohort The Session's Cohort data, or null.
	 *
	 * @return array<int,string> The assigned teacher Nextcloud user ids.
	 */
	private function assignedTeacherIds(array $session, ?array $cohort): array {
		$substituteId = (string)($session['substituteTeacherId'] ?? '');
		if ($substituteId !== '') {
			return [$substituteId];
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
	 * @return array<int,string> The string list.
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
