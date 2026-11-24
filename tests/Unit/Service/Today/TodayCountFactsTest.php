<?php

/**
 * The counts behind the role tiles on Today, each with rows and empty.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Today;

use DateTimeImmutable;
use OCA\Learniq\Service\Today\TodayCountFacts;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Today\TodayCountFacts
 */
class TodayCountFactsTest extends TestCase {

	/**
	 * Lessons that started without a register count; later and cancelled ones do not.
	 *
	 * @return void
	 */
	public function testRegistersNotFilledCountsStartedLessonsWithoutMarks(): void {
		$now = new DateTimeImmutable('2026-10-05T10:00:00+02:00');
		$sessions = [
			['id' => 'a', 'startsAt' => '2026-10-05T08:30:00+02:00', 'markCount' => 0],
			['id' => 'b', 'startsAt' => '2026-10-05T09:20:00+02:00', 'markCount' => 24],
			['id' => 'c', 'startsAt' => '2026-10-05T11:00:00+02:00', 'markCount' => 0],
			['id' => 'd', 'startsAt' => '2026-10-05T08:30:00+02:00', 'markCount' => 0, 'lifecycle' => 'cancelled'],
		];

		self::assertSame(expected: ['count' => 1, 'total' => 3], actual: (new TodayCountFacts())->registersNotFilled(sessions: $sessions, now: $now));
		self::assertSame(expected: ['count' => 0, 'total' => 0], actual: (new TodayCountFacts())->registersNotFilled(sessions: [], now: $now));
	}//end testRegistersNotFilledCountsStartedLessonsWithoutMarks()

	/**
	 * Parent reports add up over the groups.
	 *
	 * @return void
	 */
	public function testParentReportsWaitingAddsUpOverGroups(): void {
		$facts = new TodayCountFacts();

		self::assertSame(expected: ['count' => 3, 'groups' => 2], actual: $facts->parentReportsWaiting(groups: [['reports' => 2], ['reports' => 0], ['reports' => 1]]));
		self::assertSame(expected: ['count' => 0, 'groups' => 0], actual: $facts->parentReportsWaiting(groups: []));
	}//end testParentReportsWaitingAddsUpOverGroups()

	/**
	 * Only hand-ins without a grade, on assignments of the caller's groups, count.
	 *
	 * @return void
	 */
	public function testSubmissionsToMarkCountsUngradedHandInsOfOwnGroups(): void {
		$assignments = [
			['id' => 'essay', 'cohortId' => 'h5a'],
			['id' => 'lab', 'cohortId' => 'h5a'],
			['id' => 'other', 'cohortId' => 'h4b'],
		];
		$submissions = [
			['assignmentId' => 'essay', 'lifecycle' => 'submitted'],
			['assignmentId' => 'essay', 'lifecycle' => 'late'],
			['assignmentId' => 'essay', 'lifecycle' => 'submitted', 'gradeEntryId' => 'g-1'],
			['assignmentId' => 'essay', 'lifecycle' => 'draft'],
			['assignmentId' => 'lab', 'lifecycle' => 'returned'],
			['assignmentId' => 'other', 'lifecycle' => 'submitted'],
		];

		$facts = new TodayCountFacts();

		self::assertSame(
			expected: ['count' => 2, 'assignments' => 1],
			actual: $facts->submissionsToMark(assignments: $assignments, submissions: $submissions, cohortIds: ['h5a'])
		);
		self::assertSame(
			expected: ['count' => 0, 'assignments' => 0],
			actual: $facts->submissionsToMark(assignments: $assignments, submissions: $submissions, cohortIds: [])
		);
	}//end testSubmissionsToMarkCountsUngradedHandInsOfOwnGroups()

	/**
	 * Chosen times and those to confirm are the caller's own.
	 *
	 * @return void
	 */
	public function testConferenceTimesCountsChosenAndToConfirm(): void {
		$slots = [
			['teacherId' => 'daan', 'lifecycle' => 'booked'],
			['teacherId' => 'daan', 'lifecycle' => 'booked'],
			['teacherId' => 'daan', 'lifecycle' => 'acknowledged'],
			['teacherId' => 'daan', 'lifecycle' => 'open'],
			['teacherId' => 'sanne', 'lifecycle' => 'booked'],
		];

		$facts = new TodayCountFacts();

		self::assertSame(expected: ['count' => 2, 'chosen' => 3, 'total' => 4], actual: $facts->conferenceTimes(slots: $slots, uid: 'daan'));
		self::assertSame(expected: ['count' => 0, 'chosen' => 0, 'total' => 0], actual: $facts->conferenceTimes(slots: [], uid: 'daan'));
	}//end testConferenceTimesCountsChosenAndToConfirm()

	/**
	 * The directeur at 9.05: seven of eight groups filled their register; a group without lessons is left out.
	 *
	 * @return void
	 */
	public function testRegistersFilledPerGroupReadsSevenOfEight(): void {
		$groups = [];
		for ($i = 1; $i <= 7; $i++) {
			$groups[] = ['sessions' => [['id' => 's' . $i]], 'records' => ['s' . $i => 20]];
		}

		$groups[] = ['sessions' => [['id' => 'g3']], 'records' => ['g3' => 0]];
		$groups[] = ['sessions' => [], 'records' => []];

		$facts = new TodayCountFacts();

		self::assertSame(expected: ['count' => 7, 'total' => 8], actual: $facts->registersFilledPerGroup(groups: $groups));
		self::assertSame(expected: ['count' => 0, 'total' => 0], actual: $facts->registersFilledPerGroup(groups: []));
	}//end testRegistersFilledPerGroupReadsSevenOfEight()

	/**
	 * Pupils with three or more insufficient final grades count per year; two is not enough.
	 *
	 * @return void
	 */
	public function testInsufficientPerYearCountsPupilsWithThreeOrMore(): void {
		$grades = [];
		foreach (['yusuf' => 3, 'sem' => 4, 'tess' => 2, 'noor' => 3] as $learner => $fails) {
			for ($i = 0; $i < $fails; $i++) {
				$grades[] = ['learnerId' => $learner, 'passed' => false];
			}
		}

		$grades[] = ['learnerId' => 'tess', 'passed' => true];
		$grades[] = ['learnerId' => 'tess', 'passed' => null];
		$cohorts = [
			['programmeYear' => 4, 'learnerIds' => ['yusuf', 'tess']],
			['programmeYear' => 5, 'learnerIds' => ['sem']],
		];

		$facts = new TodayCountFacts();

		self::assertSame(expected: ['count' => 3, 'perYear' => [0 => 1, 4 => 1, 5 => 1]], actual: $facts->insufficientPerYear(finalGrades: $grades, cohorts: $cohorts));
		self::assertSame(expected: ['count' => 0, 'perYear' => []], actual: $facts->insufficientPerYear(finalGrades: [], cohorts: $cohorts));
	}//end testInsufficientPerYearCountsPupilsWithThreeOrMore()

	/**
	 * Cancelled lessons count once, even when two groups share the lesson.
	 *
	 * @return void
	 */
	public function testLessonsCancelledTodayCountsEachLessonOnce(): void {
		$groups = [
			['sessions' => [['id' => 'a', 'lifecycle' => 'cancelled'], ['id' => 'b', 'lifecycle' => 'scheduled']]],
			['sessions' => [['id' => 'a', 'lifecycle' => 'cancelled'], ['id' => 'c', 'lifecycle' => 'cancelled']]],
		];

		$facts = new TodayCountFacts();

		self::assertSame(expected: ['count' => 2], actual: $facts->lessonsCancelledToday(groups: $groups));
		self::assertSame(expected: ['count' => 0], actual: $facts->lessonsCancelledToday(groups: []));
	}//end testLessonsCancelledTodayCountsEachLessonOnce()
}//end class
