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
use OCA\Learniq\Service\Today\FirstTodayProbe;
use OCA\Learniq\Service\Today\FirstTodayService;
use OCA\Learniq\Service\Today\TodayTiles;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Service\Today\TodayTiles
 * @covers \OCA\Learniq\Service\Today\FirstTodayService
 * @uses   \OCA\Learniq\Service\Today\FirstTodayProbe
 * @uses   \OCA\Learniq\Service\Today\RegisterFacts
 * @uses   \OCA\Learniq\Service\Today\AttendanceFacts
 * @uses   \OCA\Learniq\Service\Today\PlacementFacts
 * @uses   \OCA\Learniq\Service\Today\ExamFacts
 * @uses   \OCA\Learniq\Service\Today\TodayCountFacts
 * @uses   \OCA\Learniq\Service\Today\FirstTodayRules
 * @uses   \OCA\Learniq\Service\Today\FollowUpFacts
 * @uses   \OCA\Learniq\Service\Today\TodayContext
 */
class TodayTilesTest extends TestCase {

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
	private function service(string $role, string $segment, string $time='09:05'): FirstTodayService {
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

		$answer = $service->tilesFor(user: $this->daan());

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

		$tiles = array_column($service->tilesFor(user: $this->daan())['tiles'], null, 'id');

		self::assertSame(expected: 'subject-teacher', actual: $service->tilesFor(user: $this->daan())['role']);
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

		$answer = $service->tilesFor(user: $this->daan());

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
			expected: [
				'role' => 'care-coordinator',
				'tiles' => [['id' => 'plans-ending', 'count' => 1]],
				'byTile' => ['plansEnding' => ['id' => 'plans-ending', 'count' => 1]],
			],
			actual: $service->tilesFor(user: $this->daan())
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

		$answer = $service->tilesFor(user: $this->daan());
		self::assertSame(expected: [], actual: $answer['tiles']);
		self::assertSame(expected: [], actual: $answer['byTile']);
	}//end testARoleWithoutTilesReadsNothing()

	/**
	 * Every tile is keyed by the camelCase form of its id, which a caption token can name.
	 *
	 * @return void
	 */
	public function testByTileKeysEveryTileInCamelCase(): void {
		$tiles = [];
		foreach (TodayTiles::TILES as $ids) {
			foreach ($ids as $id) {
				$tiles[$id] = ['id' => $id, 'count' => 2];
			}
		}

		$keyed = $this->tiles()->byTile(tiles: array_values($tiles));

		self::assertSame(
			expected: [
				'registersNotFilled',
				'parentReportsWaiting',
				'conferenceTimes',
				'submissionsToMark',
				'plansEnding',
				'registersFilledPerGroup',
				'insufficientPerYear',
				'lessonsCancelledToday',
				'leaveToDecide',
			],
			actual: array_keys($keyed)
		);
		foreach (array_keys($keyed) as $key) {
			self::assertMatchesRegularExpression(pattern: '/^[A-Za-z0-9_]+$/', string: $key);
		}

		self::assertSame(expected: ['id' => 'leave-to-decide', 'count' => 2], actual: $keyed['leaveToDecide']);
	}//end testByTileKeysEveryTileInCamelCase()

	/**
	 * A row without an id is left out of the keyed tiles.
	 *
	 * @return void
	 */
	public function testByTileSkipsARowWithoutAnId(): void {
		self::assertSame(expected: [], actual: $this->tiles()->byTile(tiles: [['count' => 3], ['id' => '', 'count' => 1]]));
	}//end testByTileSkipsARowWithoutAnId()

	/**
	 * Tiles over a reader and a probe that are never asked: byTile() reads nothing.
	 *
	 * @return TodayTiles
	 */
	private function tiles(): TodayTiles {
		$reader = $this->createMock(originalClassName: FirstTodayReader::class);
		$reader->expects(self::never())->method('rows');

		return new TodayTiles(reader: $reader, probe: new FirstTodayProbe(reader: $reader));
	}//end tiles()

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

	/**
	 * The BPV hours per company read her own placements and the submitted weeks, and nothing more without placements.
	 *
	 * @return void
	 */
	public function testHoursPerCompanyReadsHerPlacementsAndTheSubmittedWeeks(): void {
		$service = $this->service(role: 'instructor', segment: 'mbo');
		$this->reader->method('rows')->willReturnCallback(
			static fn (string $schema, array $filters=[]): array => match ([$schema, $filters]) {
				['bpv-placement', ['schoolCoachId' => 'daan']] => [['id' => 'p-1', 'schoolCoachId' => 'daan', 'trainingCompanyName' => 'Van Dijk Machinebouw']],
				['bpv-hour-week', ['lifecycle' => 'submitted']] => [
					['bpvPlacementId' => 'p-1', 'isoWeek' => '2026-W40', 'hoursSubmitted' => 24, 'submittedAt' => '2026-10-02T17:00:00+02:00', 'lifecycle' => 'submitted'],
				],
				default => [],
			}
		);

		$answer = $service->hoursPerCompanyFor(user: $this->daan());

		self::assertSame(expected: ['Van Dijk Machinebouw'], actual: array_column($answer['companies'], 'company'));
		self::assertSame(expected: 24.0, actual: $answer['companies'][0]['hours']);
	}//end testHoursPerCompanyReadsHerPlacementsAndTheSubmittedWeeks()

	/**
	 * A teacher who coaches no placement reads no hour weeks; a failed read answers null.
	 *
	 * @return void
	 */
	public function testHoursPerCompanyWithoutPlacementsOrAFailedRead(): void {
		$service = $this->service(role: 'instructor', segment: 'mbo');
		$this->reader->expects(self::once())->method('rows')->with('bpv-placement', ['schoolCoachId' => 'daan'])->willReturn([]);

		self::assertSame(expected: ['companies' => []], actual: $service->hoursPerCompanyFor(user: $this->daan()));

		$failing = $this->service(role: 'instructor', segment: 'mbo');
		$this->reader->method('rows')->willThrowException(new RuntimeException('down'));
		self::assertNull(actual: $failing->hoursPerCompanyFor(user: $this->daan()));
	}//end testHoursPerCompanyWithoutPlacementsOrAFailedRead()
}//end class
