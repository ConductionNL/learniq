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
use OCA\Learniq\Service\Today\TodayContext;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Service\Today\FirstTodayService
 * @uses   \OCA\Learniq\Service\Today\FirstTodayRules
 * @uses   \OCA\Learniq\Service\Today\RegisterFacts
 * @uses   \OCA\Learniq\Service\Today\AttendanceFacts
 * @uses   \OCA\Learniq\Service\Today\FollowUpFacts
 * @uses   \OCA\Learniq\Service\Today\TodayContext
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
		$roles = $this->createMock(originalClassName: DashboardRoleServiceInterface::class);
		$roles->method('resolvePrimaryRole')->willReturn($role);
		$segments = $this->createMock(originalClassName: SegmentServiceInterface::class);
		$segments->method('currentSegment')->willReturn($segment);
		$this->reader = $this->createMock(originalClassName: FirstTodayReader::class);
		$this->reader->method('contextFor')->willReturnCallback(
			static fn (IUser $user): TodayContext => new TodayContext(
				user: $user,
				now: new DateTimeImmutable('2026-10-05T' . $time . ':00', new DateTimeZone('Europe/Amsterdam'))
			)
		);

		return new FirstTodayService(roles: $roles, segments: $segments, reader: $this->reader, logger: new NullLogger());
	}//end service()

	/**
	 * A user signed in as daan.
	 *
	 * @return IUser
	 */
	private function daan(): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
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
			->with(self::callback(callback: static fn (TodayContext $context): bool => $context->today() === '2026-10-05'), true)
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

		self::assertSame(expected: 'group-teacher', actual: $card['role']);
		self::assertSame(expected: 'register-not-filled', actual: $card['rule']);
		self::assertSame(expected: 1, actual: $card['count']);
		self::assertSame(expected: 2, actual: $card['reportCount']);
		self::assertSame(expected: 'Groep 7', actual: $card['cohortName']);
	}//end testMeesterDaanReadsTheOpenRegisterFirst()

	/**
	 * Nothing waits: no card, and every rule of the role was asked.
	 *
	 * @return void
	 */
	public function testNothingFirstAnswersNoRule(): void {
		$service = $this->service(role: 'instructor', segment: 'vo', time: '10:30');
		$this->reader->expects(self::once())->method('groupsToday')->willReturn([]);
		$this->reader->expects(self::exactly(count: 2))->method('rows')->willReturn([]);

		self::assertSame(expected: ['role' => 'subject-teacher', 'rule' => null, 'count' => 0], actual: $service->forUser(user: $this->daan()));
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

		self::assertSame(expected: 'mentor', actual: $card['role']);
		self::assertSame(expected: 'unauthorised-absence', actual: $card['rule']);
		self::assertSame(expected: 'yusuf', actual: $card['learnerId']);
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

		self::assertSame(expected: ['role' => null, 'rule' => null, 'count' => 0], actual: $service->forUser(user: $this->daan()));
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

		self::assertSame(expected: 'leave-past-half', actual: $card['rule']);
		self::assertSame(expected: 2, actual: $card['count']);
	}//end testTheHeadReadsLeaveRequestsFirst()

	/**
	 * A failed read gives no card, so the endpoint can say it could not check.
	 *
	 * @return void
	 */
	public function testAFailedReadGivesNoCard(): void {
		$service = $this->service(role: 'coordinator', segment: 'po');
		$this->reader->method('rows')->willThrowException(new RuntimeException('database gone'));

		self::assertNull(actual: $service->cardFor(user: $this->daan()));
	}//end testAFailedReadGivesNoCard()

	/**
	 * The card comes back as forUser() answers it when the reads succeed.
	 *
	 * @return void
	 */
	public function testCardForAnswersTheCard(): void {
		$service = $this->service(role: 'coordinator', segment: 'po');
		$this->reader->method('rows')->willReturn([]);

		self::assertSame(expected: ['role' => 'care-coordinator', 'rule' => null, 'count' => 0], actual: $service->cardFor(user: $this->daan()));
	}//end testCardForAnswersTheCard()
}//end class
