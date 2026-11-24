<?php

/**
 * The rules of a group's pupil list: rows, saved views and order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Pupil;

use DateTimeImmutable;
use OCA\Learniq\Service\Attendance\TodayState;
use OCA\Learniq\Service\Pupil\PupilList;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Pupil\PupilList
 * @uses   \OCA\Learniq\Service\Pupil\PupilPage
 */
class PupilListTest extends TestCase {

	/**
	 * One row through PupilList::row(), with the pupil's parts named one by one, at now().
	 *
	 * @param string                    $learnerId  The pupil's user id.
	 * @param array<string, mixed>|null $profile    The profile, or null.
	 * @param string                    $todayState Today's state.
	 * @param array<string, mixed>|null $summary    The attendance summary, or null.
	 * @param array<string, mixed>      $records    The records by schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function rowOf(string $learnerId, ?array $profile, string $todayState, ?array $summary, array $records): array {
		return PupilList::row(
			pupil: ['learnerId' => $learnerId, 'profile' => $profile, 'todayState' => $todayState, 'summary' => $summary, 'records' => $records],
			now: self::now()
		);
	}//end rowOf()

	/**
	 * Monday 26 October 2026, 08.30 Amsterdam time.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-26T08:30:00+01:00');
	}//end now()

	/**
	 * Vera's row: today's state, this year's absence and lateness, the coming
	 * conference she has a time for, a running plan and no signal.
	 *
	 * @return void
	 */
	public function testVerasRowCarriesWhatThePrimaryListShows(): void {
		$row = self::rowOf(
			learnerId: 'vera',
			profile: ['id' => 'p-vera', 'givenName' => 'Vera', 'familyName' => 'Hulstkamp'],
			todayState: TodayState::IN_CLASS,
			summary: ['schoolYear' => '2026-2027', 'absentDays' => 2, 'lateCount' => 1, 'thresholdNearing' => false],
			records: [
				'conference-slot' => [
					['lifecycle' => 'completed', 'startsAt' => '2026-03-12T18:00:00+01:00'],
					['lifecycle' => 'booked', 'startsAt' => '2026-11-05T18:00:00+01:00'],
					['lifecycle' => 'acknowledged', 'startsAt' => '2026-10-29T18:10:00+01:00'],
					['lifecycle' => 'booked', 'startsAt' => '2026-10-20T18:00:00+02:00'],
				],
				'learning-plan' => [['lifecycle' => 'closed'], ['lifecycle' => 'active']],
			],
		);

		$this->assertSame(
			[
				'id' => 'p-vera',
				'learnerId' => 'vera',
				'profileId' => 'p-vera',
				'name' => 'Vera Hulstkamp',
				'todayState' => TodayState::IN_CLASS,
				'todayUntil' => null,
				'absentDays' => 2,
				'presentPercent' => null,
				'lateCount' => 1,
				'conferenceAt' => '2026-10-29T18:10:00+01:00',
				'conferenceState' => 'acknowledged',
				'care' => PupilList::CARE_PLAN,
				'signal' => '',
				'average' => null,
				'insufficientCount' => 0,
				'company' => '',
				'bpvHoursApproved' => null,
				'placementState' => '',
			],
			$row
		);
	}//end testVerasRowCarriesWhatThePrimaryListShows()

	/**
	 * Lina's row carries until when her appointment keeps her away; a value
	 * that is no time string reads as no time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T1
	 */
	public function testARowCarriesUntilWhenThePupilIsAway(): void {
		$pupil = ['learnerId' => 'lina', 'profile' => null, 'todayState' => TodayState::APPOINTMENT, 'summary' => null, 'records' => []];

		$row = PupilList::row(pupil: $pupil + ['todayUntil' => '11:30'], now: self::now());
		$this->assertSame(TodayState::APPOINTMENT, $row['todayState']);
		$this->assertSame('11:30', $row['todayUntil']);

		$this->assertNull(PupilList::row(pupil: $pupil + ['todayUntil' => ''], now: self::now())['todayUntil']);
		$this->assertNull(PupilList::row(pupil: $pupil + ['todayUntil' => 1130], now: self::now())['todayUntil']);
		$this->assertNull(PupilList::row(pupil: $pupil, now: self::now())['todayUntil']);
	}//end testARowCarriesUntilWhenThePupilIsAway()

	/**
	 * Noor's row: a weighted average of the counting grades only, three
	 * insufficient ones make the signal, and an open support request is care.
	 *
	 * @return void
	 */
	public function testThreeInsufficientGradesAreASignal(): void {
		$row = self::rowOf(
			learnerId: 'noor',
			profile: ['uuid' => 'p-noor', 'fullName' => 'Noor Bakker'],
			todayState: TodayState::LATE,
			summary: null,
			records: [
				'grade-entry' => [
					['lifecycle' => 'published', 'value' => 5.0, 'weight' => 2],
					['lifecycle' => 'published', 'value' => 4.5],
					['lifecycle' => 'revised', 'value' => 5.4, 'weight' => 'x'],
					['lifecycle' => 'published', 'value' => 8.1, 'weight' => 1],
					['lifecycle' => 'concept', 'value' => 1.0],
					['lifecycle' => 'published', 'value' => 'nine'],
				],
				'support-request' => [['lifecycle' => 'closed'], ['lifecycle' => 'submitted']],
			],
		);

		$this->assertSame('p-noor', $row['profileId']);
		$this->assertSame(0, $row['absentDays']);
		$this->assertSame(5.6, $row['average']);
		$this->assertSame(3, $row['insufficientCount']);
		$this->assertSame(PupilList::SIGNAL_INSUFFICIENT_GRADES, $row['signal']);
		$this->assertSame(expected: PupilList::CARE_WITH_COORDINATOR, actual: $row['care']);
		$this->assertSame(PupilList::CONFERENCE_NONE, $row['conferenceState']);
		$this->assertNull($row['conferenceAt']);
	}//end testThreeInsufficientGradesAreASignal()

	/**
	 * A support request that lies with the care coordinator reads "in gesprek
	 * met IB"; one routed on reads as requested support; a running plan wins.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T2
	 */
	public function testCareSaysWhereTheSupportRequestLies(): void {
		$care = static fn (array $requests, array $plans = []): string => self::rowOf(
			learnerId: 'x',
			profile: null,
			todayState: TodayState::IN_CLASS,
			summary: null,
			records: ['support-request' => $requests, 'learning-plan' => $plans]
		)['care'];

		$routed    = ['lifecycle' => 'routed-to-swv'];
		$submitted = ['lifecycle' => 'submitted'];
		$this->assertSame(expected: PupilList::CARE_WITH_COORDINATOR, actual: $care([$routed, $submitted]));
		$this->assertSame(expected: PupilList::CARE_SUPPORT_REQUEST, actual: $care([$routed, ['lifecycle' => 'draft']]));
		$this->assertSame(expected: PupilList::CARE_PLAN, actual: $care([$submitted], [['lifecycle' => 'active']]));
		$this->assertSame(expected: '', actual: $care([['lifecycle' => 'closed'], ['lifecycle' => 'draft']]));
		$this->assertTrue(condition: PupilList::inView(row: ['care' => PupilList::CARE_WITH_COORDINATOR], view: PupilList::VIEW_WITH_CARE));
	}//end testCareSaysWhereTheSupportRequestLies()

	/**
	 * An open attendance flag wins over a nearing threshold, which wins over
	 * the grades; a resolved flag is no signal.
	 *
	 * @return void
	 */
	public function testTheSignalOrder(): void {
		$insufficient = [
			['lifecycle' => 'published', 'value' => 3.0],
			['lifecycle' => 'published', 'value' => 3.0],
			['lifecycle' => 'published', 'value' => 3.0],
		];
		$nearing = ['thresholdNearing' => true];

		$flagged = self::rowOf(learnerId: 'a', profile: null, todayState: TodayState::IN_CLASS, summary: $nearing, records: ['attendance-flag' => [['lifecycle' => 'in-handling']], 'grade-entry' => $insufficient]);
		$near = self::rowOf(learnerId: 'b', profile: null, todayState: TodayState::IN_CLASS, summary: $nearing, records: ['attendance-flag' => [['lifecycle' => 'resolved']], 'grade-entry' => $insufficient]);
		$grades = self::rowOf(learnerId: 'c', profile: null, todayState: TodayState::IN_CLASS, summary: ['thresholdNearing' => 'yes'], records: ['grade-entry' => $insufficient]);

		$this->assertSame(PupilList::SIGNAL_ATTENDANCE_FLAG, $flagged['signal']);
		$this->assertSame(PupilList::SIGNAL_NEARING_THRESHOLD, $near['signal']);
		$this->assertSame(PupilList::SIGNAL_INSUFFICIENT_GRADES, $grades['signal']);
		$this->assertSame('c', $grades['name']);
		$this->assertSame('', $grades['profileId']);
		$this->assertSame('c', $grades['id']);
	}//end testTheSignalOrder()

	/**
	 * Milan's row: the running placement's company, approved hours and state.
	 *
	 * @return void
	 */
	public function testMilansPlacementColumns(): void {
		$row = self::rowOf(
			learnerId: 'milan',
			profile: ['fullName' => ''],
			todayState: TodayState::NOT_REGISTERED,
			summary: null,
			records: [
				'bpv-placement' => [
					['lifecycle' => 'completed', 'trainingCompanyName' => 'Oud bedrijf', 'hoursApprovedTotal' => 400],
					['lifecycle' => 'active', 'trainingCompanyName' => 'Zorggroep De Linde', 'hoursApprovedTotal' => 212],
				],
				'conference-slot' => [['lifecycle' => 'proposed', 'startsAt' => '2026-11-02T16:00:00+01:00'], ['lifecycle' => 'booked', 'startsAt' => 'not a date']],
			],
		);

		$this->assertSame('milan', $row['name']);
		$this->assertSame('Zorggroep De Linde', $row['company']);
		$this->assertSame(212.0, $row['bpvHoursApproved']);
		$this->assertSame('active', $row['placementState']);
		$this->assertSame('proposed', $row['conferenceState']);
	}//end testMilansPlacementColumns()

	/**
	 * Spec scenario "Groep 7 on Monday morning": Lina at the dentist and
	 * Hamza reported ill make "Absent today" read 2; a proposed time is no
	 * conference time yet.
	 *
	 * @return void
	 */
	public function testTheSavedViewsCount(): void {
		$rows = [
			['name' => 'Lina', 'todayState' => TodayState::APPOINTMENT, 'conferenceState' => 'booked', 'care' => '', 'signal' => ''],
			['name' => 'Hamza', 'todayState' => TodayState::REPORTED_ILL, 'conferenceState' => 'proposed', 'care' => PupilList::CARE_PLAN, 'signal' => ''],
			['name' => 'Vera', 'todayState' => TodayState::IN_CLASS, 'conferenceState' => 'acknowledged', 'care' => PupilList::CARE_PLAN, 'signal' => ''],
			['name' => 'Sem', 'todayState' => TodayState::LATE, 'conferenceState' => PupilList::CONFERENCE_NONE, 'care' => '', 'signal' => PupilList::SIGNAL_ATTENDANCE_FLAG],
		];

		$this->assertSame(
			[
				PupilList::VIEW_ABSENT_TODAY => 2,
				PupilList::VIEW_NO_CONFERENCE_TIME => 2,
				PupilList::VIEW_WITH_CARE => 2,
				PupilList::VIEW_WITH_SIGNAL => 1,
			],
			PupilList::viewCounts(rows: $rows)
		);
		$this->assertTrue(PupilList::inView(row: $rows[0], view: ''));
		$this->assertTrue(PupilList::inView(row: $rows[0], view: 'no-such-view'));
		$this->assertFalse(PupilList::inView(row: $rows[2], view: PupilList::VIEW_NO_CONFERENCE_TIME));
	}//end testTheSavedViewsCount()

	/**
	 * By name by default; the mentor view puts pupils with a signal first.
	 *
	 * @return void
	 */
	public function testSignalFirstOrder(): void {
		$rows = [
			['name' => 'vera', 'signal' => ''],
			['name' => 'Sem', 'signal' => PupilList::SIGNAL_ATTENDANCE_FLAG],
			['name' => 'Anne', 'signal' => ''],
			['name' => 'Noor', 'signal' => PupilList::SIGNAL_INSUFFICIENT_GRADES],
		];

		$this->assertSame(['Anne', 'Noor', 'Sem', 'vera'], array_column(PupilList::ordered(rows: $rows, sort: ''), 'name'));
		$this->assertSame(['Noor', 'Sem', 'Anne', 'vera'], array_column(PupilList::ordered(rows: $rows, sort: PupilList::SORT_SIGNAL_FIRST), 'name'));
	}//end testSignalFirstOrder()

	/**
	 * Present this year: the school days held less the days absent, in whole percent; none before the first school day.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T2
	 */
	public function testPresentThisYearIsAShareOfTheSchoolDaysHeld(): void {
		$this->assertSame(expected: 85, actual: PupilList::presentPercent(schoolDays: 40, absentDays: 6));
		$this->assertSame(expected: 100, actual: PupilList::presentPercent(schoolDays: 40, absentDays: 0));
		$this->assertSame(expected: 0, actual: PupilList::presentPercent(schoolDays: 3, absentDays: 5));
		$this->assertNull(actual: PupilList::presentPercent(schoolDays: 0, absentDays: 0));
		$this->assertNull(actual: PupilList::presentPercent(schoolDays: null, absentDays: 2));

		$row = PupilList::row(
			pupil: [
				'learnerId' => 'vera',
				'profile' => null,
				'todayState' => TodayState::IN_CLASS,
				'summary' => ['absentDays' => 2],
				'records' => [],
				'schoolDays' => 50,
			],
			now: self::now()
		);
		$this->assertSame(expected: 96, actual: $row['presentPercent']);
	}//end testPresentThisYearIsAShareOfTheSchoolDaysHeld()
}//end class
