<?php

/**
 * SessionChangeBatchService: one change on many lessons, each guarded.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-every-lesson-in-a-batch-passes-the-same-checks
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Learniq\Lifecycle\SessionChangeGuard;
use OCA\Learniq\Listener\SessionChangeNoticeHandler;
use OCA\Learniq\Service\SessionChangeBatchService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\SessionChangeInput;
use OCA\Learniq\Timetabling\SessionSeries;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Guard per lesson as the caller, refusals recorded, one union of people.
 */
class SessionChangeBatchServiceTest extends TestCase {

	/**
	 * Lessons by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $sessions = [];

	/**
	 * Every saveObject call: [schema, object, uuid].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: ?string}>
	 */
	private array $saves = [];

	/**
	 * Every transition fired: [id, action].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $transitions = [];

	/**
	 * Every guard call: [id, action, user].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $guardCalls = [];

	/**
	 * The series finder over the same object double.
	 *
	 * @var SessionSeries|null
	 */
	private ?SessionSeries $series = null;

	/**
	 * Four Tuesday lessons at 10:15 of one group and course, the last one completed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saves = [];
		$this->transitions = [];
		$this->guardCalls = [];
		$this->sessions = [];
		foreach (['2026-03-03', '2026-03-10', '2026-03-17', '2026-03-24'] as $index => $date) {
			$id = 's'.($index + 1);
			$this->sessions[$id] = [
				'id' => $id,
				'cohortId' => 'cohort-4h',
				'courseId' => 'course-wisb',
				'title' => 'Wiskunde B, 4 havo',
				'startsAt' => $date.'T10:15:00+01:00',
				'endsAt' => $date.'T11:05:00+01:00',
				'lifecycle' => ($index === 3) ? 'completed' : 'scheduled',
				'tenant_id' => 'tenant-1',
			];
		}

		// Another course in the same slot, and the same course on a Thursday.
		$this->sessions['other-course'] = array_merge($this->sessions['s2'], ['id' => 'other-course', 'courseId' => 'course-ne']);
		$this->sessions['thursday'] = array_merge($this->sessions['s2'], ['id' => 'thursday', 'startsAt' => '2026-03-12T10:15:00+01:00']);
	}//end setUp()

	/**
	 * The service over the lessons above.
	 *
	 * @param callable|null $guardRule fn(array $session, string $action): ?string, a refusal reason or null.
	 *
	 * @return SessionChangeBatchService
	 */
	private function service(?callable $guardRule=null): SessionChangeBatchService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			fn ($id) => isset($this->sessions[$id]) ? OrEntityFactory::make($this->sessions[$id], 'session') : null
		);
		$objects->method('findAll')->willReturnCallback(
			fn () => OrEntityFactory::makeMany(array_values($this->sessions), 'session')
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, $extend=[], $register=null, $schema=null, $uuid=null) {
				$this->saves[] = [(string)$schema, $object, $uuid];
				return OrEntityFactory::make($object, (string)$schema, 'learniq', $uuid);
			}
		);

		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('transition')->willReturnCallback(
			function (string $id, string $action) {
				$this->transitions[] = [$id, $action];
				return OrEntityFactory::make($this->sessions[$id], 'session');
			}
		);

		$guard = $this->createMock(SessionChangeGuard::class);
		$guard->method('check')->willReturnCallback(
			function (array $object, string $action, string $userId) use ($guardRule) {
				$this->guardCalls[] = [(string)$object['id'], $action, $userId];
				$reason = ($guardRule !== null) ? $guardRule($object, $action) : null;
				return ($reason === null) ? GuardResult::allow() : GuardResult::deny($reason);
			}
		);

		$notices = $this->createMock(SessionChangeNoticeHandler::class);
		$notices->method('affectedPeople')->willReturnCallback(
			static fn (array $session) => [
				'learnerIds' => ['j.bakker', 't.smit'],
				'parentIds' => ['ouder-'.$session['id'], 'ouder-bakker'],
			]
		);

		$this->series = new SessionSeries($objects);

		return new SessionChangeBatchService($objects, $this->series, new SessionChangeInput(), $engine, $guard, $notices, new NullLogger());
	}//end service()

	/**
	 * A completed lesson is refused, the other three are cancelled.
	 *
	 * @return void
	 */
	public function testRefusedLessonDoesNotStopTheBatch(): void {
		$batch = $this->service()->apply(
			['kind' => 'cancel', 'sessionIds' => ['s1', 's2', 's3', 's4'], 'changeReasonKind' => 'teacher-absence'],
			'coordinator-1'
		);

		self::assertSame(3, $batch['appliedCount']);
		self::assertSame([['s1', 'cancel'], ['s2', 'cancel'], ['s3', 'cancel']], $this->transitions);
		$outcomes = array_column($batch['results'], 'outcome', 'sessionId');
		self::assertSame(['s1' => 'applied', 's2' => 'applied', 's3' => 'applied', 's4' => 'refused'], $outcomes);
		self::assertNotEmpty($batch['results'][3]['reason']);
	}//end testRefusedLessonDoesNotStopTheBatch()

	/**
	 * The guard runs for every lesson, as the person who made the batch, and
	 * its refusal is recorded with its reason.
	 *
	 * @return void
	 */
	public function testGuardRunsPerLessonAsTheCaller(): void {
		$batch = $this->service(
			static fn (array $session): ?string => ($session['id'] === 's2') ? 'Not your group.' : null
		)->apply(['kind' => 'cancel', 'sessionIds' => ['s1', 's2', 's3'], 'changeReasonKind' => 'teacher-absence'], 'teacher-9');

		self::assertSame([['s1', 'cancel', 'teacher-9'], ['s2', 'cancel', 'teacher-9'], ['s3', 'cancel', 'teacher-9']], $this->guardCalls);
		self::assertSame('Not your group.', $batch['results'][1]['reason']);
		self::assertSame([['s1', 'cancel'], ['s3', 'cancel']], $this->transitions);
	}//end testGuardRunsPerLessonAsTheCaller()

	/**
	 * Each changed lesson points at the batch and carries no people of its
	 * own; the batch carries the union and the dates.
	 *
	 * @return void
	 */
	public function testOneMessagePerBatch(): void {
		$batch = $this->service()->apply(
			['kind' => 'cancel', 'sessionIds' => ['s1', 's2', 's3'], 'changeReasonKind' => 'teacher-absence', 'changeReason' => 'Ziek'],
			'coordinator-1'
		);

		$sessionSaves = array_values(array_filter($this->saves, static fn (array $save): bool => $save[0] === 'session'));
		self::assertCount(3, $sessionSaves);
		foreach ($sessionSaves as $save) {
			self::assertSame($batch['id'], $save[1]['changeBatchId']);
			self::assertSame([], $save[1]['affectedLearnerIds']);
			self::assertSame([], $save[1]['affectedParentIds']);
		}

		$batchSaves = array_values(array_filter($this->saves, static fn (array $save): bool => $save[0] === 'session-change-batch'));
		self::assertCount(1, $batchSaves);
		self::assertSame($batch['id'], $batchSaves[0][2]);
		self::assertSame(['j.bakker', 't.smit'], $batchSaves[0][1]['affectedLearnerIds']);
		self::assertSame(['ouder-s1', 'ouder-bakker', 'ouder-s2', 'ouder-s3'], $batchSaves[0][1]['affectedParentIds']);
		self::assertSame('3-3-2026, 10-3-2026, 17-3-2026', $batchSaves[0][1]['lessonDates']);
		self::assertSame('tenant-1', $batchSaves[0][1]['tenant_id']);
	}//end testOneMessagePerBatch()

	/**
	 * A substitute change fires the substitute transition; a room change is a
	 * guarded update with no transition.
	 *
	 * @return void
	 */
	public function testSubstituteAndRoomChanges(): void {
		$this->service()->apply(['kind' => 'substitute', 'sessionIds' => ['s1'], 'changeReasonKind' => 'teacher-absence', 'substituteTeacherId' => 'sub-1'], 'coordinator-1');
		self::assertSame([['s1', 'substitute-teacher']], $this->transitions);

		$this->transitions = [];
		$this->saves = [];
		$this->service()->apply(['kind' => 'room', 'sessionIds' => ['s2'], 'changeReasonKind' => 'room-unavailable', 'roomId' => 'room-b12'], 'coordinator-1');
		self::assertSame([], $this->transitions);
		self::assertSame('room-b12', $this->saves[0][1]['roomId']);
		self::assertSame(['s2', 'room-change', 'coordinator-1'], end($this->guardCalls));
	}//end testSubstituteAndRoomChanges()

	/**
	 * A transition the engine refuses restores the lesson and is recorded.
	 *
	 * @return void
	 */
	public function testFailedTransitionRestoresTheLesson(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(fn ($id) => OrEntityFactory::make($this->sessions[$id], 'session'));
		$objects->method('saveObject')->willReturnCallback(
			function ($object, $extend=[], $register=null, $schema=null, $uuid=null) {
				$this->saves[] = [(string)$schema, $object, $uuid];
				return OrEntityFactory::make($object, (string)$schema);
			}
		);
		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('transition')->willThrowException(new \RuntimeException('Transition not allowed.'));
		$guard = $this->createMock(SessionChangeGuard::class);
		$guard->method('check')->willReturn(GuardResult::allow());
		$notices = $this->createMock(SessionChangeNoticeHandler::class);

		$batch = (new SessionChangeBatchService($objects, new SessionSeries($objects), new SessionChangeInput(), $engine, $guard, $notices, new NullLogger()))
			->apply(['kind' => 'cancel', 'sessionIds' => ['s1'], 'changeReasonKind' => 'other'], 'coordinator-1');

		self::assertSame(0, $batch['appliedCount']);
		self::assertSame('Transition not allowed.', $batch['results'][0]['reason']);
		$restored = $this->saves[1][1];
		unset($restored['@self']);
		self::assertSame($this->sessions['s1'], $restored);
		self::assertSame([], $batch['affectedLearnerIds']);
	}//end testFailedTransitionRestoresTheLesson()

	/**
	 * A substitute change without a substitute is refused.
	 *
	 * @return void
	 */
	public function testIncompleteInputIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service()->apply(['kind' => 'substitute', 'sessionIds' => ['s1'], 'changeReasonKind' => 'teacher-absence'], 'coordinator-1');
	}//end testIncompleteInputIsRefused()

	/**
	 * A change without a reason is refused before any lesson is touched.
	 *
	 * @return void
	 */
	public function testMissingReasonIsRefused(): void {
		try {
			$this->service()->apply(['kind' => 'cancel', 'sessionIds' => ['s1']], 'coordinator-1');
			self::fail('Expected a refusal.');
		} catch (InvalidArgumentException $exception) {
			self::assertSame('A change needs a reason.', $exception->getMessage());
		}

		self::assertSame([], $this->saves);
	}//end testMissingReasonIsRefused()

	/**
	 * The series holds the same group, course, weekday and time, from the
	 * lesson on, up to the until date.
	 *
	 * @return void
	 */
	public function testSeriesListsTheSameWeeklySlot(): void {
		$this->service();
		$series = $this->series->series('s2', '2026-03-20');

		self::assertSame(['s2', 's3'], array_column($series, 'id'));
		self::assertTrue($series[0]['changeable']);
	}//end testSeriesListsTheSameWeeklySlot()

	/**
	 * An unreadable lesson has no series.
	 *
	 * @return void
	 */
	public function testSeriesOfAnUnknownLessonIsNull(): void {
		$this->service();
		self::assertNull($this->series->series('nope'));
	}//end testSeriesOfAnUnknownLessonIsNull()
}//end class
