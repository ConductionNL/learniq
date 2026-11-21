<?php

/**
 * The First today card of a user: her role, the rule that wins, and the reads it makes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Today;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Kernel\Contracts\DashboardRoleServiceInterface;
use OCA\Learniq\Kernel\Contracts\SegmentServiceInterface;
use OCA\Learniq\Service\Today\FirstTodayReader;
use OCA\Learniq\Service\Today\FirstTodayService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Today\FirstTodayService
 * @uses   \OCA\Learniq\Service\Today\FirstTodayRules
 * @uses   \OCA\Learniq\Service\Today\FirstTodayFacts
 * @uses   \OCA\Learniq\Service\Exam\WorkingDays
 */
class FirstTodayServiceTest extends TestCase {

	/**
	 * The reader double.
	 *
	 * @var FirstTodayReader&MockObject
	 */
	private FirstTodayReader&MockObject $reader;

	/**
	 * The service for a user of a role at a school kind, on Monday 5 October 2026 at a time.
	 *
	 * @param string $role    The primary role.
	 * @param string $segment The school kind.
	 * @param string $time    `H:i`, Amsterdam.
	 *
	 * @return FirstTodayService
	 */
	private function service(string $role, string $segment, string $time='08:40'): FirstTodayService {
		$roles = $this->createMock(DashboardRoleServiceInterface::class);
		$roles->method('resolvePrimaryRole')->willReturn($role);
		$segments = $this->createMock(SegmentServiceInterface::class);
		$segments->method('currentSegment')->willReturn($segment);
		$this->reader = $this->createMock(FirstTodayReader::class);
		$this->reader->method('zone')->willReturn(new DateTimeZone('Europe/Amsterdam'));
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn((new DateTimeImmutable('2026-10-05T' . $time . ':00', new DateTimeZone('Europe/Amsterdam')))->getTimestamp());

		return new FirstTodayService(roles: $roles, segments: $segments, reader: $this->reader, time: $clock);
	}//end service()

	/**
	 * A user signed in as daan.
	 *
	 * @return IUser
	 */
	private function daan(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('daan');
		return $user;
	}//end daan()

	/**
	 * Meester Daan at 8.40 reads that the register of groep 7 is not filled, with the two parent reports.
	 *
	 * @return void
	 */
	public function testMeesterDaanReadsTheOpenRegisterFirst(): void {
		$service = $this->service(role: 'instructor', segment: 'po');
		$this->reader->expects(self::once())->method('groupsToday')
			->with(self::anything(), true, '2026-10-05', self::anything())
			->willReturn(
				[[
					'cohort' => ['id' => 'groep-7', 'name' => 'Groep 7'],
					'sessions' => [['id' => 's-1', 'startsAt' => '2026-10-05T08:30:00+02:00']],
					'records' => ['s-1' => 0],
					'reports' => 2,
				],
				]
			);
		$this->reader->expects(self::never())->method('rows');

		$card = $service->forUser(user: $this->daan());

		self::assertSame('group-teacher', $card['role']);
		self::assertSame('register-not-filled', $card['rule']);
		self::assertSame(1, $card['count']);
		self::assertSame(2, $card['reportCount']);
		self::assertSame('Groep 7', $card['cohortName']);
	}//end testMeesterDaanReadsTheOpenRegisterFirst()

	/**
	 * Nothing waits: no card, and every rule of the role was asked.
	 *
	 * @return void
	 */
	public function testNothingFirstAnswersNoRule(): void {
		$service = $this->service(role: 'instructor', segment: 'vo', time: '10:30');
		$this->reader->expects(self::once())->method('groupsToday')->willReturn([]);
		$this->reader->expects(self::exactly(2))->method('rows')->willReturn([]);

		self::assertSame(['role' => 'subject-teacher', 'rule' => null, 'count' => 0], $service->forUser(user: $this->daan()));
	}//end testNothingFirstAnswersNoRule()

	/**
	 * In the Mentor view a teacher reads the mentor's rules, on her own mentor pupils.
	 *
	 * @return void
	 */
	public function testTheMentorViewReadsTheMentorsRules(): void {
		$service = $this->service(role: 'instructor', segment: 'vo');
		$this->reader->expects(self::once())->method('rows')
			->with('attendance-flag', ['mentorId' => 'daan', 'lifecycle' => 'open'])
			->willReturn([['learnerId' => 'yusuf', 'mentorId' => 'daan', 'lifecycle' => 'open', 'windowEnd' => '2026-10-02']]);

		$card = $service->forUser(user: $this->daan(), view: 'mentor');

		self::assertSame('mentor', $card['role']);
		self::assertSame('unauthorised-absence', $card['rule']);
		self::assertSame('yusuf', $card['learnerId']);
	}//end testTheMentorViewReadsTheMentorsRules()

	/**
	 * A role Today has no card for reads nothing.
	 *
	 * @return void
	 */
	public function testARoleWithoutACardReadsNothing(): void {
		$service = $this->service(role: 'learner', segment: 'vo');
		$this->reader->expects(self::never())->method('rows');
		$this->reader->expects(self::never())->method('groupsToday');

		self::assertSame(['role' => null, 'rule' => null, 'count' => 0], $service->forUser(user: $this->daan()));
	}//end testARoleWithoutACardReadsNothing()

	/**
	 * The directeur's leave requests past half their time win over the registers.
	 *
	 * @return void
	 */
	public function testTheHeadReadsLeaveRequestsFirst(): void {
		$service = $this->service(role: 'administration-manager', segment: 'po', time: '09:05');
		$this->reader->expects(self::once())->method('rows')
			->with('leave-request', ['lifecycle' => 'submitted'])
			->willReturn(
				[
					['lifecycle' => 'submitted', 'created' => '2026-09-21T10:00:00+02:00', 'decideBy' => '2026-10-07T10:00:00+02:00'],
					['lifecycle' => 'submitted', 'created' => '2026-09-22T10:00:00+02:00', 'decideBy' => '2026-10-08T10:00:00+02:00'],
				]
			);
		$this->reader->expects(self::never())->method('groupsToday');

		$card = $service->forUser(user: $this->daan());

		self::assertSame('leave-past-half', $card['rule']);
		self::assertSame(2, $card['count']);
	}//end testTheHeadReadsLeaveRequestsFirst()
}//end class
