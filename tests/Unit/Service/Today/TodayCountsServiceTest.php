<?php

/**
 * The role tiles on Today: which tiles a role gets and the reads behind them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Today;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Kernel\Contracts\DashboardRoleServiceInterface;
use OCA\Learniq\Kernel\Contracts\SegmentServiceInterface;
use OCA\Learniq\Service\Today\FirstTodayReader;
use OCA\Learniq\Service\Today\TodayContext;
use OCA\Learniq\Service\Today\TodayCountsService;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Service\Today\TodayCountsService
 * @uses   \OCA\Learniq\Service\Today\TodayCountFacts
 * @uses   \OCA\Learniq\Service\Today\FirstTodayRules
 * @uses   \OCA\Learniq\Service\Today\FollowUpFacts
 * @uses   \OCA\Learniq\Service\Today\TodayContext
 */
class TodayCountsServiceTest extends TestCase {

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
	 * @return TodayCountsService
	 */
	private function service(string $role, string $segment, string $time='09:05'): TodayCountsService {
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

		return new TodayCountsService(roles: $roles, segments: $segments, reader: $this->reader, logger: new NullLogger());
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
	 * Meester Daan's tiles: the open register, the parent reports and his conference times; his groups are read once.
	 *
	 * @return void
	 */
	public function testTheGroupTeacherCountsHisRegisterReportsAndConference(): void {
		$service = $this->service(role: 'instructor', segment: 'po', time: '08:40');
		$this->reader->expects(self::once())->method('groupsToday')->with(self::anything(), true)->willReturn(
			[[
				'cohort' => ['id' => 'groep-7', 'name' => 'Groep 7'],
				'sessions' => [['id' => 's-1', 'startsAt' => '2026-10-05T08:30:00+02:00']],
				'records' => ['s-1' => 0],
				'reports' => 2,
			],
			]
		);
		$this->reader->expects(self::once())->method('rows')
			->with('conference-slot', ['teacherId' => 'daan'])
			->willReturn([['teacherId' => 'daan', 'lifecycle' => 'booked'], ['teacherId' => 'daan', 'lifecycle' => 'confirmed']]);

		$answer = $service->forUser(user: $this->daan());

		self::assertSame(expected: 'group-teacher', actual: $answer['role']);
		self::assertSame(
			expected: [
				['id' => 'registers-not-filled', 'count' => 1, 'total' => 1],
				['id' => 'parent-reports-waiting', 'count' => 2, 'groups' => 1],
				['id' => 'conference-times', 'count' => 1, 'chosen' => 2, 'total' => 2],
			],
			actual: $answer['tiles']
		);
	}//end testTheGroupTeacherCountsHisRegisterReportsAndConference()

	/**
	 * A subject teacher's marking stack counts hand-ins of his own groups only.
	 *
	 * @return void
	 */
	public function testTheSubjectTeacherCountsHisMarkingStack(): void {
		$service = $this->service(role: 'instructor', segment: 'vo');
		$this->reader->method('groupsToday')->willReturn([['cohort' => ['id' => 'h5a'], 'sessions' => [], 'records' => [], 'reports' => 0]]);
		$this->reader->method('rows')->willReturnCallback(
			static fn (string $schema, array $filters=[]): array => match ($schema . '|' . ($filters['lifecycle'] ?? '')) {
				'assignment|published' => [['id' => 'essay', 'cohortId' => 'h5a'], ['id' => 'other', 'cohortId' => 'h4b']],
				'submission|submitted' => [['assignmentId' => 'essay', 'lifecycle' => 'submitted'], ['assignmentId' => 'other', 'lifecycle' => 'submitted']],
				'submission|late' => [['assignmentId' => 'essay', 'lifecycle' => 'late']],
				default => [],
			}
		);

		$tiles = array_column($service->forUser(user: $this->daan())['tiles'], null, 'id');

		self::assertSame(expected: 'subject-teacher', actual: $service->forUser(user: $this->daan())['role']);
		self::assertSame(expected: 2, actual: $tiles['submissions-to-mark']['count']);
		self::assertSame(expected: 1, actual: $tiles['submissions-to-mark']['assignments']);
	}//end testTheSubjectTeacherCountsHisMarkingStack()

	/**
	 * The head reads every group: registers filled, pupils with three fails, cancelled lessons and leave to decide.
	 *
	 * @return void
	 */
	public function testTheHeadCountsTheSchool(): void {
		$service = $this->service(role: 'administration-manager', segment: 'vo');
		$this->reader->expects(self::once())->method('groupsToday')->with(self::anything(), false)->willReturn(
			[
				[
					'cohort' => ['id' => 'h4b', 'programmeYear' => 4, 'learnerIds' => ['yusuf']],
					'sessions' => [['id' => 'a']],
					'records' => ['a' => 27],
					'reports' => 0,
				],
				[
					'cohort' => ['id' => 'h5a', 'programmeYear' => 5, 'learnerIds' => []],
					'sessions' => [['id' => 'b', 'lifecycle' => 'cancelled']],
					'records' => ['b' => 0],
					'reports' => 0,
				],
			]
		);
		$this->reader->method('rows')->willReturnCallback(
			static fn (string $schema): array => match ($schema) {
				'final-grade' => array_fill(0, 3, ['learnerId' => 'yusuf', 'passed' => false]),
				'leave-request' => [['lifecycle' => 'submitted'], ['lifecycle' => 'submitted']],
				default => [],
			}
		);

		$answer = $service->forUser(user: $this->daan());

		self::assertSame(expected: 'head', actual: $answer['role']);
		self::assertSame(
			expected: [
				['id' => 'registers-filled-per-group', 'count' => 1, 'total' => 2],
				['id' => 'insufficient-per-year', 'count' => 1, 'perYear' => [4 => 1]],
				['id' => 'lessons-cancelled-today', 'count' => 1],
				['id' => 'leave-to-decide', 'count' => 2],
			],
			actual: $answer['tiles']
		);
	}//end testTheHeadCountsTheSchool()

	/**
	 * The intern begeleider counts plans that end within seven days.
	 *
	 * @return void
	 */
	public function testTheCareCoordinatorCountsPlansEnding(): void {
		$service = $this->service(role: 'coordinator', segment: 'po');
		$this->reader->expects(self::never())->method('groupsToday');
		$this->reader->method('rows')->willReturnCallback(
			static fn (string $schema): array => match ($schema) {
				'learning-plan' => [['lifecycle' => 'active', 'nextReviewAt' => '2026-10-09'], ['lifecycle' => 'active', 'nextReviewAt' => '2026-11-30']],
				default => [],
			}
		);

		self::assertSame(
			expected: ['role' => 'care-coordinator', 'tiles' => [['id' => 'plans-ending', 'count' => 1]]],
			actual: $service->forUser(user: $this->daan())
		);
	}//end testTheCareCoordinatorCountsPlansEnding()

	/**
	 * A role Today has no tiles for reads nothing.
	 *
	 * @return void
	 */
	public function testARoleWithoutTilesReadsNothing(): void {
		$service = $this->service(role: 'learner', segment: 'vo');
		$this->reader->expects(self::never())->method('contextFor');
		$this->reader->expects(self::never())->method('rows');
		$this->reader->expects(self::never())->method('groupsToday');

		self::assertSame(expected: [], actual: $service->forUser(user: $this->daan())['tiles']);
	}//end testARoleWithoutTilesReadsNothing()

	/**
	 * A failed read answers null, so the controller can say so.
	 *
	 * @return void
	 */
	public function testAFailedReadAnswersNull(): void {
		$service = $this->service(role: 'administration-manager', segment: 'vo');
		$this->reader->method('groupsToday')->willThrowException(new RuntimeException('down'));

		self::assertNull(actual: $service->tilesFor(user: $this->daan()));
	}//end testAFailedReadAnswersNull()
}//end class
