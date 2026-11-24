<?php

/**
 * Learniq Today Count Facts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The counts behind the role tiles on Today: registers not filled for the
 * caller's lessons so far, parent reports waiting in her registers,
 * submissions waiting for marking per assignment, a conference round's
 * chosen and to-confirm times, registers filled per group, pupils per year
 * with three or more insufficient final grades, and lessons cancelled
 * today. Every method takes rows already read, so each count and its empty
 * case is a unit test.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Today
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Today;

use DateTimeImmutable;

/**
 * The counts of the role tiles on Today.
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
 */
final class TodayCountFacts {
	use RowValues;

	/**
	 * The number of insufficient final grades from which a pupil counts.
	 *
	 * @var int
	 */
	public const INSUFFICIENT_FROM = 3;

	/**
	 * Submission states that wait for a mark.
	 *
	 * @var list<string>
	 */
	private const HANDED_IN = ['submitted', 'late'];

	/**
	 * Conference slot states in which a guardian chose the time.
	 *
	 * @var list<string>
	 */
	private const CHOSEN_SLOT = ['booked', 'acknowledged', 'confirmed', 'completed'];

	/**
	 * The caller's lessons of today that started and have no register saved.
	 *
	 * @param list<array<string, mixed>> $sessions Today's lessons of the caller, each with `markCount` (saved marks).
	 * @param DateTimeImmutable          $now      Now.
	 *
	 * @return array<string, int> count, total (her lessons today that are not cancelled).
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T2
	 */
	public function registersNotFilled(array $sessions, DateTimeImmutable $now): array {
		$held = array_values(array_filter($sessions, static fn (array $session): bool => ($session['lifecycle'] ?? '') !== 'cancelled'));
		$open = array_filter(
			$held,
			static function (array $session) use ($now): bool {
				$starts = self::moment(value: ($session['startsAt'] ?? null));
				return $starts !== null && $starts <= $now && (int)($session['markCount'] ?? 0) === 0;
			}
		);

		return ['count' => count($open), 'total' => count($held)];
	}//end registersNotFilled()

	/**
	 * Absence reports by parents that cover today, over the caller's groups.
	 *
	 * @param list<array<string, mixed>> $groups The caller's groups with today's lessons (`reports` per group).
	 *
	 * @return array<string, int> count, groups (groups with at least one report).
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T2
	 */
	public function parentReportsWaiting(array $groups): array {
		$count = 0;
		$withReports = 0;
		foreach ($groups as $group) {
			$reports = (int)($group['reports'] ?? 0);
			$count += $reports;
			$withReports += (int)($reports > 0);
		}

		return ['count' => $count, 'groups' => $withReports];
	}//end parentReportsWaiting()

	/**
	 * Hand-ins without a grade, over the published assignments of the caller's groups.
	 *
	 * @param list<array<string, mixed>> $assignments Published assignments.
	 * @param list<array<string, mixed>> $submissions Submissions.
	 * @param list<string>               $cohortIds   The groups the caller teaches.
	 *
	 * @return array<string, int> count (hand-ins to mark), assignments (assignments with at least one).
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T2
	 */
	public function submissionsToMark(array $assignments, array $submissions, array $cohortIds): array {
		$mine = [];
		foreach ($assignments as $assignment) {
			if (in_array((string)($assignment['cohortId'] ?? ''), $cohortIds, true) === true) {
				$mine[self::idOf(row: $assignment)] = 0;
			}
		}

		unset($mine['']);
		foreach ($submissions as $submission) {
			$assignmentId = (string)($submission['assignmentId'] ?? '');
			if (isset($mine[$assignmentId]) === false
				|| in_array(($submission['lifecycle'] ?? ''), self::HANDED_IN, true) === false
				|| (string)($submission['gradeEntryId'] ?? '') !== ''
			) {
				continue;
			}

			$mine[$assignmentId]++;
		}

		return ['count' => array_sum($mine), 'assignments' => count(array_filter($mine))];
	}//end submissionsToMark()

	/**
	 * The caller's conference times: chosen by a guardian, and of those waiting for her confirmation.
	 *
	 * @param list<array<string, mixed>> $slots Conference slots.
	 * @param string                     $uid   The caller.
	 *
	 * @return array<string, int> count (times to confirm), chosen, total (her times).
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T2
	 */
	public function conferenceTimes(array $slots, string $uid): array {
		$mine = array_values(array_filter($slots, static fn (array $slot): bool => ($slot['teacherId'] ?? null) === $uid));
		$chosen = array_filter($mine, static fn (array $slot): bool => in_array(($slot['lifecycle'] ?? ''), self::CHOSEN_SLOT, true));
		$toConfirm = array_filter($mine, static fn (array $slot): bool => ($slot['lifecycle'] ?? '') === 'booked');

		return ['count' => count($toConfirm), 'chosen' => count($chosen), 'total' => count($mine)];
	}//end conferenceTimes()

	/**
	 * Groups whose register of today is filled, against every group with a lesson today.
	 *
	 * @param list<array<string, mixed>> $groups Every group with today's lessons (`sessions`, `records` per lesson id).
	 *
	 * @return array<string, int> count (groups filled), total (groups with a lesson today).
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#scenario-the-directeur-at-905
	 */
	public function registersFilledPerGroup(array $groups): array {
		$withLessons = array_values(array_filter($groups, static fn (array $group): bool => (array)($group['sessions'] ?? []) !== []));
		$filled = array_filter(
			$withLessons,
			static fn (array $group): bool => array_sum(array_map('intval', (array)($group['records'] ?? []))) > 0
		);

		return ['count' => count($filled), 'total' => count($withLessons)];
	}//end registersFilledPerGroup()

	/**
	 * Pupils with three or more insufficient final grades, per year of their programme.
	 *
	 * A pupil's year is the `programmeYear` of a group she is in; a pupil in
	 * no group with a year counts under year 0.
	 *
	 * @param list<array<string, mixed>> $finalGrades Final grades.
	 * @param list<array<string, mixed>> $cohorts     The groups the caller may open.
	 *
	 * @return array{count: int, perYear: array<int, int>} count (pupils over all years), perYear.
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T2
	 */
	public function insufficientPerYear(array $finalGrades, array $cohorts): array {
		$fails = [];
		foreach ($finalGrades as $grade) {
			$learnerId = (string)($grade['learnerId'] ?? '');
			if ($learnerId !== '' && ($grade['passed'] ?? null) === false) {
				$fails[$learnerId] = ($fails[$learnerId] ?? 0) + 1;
			}
		}

		$yearOf = [];
		foreach ($cohorts as $cohort) {
			$year = $cohort['programmeYear'] ?? null;
			if (is_int($year) === false) {
				continue;
			}

			foreach ((array)($cohort['learnerIds'] ?? []) as $learnerId) {
				$yearOf[(string)$learnerId] ??= $year;
			}
		}

		$perYear = [];
		foreach ($fails as $learnerId => $count) {
			if ($count < self::INSUFFICIENT_FROM) {
				continue;
			}

			$year = ($yearOf[(string)$learnerId] ?? 0);
			$perYear[$year] = ($perYear[$year] ?? 0) + 1;
		}

		ksort($perYear);

		return ['count' => array_sum($perYear), 'perYear' => $perYear];
	}//end insufficientPerYear()

	/**
	 * Lessons of today that were cancelled, over the given groups.
	 *
	 * @param list<array<string, mixed>> $groups Groups with today's lessons.
	 *
	 * @return array<string, int> count.
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T2
	 */
	public function lessonsCancelledToday(array $groups): array {
		$seen = [];
		foreach ($groups as $group) {
			foreach ((array)($group['sessions'] ?? []) as $session) {
				if (($session['lifecycle'] ?? '') === 'cancelled') {
					$seen[self::idOf(row: (array)$session)] = true;
				}
			}
		}

		return ['count' => count($seen)];
	}//end lessonsCancelledToday()
}//end class
