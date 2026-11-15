<?php

/**
 * Learniq SessionMoveNoticeHandler unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/timetabling/spec.md#requirement-a-lesson-moved-to-another-room-or-time-notifies-the-affected-learners-and-parents
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Kernel\Contracts\SessionChangeNoticeHandlerInterface;
use OCA\Learniq\Listener\SessionMoveNoticeHandler;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SessionMoveNoticeHandler: one lesson moved to another room or time.
 *
 * @covers \OCA\Learniq\Listener\SessionMoveNoticeHandler
 */
class SessionMoveNoticeHandlerTest extends TestCase {

	private const ROOM_214 = 'room-2-14';
	private const ROOM_108 = 'room-1-08';

	/**
	 * OpenRegister object access.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * The rows saveObject received.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Wire the object service: two rooms, and saves recorded.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				$rooms = [
					self::ROOM_214 => ['id' => self::ROOM_214, 'name' => '2.14'],
					self::ROOM_108 => ['id' => self::ROOM_108, 'name' => '1.08'],
				];
				if ($schema === 'room' && isset($rooms[$id]) === true) {
					return OrEntityFactory::make($rooms[$id], 'room');
				}

				return null;
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$this->saved[] = $object;
				return OrEntityFactory::make($object, (string)$schema, (string)$register);
			}
		);
	}//end setUp()

	/**
	 * The handler, with the group's learners and their listed parents.
	 *
	 * @return SessionMoveNoticeHandler
	 */
	private function handler(): SessionMoveNoticeHandler {
		$notices = $this->createMock(SessionChangeNoticeHandlerInterface::class);
		$notices->method('affectedPeople')->willReturn(['learnerIds' => ['fenna', 'daan'], 'parentIds' => ['parent-fenna']]);

		return new SessionMoveNoticeHandler($this->objectService, $notices, TransitionScope::resolver(), new NullLogger());
	}//end handler()

	/**
	 * Next Tuesday's "Wiskunde B, 4 havo" lesson in room 2.14 at 10:15.
	 *
	 * @param array<string,mixed> $changes Fields to override.
	 *
	 * @return array<string,mixed>
	 */
	private function lesson(array $changes=[]): array {
		return array_merge(
			[
				'id' => 'session-1',
				'title' => 'Wiskunde B, 4 havo',
				'cohortId' => 'cohort-4h',
				'roomId' => self::ROOM_214,
				'location' => null,
				'startsAt' => '2026-11-17T10:15:00+01:00',
				'endsAt' => '2026-11-17T11:05:00+01:00',
				'lifecycle' => 'scheduled',
				'changeBatchId' => null,
				'tenant_id' => 'tenant-a',
			],
			$changes
		);
	}//end lesson()

	/**
	 * Dispatch an update from one lesson state to another.
	 *
	 * @param array<string,mixed> $old The lesson before.
	 * @param array<string,mixed> $new The lesson after.
	 *
	 * @return void
	 */
	private function update(array $old, array $new): void {
		$this->handler()->handle(
			new ObjectUpdatedEvent(OrEntityFactory::make($new, 'session'), OrEntityFactory::make($old, 'session'))
		);
	}//end update()

	/**
	 * Scenario: a single lesson moves to another room.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/timetabling/spec.md#scenario-a-single-lesson-moves-to-another-room
	 */
	public function testASingleLessonMovedToAnotherRoomNotifiesTheGroupAndParents(): void {
		$this->update($this->lesson(), $this->lesson(['roomId' => self::ROOM_108]));

		self::assertCount(1, $this->saved);
		$row = $this->saved[0];
		self::assertSame(['fenna', 'daan'], $row['affectedLearnerIds']);
		self::assertSame(['parent-fenna'], $row['affectedParentIds']);
		self::assertSame('Wiskunde B, 4 havo, 17-11-2026 10:15, 1.08', $row['moveNotice']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $row['movedAt']);
		self::assertSame(self::ROOM_108, $row['roomId']);
	}//end testASingleLessonMovedToAnotherRoomNotifiesTheGroupAndParents()

	/**
	 * Scenario: a lesson moves to another time — the notice names the new time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/timetabling/spec.md#scenario-a-lesson-moves-to-another-time
	 */
	public function testALessonMovedToAnotherTimeNamesTheNewTime(): void {
		$this->update($this->lesson(), $this->lesson(['startsAt' => '2026-11-17T13:00:00+01:00', 'endsAt' => '2026-11-17T13:50:00+01:00']));

		self::assertCount(1, $this->saved);
		self::assertSame('Wiskunde B, 4 havo, 17-11-2026 13:00', $this->saved[0]['moveNotice']);
		self::assertSame(['fenna', 'daan'], $this->saved[0]['affectedLearnerIds']);
	}//end testALessonMovedToAnotherTimeNamesTheNewTime()

	/**
	 * A lesson without a room row names its location label.
	 *
	 * @return void
	 */
	public function testALocationChangeNamesTheNewLocation(): void {
		$this->update($this->lesson(['roomId' => null, 'location' => '2.14']), $this->lesson(['roomId' => null, 'location' => 'Aula']));

		self::assertSame('Wiskunde B, 4 havo, 17-11-2026 10:15, Aula', $this->saved[0]['moveNotice']);
	}//end testALocationChangeNamesTheNewLocation()

	/**
	 * The same moment written in another offset is not a move.
	 *
	 * @return void
	 */
	public function testTheSameStartInAnotherOffsetIsNoMove(): void {
		$this->update($this->lesson(), $this->lesson(['startsAt' => '2026-11-17T09:15:00+00:00']));

		self::assertSame([], $this->saved);
	}//end testTheSameStartInAnotherOffsetIsNoMove()

	/**
	 * Scenario context: a batch change sends its own one message, so the lesson sends none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/timetabling/spec.md#scenario-a-coordinator-moves-three-weeks-of-a-lesson-to-another-room
	 */
	public function testALessonChangedByABatchSendsNoMessageOfItsOwn(): void {
		$this->update($this->lesson(), $this->lesson(['roomId' => self::ROOM_108, 'changeBatchId' => 'batch-1']));
		$this->update($this->lesson(['roomId' => self::ROOM_108, 'changeBatchId' => 'batch-1']), $this->lesson(['changeBatchId' => null]));

		self::assertSame([], $this->saved);
	}//end testALessonChangedByABatchSendsNoMessageOfItsOwn()

	/**
	 * A lesson once in a batch that later moves on its own is notified.
	 *
	 * @return void
	 */
	public function testALaterSingleMoveOfALessonOnceInABatchIsNotified(): void {
		$this->update($this->lesson(['changeBatchId' => 'batch-1']), $this->lesson(['changeBatchId' => 'batch-1', 'roomId' => self::ROOM_108]));

		self::assertCount(1, $this->saved);
	}//end testALaterSingleMoveOfALessonOnceInABatchIsNotified()

	/**
	 * The handler's own write (room and time unchanged) does not trigger it again; nor does an edit of other fields.
	 *
	 * @return void
	 */
	public function testAnUpdateThatMovesNothingWritesNothing(): void {
		$moved = $this->lesson(['roomId' => self::ROOM_108]);
		$this->update($moved, array_merge($moved, ['moveNotice' => 'x', 'movedAt' => '2026-11-15T10:00:00+00:00']));
		$this->update($this->lesson(), $this->lesson(['title' => 'Wiskunde B']));

		self::assertSame([], $this->saved);
	}//end testAnUpdateThatMovesNothingWritesNothing()

	/**
	 * A cancelled lesson is told by its cancel notice, not a move notice.
	 *
	 * @return void
	 */
	public function testACancelledLessonGetsNoMoveNotice(): void {
		$this->update($this->lesson(), $this->lesson(['lifecycle' => 'cancelled', 'roomId' => self::ROOM_108]));

		self::assertSame([], $this->saved);
	}//end testACancelledLessonGetsNoMoveNotice()

	/**
	 * Another schema's update is ignored.
	 *
	 * @return void
	 */
	public function testAnotherSchemasUpdateIsIgnored(): void {
		$this->handler()->handle(
			new ObjectUpdatedEvent(
				OrEntityFactory::make($this->lesson(['roomId' => self::ROOM_108]), 'room'),
				OrEntityFactory::make($this->lesson(), 'room')
			)
		);

		self::assertSame([], $this->saved);
	}//end testAnotherSchemasUpdateIsIgnored()
}//end class
