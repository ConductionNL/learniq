<?php

/**
 * The pupil page's header and the people around the pupil.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Pupil;

use OCA\Learniq\Service\Pupil\PupilPage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Pupil\PupilPage
 */
class PupilPageTest extends TestCase {

	/**
	 * Vera's header: name, initials, today's state as the pill, group and age.
	 *
	 * @return void
	 */
	public function testVerasHeaderShowsTodaysStateWithoutASignal(): void {
		$header = PupilPage::header(
			profile: ['givenName' => 'Vera', 'familyName' => 'Hulstkamp', 'ageYears' => 10],
			cohort: ['name' => 'Groep 7'],
			todayState: 'in-class',
			flags: [['lifecycle' => 'resolved', 'flagKind' => 'signal-verzuim']],
			placement: null
		);

		$this->assertSame(
			[
				'name' => 'Vera Hulstkamp',
				'initials' => 'VH',
				'statePill' => ['kind' => PupilPage::PILL_TODAY, 'value' => 'in-class'],
				'group' => 'Groep 7',
				'age' => 10,
				'pupilNumber' => null,
				'qualification' => '',
				'crebo' => '',
			],
			$header
		);
	}//end testVerasHeaderShowsTodaysStateWithoutASignal()

	/**
	 * Milan's header: the full name wins, initials take the last family name
	 * part, the placement gives the qualification and crebo, and a non-integer
	 * age reads as null.
	 *
	 * @return void
	 */
	public function testAStudentInAPlacementShowsTheQualificationAndCrebo(): void {
		$header = PupilPage::header(
			profile: ['fullName' => 'Milan de Groot', 'givenName' => 'Milan', 'familyName' => 'de Groot', 'ageYears' => '19'],
			cohort: ['name' => 'MZ2A'],
			todayState: 'not-registered',
			flags: [],
			placement: ['qualificationName' => ' Medewerker zorg ', 'crebo' => '25491']
		);

		$this->assertSame('Milan de Groot', $header['name']);
		$this->assertSame('MG', $header['initials']);
		$this->assertNull($header['age']);
		$this->assertSame('Medewerker zorg', $header['qualification']);
		$this->assertSame('25491', $header['crebo']);
	}//end testAStudentInAPlacementShowsTheQualificationAndCrebo()

	/**
	 * Without given and family name the initials come from the full name.
	 *
	 * @return void
	 */
	public function testInitialsFallBackToTheFullName(): void {
		$this->assertSame('NB', PupilPage::header(profile: ['fullName' => 'noor van bakker'], cohort: [], todayState: '', flags: [], placement: null)['initials']);
		$this->assertSame('C', PupilPage::header(profile: ['fullName' => 'Cher'], cohort: [], todayState: '', flags: [], placement: null)['initials']);
		$this->assertSame('', PupilPage::header(profile: [], cohort: [], todayState: '', flags: [], placement: null)['initials']);
	}//end testInitialsFallBackToTheFullName()

	/**
	 * An open signal wins over today's state, the oldest open one first.
	 *
	 * @return void
	 */
	public function testTheOldestOpenSignalIsThePill(): void {
		$pill = PupilPage::statePill(
			todayState: 'in-class',
			flags: [
				['lifecycle' => 'open', 'flagKind' => 'signal-verzuim', 'windowEnd' => '2026-10-20'],
				['lifecycle' => 'resolved', 'flagKind' => 'older-but-closed', 'windowEnd' => '2026-09-01'],
				['lifecycle' => 'in-handling', 'flagKind' => 'threshold-warning', 'windowEnd' => '2026-10-02'],
			]
		);

		$this->assertSame(['kind' => PupilPage::PILL_SIGNAL, 'value' => 'threshold-warning'], $pill);
	}//end testTheOldestOpenSignalIsThePill()

	/**
	 * An active placement runs before a confirmed one; others do not run.
	 *
	 * @return void
	 */
	public function testTheRunningPlacement(): void {
		$this->assertSame(
			['id' => 'b', 'lifecycle' => 'active'],
			PupilPage::runningPlacement(placements: [['id' => 'a', 'lifecycle' => 'confirmed'], ['id' => 'b', 'lifecycle' => 'active']])
		);
		$this->assertSame(['id' => 'a', 'lifecycle' => 'confirmed'], PupilPage::runningPlacement(placements: [['id' => 'a', 'lifecycle' => 'confirmed']]));
		$this->assertNull(PupilPage::runningPlacement(placements: [['id' => 'c', 'lifecycle' => 'completed'], ['id' => 'd', 'lifecycle' => 'proposed']]));
	}//end testTheRunningPlacement()

	/**
	 * Vera: the primary teacher is the mentor, the duo partner follows, and the
	 * care coordinator of her running plan is named; no placement people.
	 *
	 * @return void
	 */
	public function testPeopleOfAPupilWithAPlanAndADuo(): void {
		$people = PupilPage::people(
			cohort: [
				'teacherIds' => ['daan', 'els'],
				'teacherAssignments' => [
					['teacherId' => 'els', 'role' => 'duo-partner'],
					['teacherId' => 'daan', 'role' => 'primary'],
					['teacherId' => 'other', 'role' => 'primary'],
					'not-an-array',
				],
			],
			plans: [['lifecycle' => 'closed', 'coordinatorId' => 'old'], ['lifecycle' => 'active', 'coordinatorId' => 'ib-anne']],
			placement: null
		);

		$this->assertSame(
			[
				['role' => PupilPage::MENTOR, 'userId' => 'daan'],
				['role' => PupilPage::DUO_PARTNER, 'userId' => 'els'],
				['role' => PupilPage::CARE_COORDINATOR, 'userId' => 'ib-anne', 'noPlan' => false],
			],
			$people
		);
	}//end testPeopleOfAPupilWithAPlanAndADuo()

	/**
	 * Without assignments the first teacher is the mentor; without a running
	 * plan the care coordinator says no plan runs; a placement adds its
	 * praktijkopleider and BPV-begeleider.
	 *
	 * @return void
	 */
	public function testPeopleOfAStudentInAPlacementWithoutAPlan(): void {
		$people = PupilPage::people(
			cohort: ['teacherIds' => ['', 'slb-sara']],
			plans: [['lifecycle' => 'draft', 'coordinatorId' => 'zoco']],
			placement: ['practicalTrainerId' => 'po-uuid', 'schoolCoachId' => '']
		);

		$this->assertSame(
			[
				['role' => PupilPage::MENTOR, 'userId' => 'slb-sara'],
				['role' => PupilPage::CARE_COORDINATOR, 'userId' => null, 'noPlan' => true],
				['role' => PupilPage::PRAKTIJKOPLEIDER, 'userId' => 'po-uuid'],
				['role' => PupilPage::BPV_BEGELEIDER, 'userId' => null],
			],
			$people
		);
	}//end testPeopleOfAStudentInAPlacementWithoutAPlan()

	/**
	 * A plan under evaluation still runs.
	 *
	 * @return void
	 */
	public function testAPlanUnderEvaluationStillRuns(): void {
		$people = PupilPage::people(cohort: [], plans: [['lifecycle' => 'under-evaluation', 'coordinatorId' => 'zoco']], placement: null);

		$this->assertSame([['role' => PupilPage::CARE_COORDINATOR, 'userId' => 'zoco', 'noPlan' => false]], $people);
	}//end testAPlanUnderEvaluationStillRuns()

	/**
	 * The header carries the school's own pupil number, never the BSN-type
	 * personal number; a blank one reads as null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function testTheHeaderShowsTheSchoolsPupilNumberNotThePersonalNumber(): void {
		$header = PupilPage::header(
			profile: ['fullName' => 'Vera Hulstkamp', 'pupilNumber' => '20231457', 'personalNumber' => '123456782'],
			cohort: [],
			todayState: 'in-class',
			flags: [],
			placement: null
		);
		$this->assertSame(expected: '20231457', actual: $header['pupilNumber']);
		$this->assertNotContains(needle: '123456782', haystack: $header);

		$blank = PupilPage::header(
			profile: ['pupilNumber' => '  ', 'personalNumber' => '123456782'],
			cohort: [],
			todayState: '',
			flags: [],
			placement: null
		);
		$this->assertNull(actual: $blank['pupilNumber']);
	}//end testTheHeaderShowsTheSchoolsPupilNumberNotThePersonalNumber()

	/**
	 * The group's team leader follows the teachers and its career coach
	 * follows the care coordinator; blank ones are left out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
	 */
	public function testTheGroupsTeamLeaderAndCareerCoachAreAmongThePeople(): void {
		$people = PupilPage::people(
			cohort: ['teacherIds' => ['daan'], 'teamLeaderId' => 'tl-iris', 'careerCoachId' => 'slb-sara'],
			plans: [],
			placement: ['practicalTrainerId' => 'po-uuid', 'schoolCoachId' => 'bpv-bas']
		);

		$this->assertSame(
			expected: [
				['role' => PupilPage::MENTOR, 'userId' => 'daan'],
				['role' => PupilPage::TEAM_LEADER, 'userId' => 'tl-iris'],
				['role' => PupilPage::CARE_COORDINATOR, 'userId' => null, 'noPlan' => true],
				['role' => PupilPage::CAREER_COACH, 'userId' => 'slb-sara'],
				['role' => PupilPage::PRAKTIJKOPLEIDER, 'userId' => 'po-uuid'],
				['role' => PupilPage::BPV_BEGELEIDER, 'userId' => 'bpv-bas'],
			],
			actual: $people
		);

		$none = PupilPage::people(cohort: ['teamLeaderId' => '', 'careerCoachId' => null], plans: [], placement: null);
		$this->assertSame(expected: [['role' => PupilPage::CARE_COORDINATOR, 'userId' => null, 'noPlan' => true]], actual: $none);
	}//end testTheGroupsTeamLeaderAndCareerCoachAreAmongThePeople()

	/**
	 * The support lesson line is listed after the care coordinator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
	 */
	public function testTheSupportLessonIsAmongThePeople(): void {
		$line   = ['role' => PupilPage::SUPPORT_LESSON, 'userId' => null, 'name' => 'Steunles wiskunde A · Meneer Demir', 'from' => '2026-10-06'];
		$people = PupilPage::people(cohort: ['teacherIds' => ['sanne']], plans: [], placement: null, lessons: [$line]);

		$this->assertSame(
			expected: [
				['role' => PupilPage::MENTOR, 'userId' => 'sanne'],
				['role' => PupilPage::CARE_COORDINATOR, 'userId' => null, 'noPlan' => true],
				['role' => PupilPage::SUPPORT_LESSON, 'userId' => null, 'name' => 'Steunles wiskunde A · Meneer Demir', 'from' => '2026-10-06'],
			],
			actual: $people
		);
	}//end testTheSupportLessonIsAmongThePeople()
}//end class
