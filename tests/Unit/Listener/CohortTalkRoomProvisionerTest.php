<?php

/**
 * Learniq CohortTalkRoomProvisioner unit tests.
 *
 * A Cohort that becomes active with no Talk conversation linked gets one
 * named after it, linked through OpenRegister's TalkLinkService, with its
 * active learners added; a cohort with a room gets no second one, and with
 * Talk off nothing is created.
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
 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/school-structure/spec.md#requirement-a-new-cohort-gets-its-own-talk-room-automatically
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\CohortTalkRoomProvisioner;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TalkLink\RoomCreation;
use OCA\OpenRegister\Service\TalkLinkService;
use OCA\Talk\Manager as TalkManager;
use OCA\Talk\Room as TalkRoom;
use OCA\Talk\Service\ParticipantService as TalkParticipantService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../../Support/SlugMapper.php';
require_once __DIR__ . '/../../Support/TransitionScope.php';

/**
 * @covers \OCA\Learniq\Listener\CohortTalkRoomProvisioner
 * @uses   \OCA\Learniq\Support\SerialisedRow
 * @uses   \OCA\Learniq\Service\ListenerSchemaResolver
 * @uses   \OCA\Learniq\Service\ListenerSlugContract
 */
class CohortTalkRoomProvisionerTest extends TestCase {

	private TalkLinkService&MockObject $talkLinks;
	private ObjectService&MockObject $objects;
	private TalkManager&MockObject $manager;
	private TalkParticipantService&MockObject $participants;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->talkLinks = $this->createMock(TalkLinkService::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->manager = $this->createMock(TalkManager::class);
		$this->participants = $this->createMock(TalkParticipantService::class);
	}//end setUp()

	/**
	 * The provisioner under test, with a container that resolves Talk.
	 *
	 * @return CohortTalkRoomProvisioner
	 */
	private function provisioner(): CohortTalkRoomProvisioner {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				'OCA\\Talk\\Manager' => $this->manager,
				'OCA\\Talk\\Service\\ParticipantService' => $this->participants,
				default => throw new RuntimeException('Unexpected container lookup: ' . $id),
			}
		);

		return new CohortTalkRoomProvisioner(
			$this->talkLinks,
			$this->objects,
			$container,
			$this->createMock(LoggerInterface::class),
			TransitionScope::resolver()
		);
	}//end provisioner()

	/**
	 * A transition event on a learniq object.
	 *
	 * @param string              $schema The schema slug.
	 * @param string              $to     The state moved to.
	 * @param array<string,mixed> $data   The object's jsonSerialize() payload.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(string $schema, string $to, array $data): ObjectTransitionedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getUuid')->willReturn($data['id'] ?? null);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn(TransitionScope::LEARNIQ_REGISTER_ID);
		$event->method('getSchema')->willReturn(TransitionScope::schemaId($schema));
		$event->method('getTo')->willReturn($to);

		return $event;
	}//end event()

	/**
	 * A new active cohort gets one room named after it, linked to the
	 * cohort, with its active learners as participants.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/school-structure/spec.md#scenario-a-coordinator-activates-a-new-cohort
	 */
	public function testANewActiveCohortGetsOneLinkedRoomWithItsLearners(): void {
		$this->talkLinks->method('isTalkAvailable')->willReturn(true);
		$this->talkLinks->method('getLinkedRooms')->with('cohort-1')->willReturn([]);

		$created = null;
		$this->talkLinks->expects(self::once())->method('createAndLinkRoom')->willReturnCallback(
			function (RoomCreation $creation) use (&$created): TalkLink {
				$created = $creation;
				return new TalkLink(roomToken: 'tok-1');
			}
		);

		$this->objects->expects(self::once())->method('findAll')->with(
			[
				'filters' => [
					'register' => 'learniq',
					'schema' => 'enrolment',
					'cohortId' => 'cohort-1',
					'lifecycle' => 'active',
				],
			]
		)->willReturn([['learnerId' => 'fenna'], ['learnerId' => 'daan'], ['learnerId' => ''], ['learnerId' => 'fenna']]);

		$room = $this->createMock(TalkRoom::class);
		$this->manager->method('getRoomByToken')->with('tok-1')->willReturn($room);
		$this->participants->expects(self::once())->method('addUsers')->with(
			$room,
			[['actorType' => 'users', 'actorId' => 'fenna'], ['actorType' => 'users', 'actorId' => 'daan']]
		);

		$this->provisioner()->handle($this->event('cohort', 'active', ['id' => 'cohort-1', 'name' => ' Verpleegkunde 2027 ']));

		self::assertInstanceOf(RoomCreation::class, $created);
		self::assertSame('Verpleegkunde 2027', $created->roomName);
		self::assertSame(2, $created->roomType);
		self::assertSame('cohort-1', $created->object->objectUuid);
		self::assertSame((int)TransitionScope::LEARNIQ_REGISTER_ID, $created->object->registerId);
		self::assertSame((int)TransitionScope::schemaId('cohort'), $created->object->schemaId);
	}//end testANewActiveCohortGetsOneLinkedRoomWithItsLearners()

	/**
	 * A cohort with no active learners yet gets its room and no participant call.
	 *
	 * @return void
	 */
	public function testACohortWithoutActiveLearnersGetsAnEmptyRoom(): void {
		$this->talkLinks->method('isTalkAvailable')->willReturn(true);
		$this->talkLinks->method('getLinkedRooms')->willReturn([]);
		$this->talkLinks->expects(self::once())->method('createAndLinkRoom')->willReturn(new TalkLink(roomToken: 'tok-1'));
		$this->objects->method('findAll')->willReturn([]);
		$this->participants->expects(self::never())->method('addUsers');

		$this->provisioner()->handle($this->event('cohort', 'active', ['id' => 'cohort-1', 'name' => 'Klas 1A']));
	}//end testACohortWithoutActiveLearnersGetsAnEmptyRoom()

	/**
	 * A cohort that already has a room gets no second one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/school-structure/spec.md#scenario-a-cohort-that-already-has-a-room
	 */
	public function testACohortWithARoomGetsNoSecondOne(): void {
		$this->talkLinks->method('isTalkAvailable')->willReturn(true);
		$this->talkLinks->method('getLinkedRooms')->with('cohort-1')->willReturn([['roomToken' => 'existing']]);
		$this->talkLinks->expects(self::never())->method('createAndLinkRoom');
		$this->participants->expects(self::never())->method('addUsers');

		$this->provisioner()->handle($this->event('cohort', 'active', ['id' => 'cohort-1', 'name' => 'Klas 1A']));
	}//end testACohortWithARoomGetsNoSecondOne()

	/**
	 * With Talk off nothing is created and nothing is looked up.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/school-structure/spec.md#scenario-talk-is-not-available
	 */
	public function testTalkOffCreatesNothing(): void {
		$this->talkLinks->method('isTalkAvailable')->willReturn(false);
		$this->talkLinks->expects(self::never())->method('getLinkedRooms');
		$this->talkLinks->expects(self::never())->method('createAndLinkRoom');

		$this->provisioner()->handle($this->event('cohort', 'active', ['id' => 'cohort-1', 'name' => 'Klas 1A']));
	}//end testTalkOffCreatesNothing()

	/**
	 * A refused room (no user session, Talk error) is logged, not thrown,
	 * and no learner is looked up.
	 *
	 * @return void
	 */
	public function testARefusedRoomFailsSoft(): void {
		$this->talkLinks->method('isTalkAvailable')->willReturn(true);
		$this->talkLinks->method('getLinkedRooms')->willReturn([]);
		$this->talkLinks->method('createAndLinkRoom')->willThrowException(new \Exception('No user logged in'));
		$this->objects->expects(self::never())->method('findAll');

		$this->provisioner()->handle($this->event('cohort', 'active', ['id' => 'cohort-1', 'name' => 'Klas 1A']));
		$this->addToAssertionCount(1);
	}//end testARefusedRoomFailsSoft()

	/**
	 * Other transitions, other schemas, other events and a nameless cohort
	 * create nothing.
	 *
	 * @return void
	 */
	public function testOnlyANamedCohortBecomingActiveCounts(): void {
		$this->talkLinks->method('isTalkAvailable')->willReturn(true);
		$this->talkLinks->method('getLinkedRooms')->willReturn([]);
		$this->talkLinks->expects(self::never())->method('createAndLinkRoom');

		$provisioner = $this->provisioner();
		$provisioner->handle($this->event('cohort', 'completed', ['id' => 'cohort-1', 'name' => 'Klas 1A']));
		$provisioner->handle($this->event('enrolment', 'active', ['id' => 'enr-1', 'name' => 'Klas 1A']));
		$provisioner->handle($this->event('cohort', 'active', ['id' => 'cohort-1', 'name' => '  ']));
		$provisioner->handle($this->createMock(Event::class));
	}//end testOnlyANamedCohortBecomingActiveCounts()
}//end class
