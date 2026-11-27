<?php

/**
 * What each First today rule counts, and its empty case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Today;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\Today\AttendanceFacts;
use OCA\Learniq\Service\Today\FollowUpFacts;
use OCA\Learniq\Service\Today\RegisterFacts;
use OCA\Learniq\Service\Today\TodayContext;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Today\RegisterFacts
 * @covers \OCA\Learniq\Service\Today\AttendanceFacts
 * @covers \OCA\Learniq\Service\Today\FollowUpFacts
 * @uses   \OCA\Learniq\Service\Today\TodayContext
 */
class FirstTodayFactsTest extends TestCase {

	/**
	 * Monday 5 October 2026 at a given time, Amsterdam.
	 *
	 * @param string $time `H:i`.
	 *
	 * @return DateTimeImmutable
	 */
	private static function monday(string $time): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-05T' . $time . ':00', new DateTimeZone('Europe/Amsterdam'));
	}//end monday()

	/**
	 * A user and Monday 5 October 2026 at a time.
	 *
	 * @param string $uid  The user.
	 * @param string $time `H:i`.
	 *
	 * @return TodayContext
	 */
	private function context(string $uid, string $time): TodayContext {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);

		return new TodayContext(user: $user, now: self::monday(time: $time));
	}//end context()

	/**
	 * Groep 7 with its Monday: two lessons, marks per lesson, two parent reports.
	 *
	 * @param int $marks The marks saved on the first lesson.
	 *
	 * @return array<string, mixed>
	 */
	private static function groep7(int $marks): array {
		return [
			'cohort' => ['id' => 'groep-7', 'name' => 'Groep 7'],
			'sessions' => [
				['id' => 's-1', 'startsAt' => '2026-10-05T08:30:00+02:00', 'endsAt' => '2026-10-05T12:00:00+02:00'],
				['id' => 's-2', 'startsAt' => '2026-10-05T13:00:00+02:00', 'endsAt' => '2026-10-05T14:30:00+02:00'],
			],
			'records' => ['s-1' => $marks, 's-2' => 0],
			'reports' => 2,
		];
	}//end groep7()

	/**
	 * Meester Daan's register: open after the first lesson started, with the two parent reports.
	 *
	 * @return void
	 */
	public function testTheRegisterOfTodayIsNotFilledAfterTheFirstLessonStarted(): void {
		$facts = (new RegisterFacts())->registerNotFilled(groups: [self::groep7(marks: 0)], now: self::monday(time: '08:40'));

		self::assertSame(expected: ['count' => 1, 'cohortId' => 'groep-7', 'cohortName' => 'Groep 7', 'reportCount' => 2], actual: $facts);
	}//end testTheRegisterOfTodayIsNotFilledAfterTheFirstLessonStarted()

	/**
	 * Before the first lesson, or once a mark is saved, the register asks nothing.
	 *
	 * @return void
	 */
	public function testARegisterBeforeTheFirstLessonOrFilledCountsNothing(): void {
		self::assertSame(
			expected: 0,
			actual: (new RegisterFacts())->registerNotFilled(groups: [self::groep7(marks: 0)], now: self::monday(time: '08:15'))['count']
		);
		self::assertSame(
			expected: 0,
			actual: (new RegisterFacts())->registerNotFilled(groups: [self::groep7(marks: 26)], now: self::monday(time: '10:00'))['count']
		);
		self::assertSame(expected: 0, actual: (new RegisterFacts())->registerNotFilled(groups: [], now: self::monday(time: '10:00'))['count']);
	}//end testARegisterBeforeTheFirstLessonOrFilledCountsNothing()

	/**
	 * The directeur at 9.05 sees nothing yet; at 9.35 one of two groups is open.
	 *
	 * @return void
	 */
	public function testTheHeadCountsUnfilledRegistersFromHalfPastNine(): void {
		$groep3 = array_merge(self::groep7(marks: 0), ['cohort' => ['id' => 'groep-3', 'name' => 'Groep 3']]);
		$groups = [self::groep7(marks: 24), $groep3, ['cohort' => ['id' => 'none'], 'sessions' => [], 'records' => [], 'reports' => 0]];

		self::assertSame(
			expected: ['count' => 0, 'total' => 2, 'cohortName' => ''],
			actual: (new RegisterFacts())->registersNotFilledAt930(groups: $groups, now: self::monday(time: '09:05'))
		);
		self::assertSame(
			expected: ['count' => 1, 'total' => 2, 'cohortName' => 'Groep 3'],
			actual: (new RegisterFacts())->registersNotFilledAt930(groups: $groups, now: self::monday(time: '09:35'))
		);
	}//end testTheHeadCountsUnfilledRegistersFromHalfPastNine()

	/**
	 * A lesson that ended without marks counts; a running, marked or cancelled one does not.
	 *
	 * @return void
	 */
	public function testALessonThatEndedWithoutARegisterCounts(): void {
		$sessions = [
			['id' => 'h5a-2', 'cohortId' => 'h5a', 'title' => 'H5a wiskunde', 'endsAt' => '2026-10-05T10:20:00+02:00', 'markCount' => 0],
			['id' => 'h5a-1', 'title' => 'H5a 1e uur', 'endsAt' => '2026-10-05T09:30:00+02:00', 'markCount' => 28],
			['id' => 'h4b-3', 'title' => 'H4b', 'endsAt' => '2026-10-05T11:10:00+02:00', 'markCount' => 0],
			['id' => 'x', 'title' => 'Cancelled', 'endsAt' => '2026-10-05T09:00:00+02:00', 'markCount' => 0, 'lifecycle' => 'cancelled'],
		];

		$facts = (new RegisterFacts())->lessonRegisterOpen(sessions: $sessions, now: self::monday(time: '10:30'));

		self::assertSame(expected: 1, actual: $facts['count']);
		self::assertSame(expected: 'h5a-2', actual: $facts['sessionId']);
		self::assertSame(expected: 'H5a wiskunde', actual: $facts['sessionTitle']);
		self::assertSame(expected: 'h5a', actual: $facts['cohortId']);
		self::assertSame(expected: 0, actual: (new RegisterFacts())->lessonRegisterOpen(sessions: [], now: self::monday(time: '10:30'))['count']);
	}//end testALessonThatEndedWithoutARegisterCounts()

	/**
	 * Only the caller's booked, not yet confirmed times count.
	 *
	 * @return void
	 */
	public function testBookingsWaitingForTheTeachersConfirmation(): void {
		$slots = [
			['teacherId' => 'daan', 'lifecycle' => 'booked'],
			['teacherId' => 'daan', 'lifecycle' => 'booked'],
			['teacherId' => 'daan', 'lifecycle' => 'acknowledged'],
			['teacherId' => 'sanne', 'lifecycle' => 'booked'],
		];

		self::assertSame(expected: ['count' => 2], actual: (new FollowUpFacts())->bookingsToConfirm(slots: $slots, uid: 'daan'));
		self::assertSame(expected: ['count' => 0], actual: (new FollowUpFacts())->bookingsToConfirm(slots: [], uid: 'daan'));
	}//end testBookingsWaitingForTheTeachersConfirmation()

	/**
	 * Pupils nearing the limit count once each, only in the caller's groups.
	 *
	 * @return void
	 */
	public function testPupilsNearingTheAttendanceLimitOfTheCallersGroups(): void {
		$summaries = [
			['learnerId' => 'yusuf', 'thresholdNearing' => true, 'teacherIds' => ['daan']],
			['learnerId' => 'yusuf', 'thresholdNearing' => true, 'teacherIds' => ['daan']],
			['learnerId' => 'noor', 'thresholdNearing' => true, 'teacherIds' => [], 'mentorId' => 'daan'],
			['learnerId' => 'lina', 'thresholdNearing' => false, 'teacherIds' => ['daan']],
			['learnerId' => 'tess', 'thresholdNearing' => true, 'teacherIds' => ['sanne']],
		];

		self::assertSame(expected: ['count' => 2], actual: (new AttendanceFacts())->nearingLimit(summaries: $summaries, uid: 'daan'));
		self::assertSame(expected: ['count' => 0], actual: (new AttendanceFacts())->nearingLimit(summaries: [], uid: 'daan'));
	}//end testPupilsNearingTheAttendanceLimitOfTheCallersGroups()

	/**
	 * An assignment of the caller's group due today with hand-ins missing.
	 *
	 * @return void
	 */
	public function testAnAssignmentDueTodayWithMissingHandIns(): void {
		$assignments = [
			[
				'id' => 'a-1',
				'title' => 'Betoog',
				'cohortId' => 'h5a',
				'dueAt' => '2026-10-05T21:59:00Z',
				'lifecycle' => 'published',
				'submissionCount' => 20,
			],
			[
				'id' => 'a-2',
				'title' => 'Done',
				'cohortId' => 'h5a',
				'dueAt' => '2026-10-05T12:00:00Z',
				'lifecycle' => 'published',
				'submissionCount' => 28,
			],
			[
				'id' => 'a-3',
				'title' => 'Tomorrow',
				'cohortId' => 'h5a',
				'dueAt' => '2026-10-05T22:30:00Z',
				'lifecycle' => 'published',
				'submissionCount' => 0,
			],
			[
				'id' => 'a-4',
				'title' => 'Other group',
				'cohortId' => 'h4b',
				'dueAt' => '2026-10-05T12:00:00Z',
				'lifecycle' => 'published',
				'submissionCount' => 0,
			],
			[
				'id' => 'a-5',
				'title' => 'Draft',
				'cohortId' => 'h5a',
				'dueAt' => '2026-10-05T12:00:00Z',
				'lifecycle' => 'draft',
				'submissionCount' => 0,
			],
		];

		$noon = $this->context(uid: 'anne', time: '12:00');
		$facts = (new FollowUpFacts())->missingHandIns(assignments: $assignments, groupSizes: ['h5a' => 28], context: $noon);

		self::assertSame(expected: ['count' => 1, 'missing' => 8, 'assignmentId' => 'a-1', 'assignmentTitle' => 'Betoog'], actual: $facts);
		self::assertSame(
			expected: 0,
			actual: (new FollowUpFacts())->missingHandIns(assignments: $assignments, groupSizes: [], context: $noon)['count']
		);
	}//end testAnAssignmentDueTodayWithMissingHandIns()

	/**
	 * Yusuf's open flag of last week asks his mentor; an older or handled one does not.
	 *
	 * @return void
	 */
	public function testUnauthorisedAbsenceOfTheMentorsPupilsInTheLastTwoWeeks(): void {
		$flags = [
			['learnerId' => 'yusuf', 'mentorId' => 'sanne', 'lifecycle' => 'open', 'windowEnd' => '2026-10-02'],
			['learnerId' => 'yusuf', 'mentorId' => 'sanne', 'lifecycle' => 'open', 'windowEnd' => '2026-09-30'],
			['learnerId' => 'old', 'mentorId' => 'sanne', 'lifecycle' => 'open', 'windowEnd' => '2026-09-01'],
			['learnerId' => 'taken', 'mentorId' => 'sanne', 'lifecycle' => 'in-handling', 'windowEnd' => '2026-10-02'],
			['learnerId' => 'other', 'mentorId' => 'daan', 'lifecycle' => 'open', 'windowEnd' => '2026-10-02'],
		];

		self::assertSame(
			expected: ['count' => 1, 'learnerId' => 'yusuf'],
			actual: (new AttendanceFacts())->unauthorisedAbsence(flags: $flags, context: $this->context(uid: 'sanne', time: '08:00'))
		);
		self::assertSame(
			expected: ['count' => 0, 'learnerId' => ''],
			actual: (new AttendanceFacts())->unauthorisedAbsence(flags: [], context: $this->context(uid: 'sanne', time: '08:00'))
		);
	}//end testUnauthorisedAbsenceOfTheMentorsPupilsInTheLastTwoWeeks()

	/**
	 * A round of the mentor that closes on Wednesday, with pupils who have not chosen.
	 *
	 * @return void
	 */
	public function testMentorTalksWhoseBookingClosesWithinTwoSchoolDays(): void {
		$rounds = [
			[
				'id' => 'r-1',
				'name' => 'Mentorgesprekken H4b',
				'lifecycle' => 'booking-open',
				'teacherIds' => ['sanne'],
				'bookingClosesAt' => '2026-10-07T17:00:00+02:00',
				'invitedLearnerIds' => ['a', 'b', 'c'],
			],
			[
				'id' => 'r-2',
				'name' => 'Later',
				'lifecycle' => 'booking-open',
				'teacherIds' => ['sanne'],
				'bookingClosesAt' => '2026-10-20T17:00:00+02:00',
				'invitedLearnerIds' => ['d'],
			],
			[
				'id' => 'r-3',
				'name' => 'Not hers',
				'lifecycle' => 'booking-open',
				'teacherIds' => ['daan'],
				'bookingClosesAt' => '2026-10-06T17:00:00+02:00',
				'invitedLearnerIds' => ['e'],
			],
			[
				'id' => 'r-4',
				'name' => 'Closed',
				'lifecycle' => 'booking-closed',
				'teacherIds' => ['sanne'],
				'bookingClosesAt' => '2026-10-06T17:00:00+02:00',
				'invitedLearnerIds' => ['f'],
			],
		];
		$slots = [
			['conferenceRoundId' => 'r-1', 'learnerId' => 'a', 'lifecycle' => 'booked'],
			['conferenceRoundId' => 'r-1', 'learnerId' => 'b', 'lifecycle' => 'free'],
		];

		$monday = $this->context(uid: 'sanne', time: '08:00');
		$facts = (new FollowUpFacts())->mentorTalksClosing(conference: ['rounds' => $rounds, 'slots' => $slots], context: $monday);

		self::assertSame(
			expected: ['count' => 2, 'roundId' => 'r-1', 'roundName' => 'Mentorgesprekken H4b', 'closesAt' => '2026-10-07T17:00:00+02:00'],
			actual: $facts
		);
		self::assertSame(
			expected: 0,
			actual: (new FollowUpFacts())->mentorTalksClosing(conference: [], context: $this->context(uid: 'sanne', time: '08:00'))['count']
		);
	}//end testMentorTalksWhoseBookingClosesWithinTwoSchoolDays()

	/**
	 * Tess Bakker's plan due on Friday and still active asks the care coordinator; one under evaluation does not.
	 *
	 * @return void
	 */
	public function testAPlanEndingWithinSevenDaysWithoutAnEvaluation(): void {
		$learningPlans = [
			['id' => 'lp-tess', 'lifecycle' => 'active', 'nextReviewAt' => '2026-10-09'],
			['id' => 'lp-busy', 'lifecycle' => 'under-evaluation', 'nextReviewAt' => '2026-10-08'],
			['id' => 'lp-far', 'lifecycle' => 'active', 'nextReviewAt' => '2026-11-30'],
			['id' => 'lp-none', 'lifecycle' => 'active'],
		];
		$groupPlans = [['id' => 'gp-7', 'lifecycle' => 'active', 'periodEndDate' => '2026-10-12']];

		self::assertSame(
			expected: ['count' => 2, 'planId' => 'lp-tess', 'schema' => 'learning-plan', 'endsOn' => '2026-10-09'],
			actual: (new FollowUpFacts())->planEnding(plans: ['group-plan' => $groupPlans, 'learning-plan' => $learningPlans], today: '2026-10-05')
		);
		self::assertSame(expected: 0, actual: (new FollowUpFacts())->planEnding(plans: [], today: '2026-10-05')['count']);
	}//end testAPlanEndingWithinSevenDaysWithoutAnEvaluation()

	/**
	 * A support request a teacher submitted and nobody took up.
	 *
	 * @return void
	 */
	public function testASignalFromATeacherNotTakenUp(): void {
		$requests = [['lifecycle' => 'submitted'], ['lifecycle' => 'in-deliberation'], ['lifecycle' => 'draft']];

		self::assertSame(expected: ['count' => 1], actual: (new FollowUpFacts())->signalNotTakenUp(requests: $requests));
		self::assertSame(expected: ['count' => 0], actual: (new FollowUpFacts())->signalNotTakenUp(requests: []));
	}//end testASignalFromATeacherNotTakenUp()

	/**
	 * Two leave requests past half their decision time ask the directeur; a fresh one does not.
	 *
	 * @return void
	 */
	public function testLeaveRequestsPastHalfTheirDecisionTime(): void {
		$requests = [
			['lifecycle' => 'submitted', 'created' => '2026-09-21T10:00:00+02:00', 'decideBy' => '2026-10-07T10:00:00+02:00'],
			['lifecycle' => 'submitted', '@self' => ['created' => '2026-09-28T10:00:00+02:00'], 'decideBy' => '2026-10-06T10:00:00+02:00'],
			['lifecycle' => 'submitted', 'created' => '2026-10-05T07:00:00+02:00', 'decideBy' => '2026-10-19T07:00:00+02:00'],
			['lifecycle' => 'allowed', 'created' => '2026-09-01T10:00:00+02:00', 'decideBy' => '2026-09-10T10:00:00+02:00'],
			['lifecycle' => 'submitted', 'created' => '2026-09-01T10:00:00+02:00'],
		];

		self::assertSame(
			expected: ['count' => 2, 'decideBy' => '2026-10-06T10:00:00+02:00'],
			actual: (new FollowUpFacts())->leavePastHalf(requests: $requests, now: self::monday(time: '08:00'))
		);
		self::assertSame(
			expected: ['count' => 0, 'decideBy' => null],
			actual: (new FollowUpFacts())->leavePastHalf(requests: [], now: self::monday(time: '08:00'))
		);
	}//end testLeaveRequestsPastHalfTheirDecisionTime()

	/**
	 * Pupils over the limit with a flag not reported yet; a reported, an early signal or a course requirement does not count.
	 *
	 * @return void
	 */
	public function testPupilsOverTheLimitNotYetReported(): void {
		$flags = [
			['learnerId' => 'a', 'flagKind' => 'langdurig-relatief-verzuim', 'lifecycle' => 'open'],
			['learnerId' => 'a', 'flagKind' => 'thuiszitter', 'lifecycle' => 'in-handling'],
			['learnerId' => 'b', 'flagKind' => 'thuiszitter', 'lifecycle' => 'in-handling'],
			['learnerId' => 'c', 'flagKind' => 'langdurig-relatief-verzuim', 'lifecycle' => 'reported'],
			['learnerId' => 'd', 'flagKind' => 'signal-verzuim', 'lifecycle' => 'open'],
			['learnerId' => 'e', 'flagKind' => 'attendance-requirement', 'lifecycle' => 'open'],
		];

		self::assertSame(expected: ['count' => 2], actual: (new AttendanceFacts())->overLimitNotReported(flags: $flags));
		self::assertSame(expected: ['count' => 0], actual: (new AttendanceFacts())->overLimitNotReported(flags: []));
	}//end testPupilsOverTheLimitNotYetReported()
}//end class
