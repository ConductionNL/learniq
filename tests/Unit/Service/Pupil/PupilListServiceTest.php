<?php

/**
 * A group's pupil list and who may read it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Pupil;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Attendance\TodayState;
use OCA\Learniq\Service\Attendance\TodayStateService;
use OCA\Learniq\Service\Pupil\PupilList;
use OCA\Learniq\Service\Pupil\PupilListService;
use OCA\Learniq\Service\Pupil\PupilPageReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Pupil\PupilListService
 * @uses   \OCA\Learniq\Service\Pupil\PupilList
 * @uses   \OCA\Learniq\Service\Pupil\PupilPage
 * @uses   \OCA\Learniq\Service\Attendance\RollCallException
 * @uses   \OCA\Learniq\Support\SchoolYear
 */
class PupilListServiceTest extends TestCase {

	private RollCallAccess&MockObject $access;
	private TodayStateService&MockObject $todayStates;
	private PupilPageReader&MockObject $reader;
	private IUser&MockObject $user;

	/**
	 * Daan's groep 7 with Lina, Hamza and Vera, on Monday 26 October 2026.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->access = $this->createMock(RollCallAccess::class);
		$this->todayStates = $this->createMock(TodayStateService::class);
		$this->reader = $this->createMock(PupilPageReader::class);
		$this->user = $this->createMock(IUser::class);
		$this->reader->method('idOf')->willReturnCallback(static fn (array $row): string => (string)($row['id'] ?? ''));
		$this->access->method('zone')->willReturn(new DateTimeZone('Europe/Amsterdam'));
		$this->access->method('today')->willReturn('2026-10-26');
	}//end setUp()

	/**
	 * The service over the doubles.
	 *
	 * @return PupilListService
	 */
	private function service(): PupilListService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new DateTimeImmutable('2026-10-26T08:30:00+01:00'))->getTimestamp());

		return new PupilListService(access: $this->access, todayStates: $this->todayStates, reader: $this->reader, time: $time);
	}//end service()

	/**
	 * Spec scenario "Groep 7 on Monday morning": the dentist and the illness
	 * are today's states, "Absent today" counts 2, and the view narrows the
	 * rows while the counts stay over the whole group.
	 *
	 * @return void
	 */
	public function testGroep7OnMondayMorning(): void {
		$groep7 = ['id' => 'groep-7', 'name' => 'Groep 7', 'learnerIds' => ['lina', 'hamza', 'vera', '']];
		$cohorts = [['id' => 'groep-8'], $groep7];
		$this->access->expects($this->once())->method('cohortsFor')->with($this->user)->willReturn($cohorts);
		$this->access->expects($this->once())->method('chosenCohort')->with($cohorts, 'groep-7')->willReturn($groep7);
		$this->todayStates->expects($this->once())->method('forCohort')
			->with($groep7, '2026-10-26', new DateTimeZone('Europe/Amsterdam'))
			->willReturn(['lina' => TodayState::APPOINTMENT, 'hamza' => TodayState::REPORTED_ILL]);
		$asked = [];
		$this->reader->method('ofPupils')->willReturnCallback(
			static function (string $schema, array $learnerIds, string $field='learnerId') use (&$asked): array {
				$asked[] = [$schema, $learnerIds, $field];
				return match ($schema) {
					'learner-profile' => [
						'lina' => [['id' => 'p-lina', 'fullName' => 'Lina Aydin']],
						'hamza' => [['id' => 'p-hamza', 'fullName' => 'Hamza El Idrissi']],
						'vera' => [['id' => 'p-vera', 'fullName' => 'Vera Hulstkamp']],
					],
					'attendance-summary' => [
						'vera' => [
							['schoolYear' => '2025-2026', 'absentDays' => 9, 'lateCount' => 4],
							['schoolYear' => '2026-2027', 'absentDays' => 1, 'lateCount' => 2],
						],
					],
					'conference-slot' => ['vera' => [['lifecycle' => 'acknowledged', 'startsAt' => '2026-10-29T18:10:00+01:00']]],
					'learning-plan' => ['hamza' => [['lifecycle' => 'active']]],
					default => [],
				};
			}
		);

		$all = $this->service()->forCohort(user: $this->user, cohortId: 'groep-7');

		$this->assertSame('groep-7', $all['cohortId']);
		$this->assertSame('Groep 7', $all['cohortName']);
		$this->assertSame('2026-2027', $all['schoolYear']);
		$this->assertSame(3, $all['total']);
		$this->assertSame(['Hamza El Idrissi', 'Lina Aydin', 'Vera Hulstkamp'], array_column($all['results'], 'name'));
		$this->assertSame([TodayState::REPORTED_ILL, TodayState::APPOINTMENT, TodayState::NOT_REGISTERED], array_column($all['results'], 'todayState'));
		$this->assertSame([0, 0, 1], array_column($all['results'], 'absentDays'));
		$this->assertSame([0, 0, 2], array_column($all['results'], 'lateCount'));
		$this->assertSame(['none', 'none', 'acknowledged'], array_column($all['results'], 'conferenceState'));
		$this->assertSame(['plan', '', ''], array_column($all['results'], 'care'));
		$this->assertSame(
			[
				PupilList::VIEW_ABSENT_TODAY => 2,
				PupilList::VIEW_NO_CONFERENCE_TIME => 2,
				PupilList::VIEW_WITH_CARE => 1,
				PupilList::VIEW_WITH_SIGNAL => 0,
			],
			$all['views']
		);
		$this->assertSame(
			[
				['learner-profile', ['lina', 'hamza', 'vera'], 'ncUserId'],
				['attendance-summary', ['lina', 'hamza', 'vera'], 'learnerId'],
				['conference-slot', ['lina', 'hamza', 'vera'], 'learnerId'],
				['learning-plan', ['lina', 'hamza', 'vera'], 'learnerId'],
				['support-request', ['lina', 'hamza', 'vera'], 'learnerId'],
				['attendance-flag', ['lina', 'hamza', 'vera'], 'learnerId'],
				['grade-entry', ['lina', 'hamza', 'vera'], 'learnerId'],
				['bpv-placement', ['lina', 'hamza', 'vera'], 'learnerId'],
			],
			$asked
		);
	}//end testGroep7OnMondayMorning()

	/**
	 * A saved view narrows the rows and the total; the counts stay whole.
	 *
	 * @return void
	 */
	public function testAViewNarrowsTheRowsNotTheCounts(): void {
		$groep7 = ['id' => 'groep-7', 'learnerIds' => ['lina', 'vera']];
		$this->access->method('cohortsFor')->willReturn([$groep7]);
		$this->access->method('chosenCohort')->willReturn($groep7);
		$this->todayStates->method('forCohort')->willReturn(['lina' => TodayState::APPOINTMENT, 'vera' => TodayState::IN_CLASS]);
		$this->reader->method('ofPupils')->willReturn([]);

		$list = $this->service()->forCohort(user: $this->user, cohortId: null, view: PupilList::VIEW_ABSENT_TODAY);

		$this->assertSame(1, $list['total']);
		$this->assertSame(['lina'], array_column($list['results'], 'learnerId'));
		$this->assertSame(1, $list['views'][PupilList::VIEW_ABSENT_TODAY]);
		$this->assertSame(2, $list['views'][PupilList::VIEW_NO_CONFERENCE_TIME]);
	}//end testAViewNarrowsTheRowsNotTheCounts()

	/**
	 * The mentor view's order puts a pupil with a signal first.
	 *
	 * @return void
	 */
	public function testSignalFirstReachesTheRules(): void {
		$klas = ['id' => 'v3a', 'learnerIds' => ['anne', 'noor']];
		$this->access->method('cohortsFor')->willReturn([$klas]);
		$this->access->method('chosenCohort')->willReturn($klas);
		$this->todayStates->method('forCohort')->willReturn([]);
		$this->reader->method('ofPupils')->willReturnCallback(
			static fn (string $schema): array => match ($schema) {
				'attendance-flag' => ['noor' => [['lifecycle' => 'open']]],
				default => [],
			}
		);

		$list = $this->service()->forCohort(user: $this->user, sort: PupilList::SORT_SIGNAL_FIRST);

		$this->assertSame(['noor', 'anne'], array_column($list['results'], 'learnerId'));
	}//end testSignalFirstReachesTheRules()

	/**
	 * A group without pupils reads nothing and counts zero.
	 *
	 * @return void
	 */
	public function testAnEmptyGroupReadsNothing(): void {
		$empty = ['id' => 'nieuw', 'name' => 'Nieuw', 'learnerIds' => []];
		$this->access->method('cohortsFor')->willReturn([$empty]);
		$this->access->method('chosenCohort')->willReturn($empty);
		$this->todayStates->expects($this->never())->method('forCohort');
		$this->reader->expects($this->never())->method('ofPupils');

		$list = $this->service()->forCohort(user: $this->user);

		$this->assertSame([], $list['results']);
		$this->assertSame(0, $list['total']);
		$this->assertSame([PupilList::VIEW_ABSENT_TODAY => 0, PupilList::VIEW_NO_CONFERENCE_TIME => 0, PupilList::VIEW_WITH_CARE => 0, PupilList::VIEW_WITH_SIGNAL => 0], $list['views']);
	}//end testAnEmptyGroupReadsNothing()

	/**
	 * A group the caller may not open is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAGroupTheCallerMayNotOpenIsRefused(): void {
		$this->access->method('cohortsFor')->willReturn([['id' => 'groep-8']]);
		$this->access->method('chosenCohort')->willThrowException(new RollCallException('You cannot take the register of this group.', RollCallException::FORBIDDEN));
		$this->todayStates->expects($this->never())->method('forCohort');
		$this->reader->expects($this->never())->method('ofPupils');

		$this->expectException(RollCallException::class);
		$this->service()->forCohort(user: $this->user, cohortId: 'groep-7');
	}//end testAGroupTheCallerMayNotOpenIsRefused()

	/**
	 * A non-teacher is refused by the register's access rule.
	 *
	 * @return void
	 */
	public function testANonTeacherIsRefused(): void {
		$this->access->method('cohortsFor')->willThrowException(new RollCallException('Only teachers and coordinators can take the register.', RollCallException::FORBIDDEN));
		$this->access->expects($this->never())->method('chosenCohort');

		$this->expectExceptionMessage('Only teachers and coordinators can take the register.');
		$this->service()->forCohort(user: $this->user);
	}//end testANonTeacherIsRefused()
}//end class
