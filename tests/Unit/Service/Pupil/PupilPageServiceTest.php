<?php

/**
 * The pupil page's opening data and who may read it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Pupil;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Attendance\TodayState;
use OCA\Learniq\Service\Attendance\TodayStateService;
use OCA\Learniq\Service\Pupil\NextStep;
use OCA\Learniq\Service\Pupil\PupilPage;
use OCA\Learniq\Service\Pupil\PupilPageReader;
use OCA\Learniq\Service\Pupil\PupilPageService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Pupil\PupilPageService
 * @uses   \OCA\Learniq\Service\Pupil\PupilPage
 * @uses   \OCA\Learniq\Service\Pupil\NextStep
 * @uses   \OCA\Learniq\Service\Attendance\RollCallException
 */
class PupilPageServiceTest extends TestCase {

	private RollCallAccess&MockObject $access;
	private TodayStateService&MockObject $todayStates;
	private PupilPageReader&MockObject $reader;
	private IUserManager&MockObject $userManager;
	private IUser&MockObject $user;

	/**
	 * Groep 7 with Vera, opened by Daan at 28 October 2026, 08.30 Amsterdam time.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->access = $this->createMock(RollCallAccess::class);
		$this->todayStates = $this->createMock(TodayStateService::class);
		$this->reader = $this->createMock(PupilPageReader::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('daan');
		$this->reader->method('idOf')->willReturnCallback(static fn (array $row): string => (string)($row['id'] ?? ''));
		$this->access->method('zone')->willReturn(new DateTimeZone('Europe/Amsterdam'));
		$this->access->method('today')->willReturn('2026-10-28');
	}//end setUp()

	/**
	 * The service over the doubles.
	 *
	 * @return PupilPageService
	 */
	private function service(): PupilPageService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new DateTimeImmutable('2026-10-28T08:30:00+01:00'))->getTimestamp());
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PupilPageService(
			access: $this->access,
			todayStates: $this->todayStates,
			reader: $this->reader,
			nextStep: new NextStep(),
			userManager: $this->userManager,
			time: $time,
			l10n: $l10n
		);
	}//end service()

	/**
	 * Spec scenario "Vera's page": the conference Fatima booked and Daan
	 * confirmed is the next step, with Fatima's name; the header and the
	 * people come with it.
	 *
	 * @return void
	 */
	public function testVerasPage(): void {
		$groep7 = ['id' => 'groep-7', 'name' => 'Groep 7', 'learnerIds' => ['vera', 'lina'], 'teacherIds' => ['daan']];
		$this->access->expects($this->once())->method('cohortsFor')->with($this->user)
			->willReturn([['id' => 'groep-8', 'learnerIds' => ['sem']], $groep7]);
		$this->reader->method('profileOf')->with('vera')->willReturn(['givenName' => 'Vera', 'familyName' => 'Hulstkamp', 'ageYears' => 10]);
		$this->reader->expects($this->once())->method('profileById')->with('fatima-uuid')->willReturn(['fullName' => 'Fatima Hulstkamp']);
		$this->todayStates->expects($this->once())->method('forCohort')
			->with($groep7, '2026-10-28', new DateTimeZone('Europe/Amsterdam'))
			->willReturn(['vera' => TodayState::IN_CLASS, 'lina' => TodayState::APPOINTMENT]);
		$this->todayStates->expects($this->once())->method('untilForCohort')->with($groep7, '2026-10-28')->willReturn(['lina' => '11:30']);
		$this->reader->method('ofPupil')->willReturnCallback(
			static fn (string $schema, string $learnerId): array => match ($schema) {
				'conference-slot' => [
					['id' => 'slot-1', 'lifecycle' => 'acknowledged', 'startsAt' => '2026-10-29T18:00:00+01:00', 'guardianRef' => 'fatima-uuid', 'acknowledgedAt' => '2026-10-20T10:00:00+02:00'],
					['id' => 'slot-2', 'lifecycle' => 'booked', 'startsAt' => '2026-11-05T18:00:00+01:00', 'guardianRef' => 'fatima-uuid'],
				],
				'learning-plan' => [['lifecycle' => 'active', 'coordinatorId' => 'ib-anne']],
				default => [],
			}
		);
		$this->reader->expects($this->once())->method('visitsOf')->with([])->willReturn([]);
		$this->userManager->method('getDisplayName')->willReturnMap([['daan', 'Daan de Wit'], ['ib-anne', 'Anne Smit']]);

		$page = $this->service()->open(user: $this->user, learnerId: 'vera');

		$this->assertSame('vera', $page['learnerId']);
		$this->assertSame('groep-7', $page['cohortId']);
		$this->assertSame(TodayState::IN_CLASS, $page['todayState']);
		$this->assertNull($page['todayUntil']);
		$this->assertSame('Vera Hulstkamp', $page['header']['name']);
		$this->assertSame(['kind' => PupilPage::PILL_TODAY, 'value' => TodayState::IN_CLASS], $page['header']['statePill']);
		$this->assertSame('Groep 7', $page['header']['group']);
		$this->assertSame(NextStep::CONFERENCE, $page['nextStep']['kind']);
		$this->assertSame('slot-1', $page['nextStep']['sourceId']);
		$this->assertSame('Fatima Hulstkamp', $page['nextStep']['chosenBy']);
		$this->assertTrue($page['nextStep']['confirmed']);
		$this->assertSame(
			[
				['role' => PupilPage::MENTOR, 'userId' => 'daan', 'name' => 'Daan de Wit'],
				['role' => PupilPage::CARE_COORDINATOR, 'userId' => 'ib-anne', 'noPlan' => false, 'name' => 'Anne Smit'],
			],
			$page['people']
		);
	}//end testVerasPage()

	/**
	 * A student in a placement: the placement's visit is the next step, the
	 * qualification is in the header, and the praktijkopleider (no Nextcloud
	 * user) has no name; the asked group is the one used.
	 *
	 * @return void
	 */
	public function testAStudentInAPlacement(): void {
		$mz = ['id' => 'mz2a', 'name' => 'MZ2A', 'learnerIds' => ['milan']];
		$this->access->method('cohortsFor')->willReturn([['id' => 'other', 'learnerIds' => ['milan']], $mz]);
		$this->reader->method('profileOf')->willReturn(['fullName' => 'Milan de Groot']);
		$this->todayStates->method('forCohort')->with($mz)->willReturn([]);
		$placement = ['id' => 'pl-1', 'lifecycle' => 'active', 'qualificationName' => 'Medewerker zorg', 'crebo' => '25491', 'practicalTrainerId' => 'po-uuid', 'schoolCoachId' => 'coach'];
		$this->reader->method('ofPupil')->willReturnCallback(
			static fn (string $schema): array => match ($schema) {
				'bpv-placement' => [['id' => 'pl-0', 'lifecycle' => 'completed'], $placement],
				default => [],
			}
		);
		$this->reader->expects($this->once())->method('visitsOf')->with(['pl-0', 'pl-1'])
			->willReturn([['id' => 'visit-1', 'lifecycle' => 'planned', 'plannedAt' => '2026-11-03T10:00:00+01:00', 'visitKind' => 'assessment']]);
		$this->reader->expects($this->never())->method('profileById');
		$this->userManager->method('getDisplayName')->willReturnMap([['coach', 'Joost Coach'], ['po-uuid', null]]);

		$page = $this->service()->open(user: $this->user, learnerId: 'milan', cohortId: 'mz2a');

		$this->assertSame('mz2a', $page['cohortId']);
		$this->assertSame(TodayState::NOT_REGISTERED, $page['todayState']);
		$this->assertSame('25491', $page['header']['crebo']);
		$this->assertSame(NextStep::PLACEMENT_VISIT, $page['nextStep']['kind']);
		$this->assertSame('visit-1', $page['nextStep']['sourceId']);
		$this->assertSame(
			[
				['role' => PupilPage::CARE_COORDINATOR, 'userId' => null, 'noPlan' => true, 'name' => ''],
				['role' => PupilPage::PRAKTIJKOPLEIDER, 'userId' => 'po-uuid', 'name' => ''],
				['role' => PupilPage::BPV_BEGELEIDER, 'userId' => 'coach', 'name' => 'Joost Coach'],
			],
			$page['people']
		);
	}//end testAStudentInAPlacement()

	/**
	 * A guardian whose profile is gone leaves the conference's chooser empty,
	 * and a slot that already names its guardian is not looked up.
	 *
	 * @return void
	 */
	public function testAMissingGuardianLeavesTheChooserEmpty(): void {
		$this->access->method('cohortsFor')->willReturn([['id' => 'g', 'learnerIds' => ['vera']]]);
		$this->reader->method('profileOf')->willReturn(['fullName' => 'Vera']);
		$this->todayStates->method('forCohort')->willReturn([]);
		$this->reader->method('ofPupil')->willReturnCallback(
			static fn (string $schema): array => match ($schema) {
				'conference-slot' => [
					['id' => 'named', 'lifecycle' => 'booked', 'startsAt' => '2026-11-10T18:00:00+01:00', 'guardianRef' => 'x', 'guardianName' => 'Al Genoemd'],
					['id' => 'gone', 'lifecycle' => 'booked', 'startsAt' => '2026-10-30T18:00:00+01:00', 'guardianRef' => 'gone-uuid'],
					['id' => 'gone-2', 'lifecycle' => 'booked', 'startsAt' => '2026-11-01T18:00:00+01:00', 'guardianRef' => 'gone-uuid'],
				],
				default => [],
			}
		);
		$this->reader->method('visitsOf')->willReturn([]);
		$this->reader->expects($this->once())->method('profileById')->with('gone-uuid')->willReturn(null);

		$page = $this->service()->open(user: $this->user, learnerId: 'vera');

		$this->assertSame('gone', $page['nextStep']['sourceId']);
		$this->assertSame('', $page['nextStep']['chosenBy']);
		$this->assertFalse($page['nextStep']['confirmed']);
	}//end testAMissingGuardianLeavesTheChooserEmpty()

	/**
	 * A teacher of no group of the pupil is refused before anything is read.
	 *
	 * @return void
	 */
	public function testACallerWithoutAGroupOfThePupilIsRefused(): void {
		$this->access->method('cohortsFor')->willReturn([['id' => 'groep-8', 'learnerIds' => ['sem']]]);
		$this->reader->expects($this->never())->method('profileOf');

		try {
			$this->service()->open(user: $this->user, learnerId: 'vera');
			$this->fail('A caller without a group of the pupil must be refused.');
		} catch (RollCallException $exception) {
			$this->assertSame(RollCallException::FORBIDDEN, $exception->getStatus());
			$this->assertSame('You cannot open the page of this pupil.', $exception->getMessage());
		}
	}//end testACallerWithoutAGroupOfThePupilIsRefused()

	/**
	 * An asked group the pupil is not in is refused, even when another group holds the pupil.
	 *
	 * @return void
	 */
	public function testAnAskedGroupWithoutThePupilIsRefused(): void {
		$this->access->method('cohortsFor')->willReturn([['id' => 'groep-7', 'learnerIds' => ['vera']], ['id' => 'groep-8', 'learnerIds' => ['sem']]]);

		$this->expectException(RollCallException::class);
		$this->service()->open(user: $this->user, learnerId: 'vera', cohortId: 'groep-8');
	}//end testAnAskedGroupWithoutThePupilIsRefused()

	/**
	 * An empty pupil id is refused, even by school-wide staff.
	 *
	 * @return void
	 */
	public function testAnEmptyPupilIsRefused(): void {
		$this->access->expects($this->never())->method('cohortsFor');

		$this->expectException(RollCallException::class);
		$this->service()->open(user: $this->user, learnerId: '');
	}//end testAnEmptyPupilIsRefused()

	/**
	 * A refusal from the register's access rule passes through.
	 *
	 * @return void
	 */
	public function testANonTeacherIsRefusedByTheAccessRule(): void {
		$this->access->method('cohortsFor')->willThrowException(new RollCallException('Only teachers and coordinators can take the register.', RollCallException::FORBIDDEN));
		$this->reader->expects($this->never())->method('profileOf');

		$this->expectExceptionMessage('Only teachers and coordinators can take the register.');
		$this->service()->open(user: $this->user, learnerId: 'vera');
	}//end testANonTeacherIsRefusedByTheAccessRule()

	/**
	 * A pupil without a profile is 404.
	 *
	 * @return void
	 */
	public function testAPupilWithoutAProfileIsNotFound(): void {
		$this->access->method('cohortsFor')->willReturn([['id' => 'groep-7', 'learnerIds' => ['vera']]]);
		$this->reader->method('profileOf')->willReturn(null);
		$this->todayStates->expects($this->never())->method('forCohort');

		try {
			$this->service()->open(user: $this->user, learnerId: 'vera');
			$this->fail('A pupil without a profile must be 404.');
		} catch (RollCallException $exception) {
			$this->assertSame(PupilPageService::NOT_FOUND, $exception->getStatus());
		}
	}//end testAPupilWithoutAProfileIsNotFound()
}//end class
