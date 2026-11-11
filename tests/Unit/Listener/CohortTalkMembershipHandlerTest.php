<?php

/**
 * Learniq CohortTalkMembershipHandler unit tests.
 *
 * Covers the `talk-classroom-spaces` Enrolment activate/withdraw -> Cohort
 * Talk conversation participant sync bridge: a learner is added when their
 * Enrolment activates and a room is linked, removed when it withdraws, and
 * both paths no-op (no participant-service call attempted) when Talk is
 * unavailable or the Cohort has no linked conversation yet.
 *
 * Uses the `OCA\Talk\Manager`/`OCA\Talk\Room`/`OCA\Talk\Service\ParticipantService`
 * stubs under `tests/Stubs/Talk/` (mirroring hermiq's
 * `tests/Stubs/Talk/*` pattern) so the handler's DI-container-resolved Talk
 * calls can be mocked with plain PHPUnit `createMock()` against real class
 * shapes, instead of hand-rolled duck-typed fakes.
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
 * @spec openspec/specs/school-structure/spec.md#requirement-enrolled-learners-sync-as-talk-room-participants-on-cohort-membership-changes
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\TalkLinkService;
use OCA\Learniq\Listener\CohortTalkMembershipHandler;
use OCA\Talk\Manager as TalkManager;
use OCA\Talk\Room as TalkRoom;
use OCA\Talk\Service\ParticipantService as TalkParticipantService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../Support/SlugMapper.php';
require_once __DIR__ . '/../../Support/TransitionScope.php';

/**
 * Tests for CohortTalkMembershipHandler::handle().
 */
class CohortTalkMembershipHandlerTest extends TestCase {
	/**
	 * Build a container mock that resolves `OCA\Talk\Manager` /
	 * `OCA\Talk\Service\ParticipantService` to the given mocks, throwing for
	 * any other lookup (and for either when its mock is null — simulating
	 * Talk's classes being entirely absent from the container).
	 *
	 * @param TalkManager|null $manager Resolved for the Manager FQCN.
	 * @param TalkParticipantService|null $participantService Resolved for the ParticipantService FQCN.
	 *
	 * @return ContainerInterface
	 */
	private function makeContainer(?TalkManager $manager, ?TalkParticipantService $participantService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($manager, $participantService) {
				if ($id === 'OCA\\Talk\\Manager') {
					if ($manager === null) {
						throw new \RuntimeException('Talk Manager not available');
					}

					return $manager;
				}

				if ($id === 'OCA\\Talk\\Service\\ParticipantService') {
					if ($participantService === null) {
						throw new \RuntimeException('Talk ParticipantService not available');
					}

					return $participantService;
				}

				throw new \RuntimeException('Unexpected container lookup: ' . $id);
			}
		);

		return $container;
	}//end makeContainer()

	/**
	 * Build a mocked ObjectTransitionedEvent for an Enrolment transition.
	 *
	 * @param string $action Transition action ('activate'|'withdraw'|...).
	 * @param array<string,mixed> $enrolmentData The Enrolment's jsonSerialize() payload.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function makeEvent(string $action, array $enrolmentData): ObjectTransitionedEvent {
		$objectEntity = $this->createMock(ObjectEntity::class);
		$objectEntity->method('jsonSerialize')->willReturn($enrolmentData);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('enrolment');
		$event->method('getAction')->willReturn($action);

		return $event;
	}//end makeEvent()

	/**
	 * Activating an Enrolment adds the learner as a participant of every
	 * Talk conversation linked to the Cohort.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/school-structure/spec.md#scenario-activating-an-enrolment-adds-the-learner-to-the-cohorts-linked-conversation
	 */
	public function testActivateAddsParticipant(): void {
		// ⚠️ A MOCK, not `new TalkRoom()`. The room is only ever an identity
		// token here — handed to a mocked manager, matched by a mocked
		// participant service, never called. But `new TalkRoom()` binds the test
		// to whether Talk is INSTALLED: with the stub it constructs, with the
		// real `OCA\Talk\Room` it needs 33 arguments and the test errors. That
		// is why this passed CI (no Talk) and failed on a dev instance (Talk
		// present) — a test nobody can run locally is a test nobody runs.
		$room = $this->createMock(TalkRoom::class);

		$manager = $this->createMock(TalkManager::class);
		$manager->method('getRoomByToken')->with('room-token-1')->willReturn($room);

		$participantService = $this->createMock(TalkParticipantService::class);
		$participantService->expects(self::once())
			->method('addUsers')
			->with($room, [['actorType' => 'users', 'actorId' => 'learner-1']]);
		$participantService->expects(self::never())->method('removeUser');

		$talkLinkService = $this->createMock(TalkLinkService::class);
		$talkLinkService->method('isTalkAvailable')->willReturn(true);
		$talkLinkService->method('getLinkedRooms')->with('cohort-1')->willReturn([['roomToken' => 'room-token-1']]);

		$handler = new CohortTalkMembershipHandler(
			$talkLinkService,
			$this->makeContainer($manager, $participantService),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			\OCA\Learniq\Tests\Support\TransitionScope::resolver()
		);

		$handler->handle($this->makeEvent('activate', ['cohortId' => 'cohort-1', 'learnerId' => 'learner-1']));

	}//end testActivateAddsParticipant()

	/**
	 * Withdrawing an Enrolment removes the learner as a participant of every
	 * Talk conversation linked to the Cohort.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/school-structure/spec.md#scenario-withdrawing-an-enrolment-removes-the-learner-from-the-cohorts-linked-conversation
	 */
	public function testWithdrawRemovesParticipant(): void {
		// A mock for the same reason as above: identity only, and it must not
		// depend on whether Talk is installed.
		$room = $this->createMock(TalkRoom::class);
		$user = $this->createMock(IUser::class);

		$manager = $this->createMock(TalkManager::class);
		$manager->method('getRoomByToken')->with('room-token-1')->willReturn($room);

		$participantService = $this->createMock(TalkParticipantService::class);
		$participantService->expects(self::once())
			->method('removeUser')
			->with($room, $user, 'leave');
		$participantService->expects(self::never())->method('addUsers');

		$talkLinkService = $this->createMock(TalkLinkService::class);
		$talkLinkService->method('isTalkAvailable')->willReturn(true);
		$talkLinkService->method('getLinkedRooms')->with('cohort-1')->willReturn([['roomToken' => 'room-token-1']]);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('learner-1')->willReturn($user);

		$handler = new CohortTalkMembershipHandler(
			$talkLinkService,
			$this->makeContainer($manager, $participantService),
			$userManager,
			$this->createMock(LoggerInterface::class),
			\OCA\Learniq\Tests\Support\TransitionScope::resolver()
		);

		$handler->handle($this->makeEvent('withdraw', ['cohortId' => 'cohort-1', 'learnerId' => 'learner-1']));

	}//end testWithdrawRemovesParticipant()

	/**
	 * A Cohort with no linked Talk conversation is a no-op — no
	 * participant-sync call is attempted (the container is never even asked
	 * to resolve Talk's Manager/ParticipantService).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/school-structure/spec.md#scenario-no-conversation-linked-yet-is-a-no-op-not-an-error
	 */
	public function testActivateWithNoLinkedRoomIsNoop(): void {
		$talkLinkService = $this->createMock(TalkLinkService::class);
		$talkLinkService->method('isTalkAvailable')->willReturn(true);
		$talkLinkService->method('getLinkedRooms')->willReturn([]);

		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');

		$handler = new CohortTalkMembershipHandler(
			$talkLinkService,
			$container,
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			\OCA\Learniq\Tests\Support\TransitionScope::resolver()
		);

		$handler->handle($this->makeEvent('activate', ['cohortId' => 'cohort-1', 'learnerId' => 'learner-1']));

	}//end testActivateWithNoLinkedRoomIsNoop()

	/**
	 * Talk being unavailable is a no-op — no participant-sync call is
	 * attempted, even when the Cohort has a (stale) linked conversation
	 * record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/school-structure/spec.md#scenario-talk-unavailable-is-a-no-op-not-an-error
	 */
	public function testActivateWithTalkUnavailableIsNoop(): void {
		$talkLinkService = $this->createMock(TalkLinkService::class);
		$talkLinkService->method('isTalkAvailable')->willReturn(false);
		// isTalkAvailable() is checked first — getLinkedRooms() must never be
		// reached, proving the fail-soft guard short-circuits before any
		// further lookup.
		$talkLinkService->expects(self::never())->method('getLinkedRooms');

		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');

		$handler = new CohortTalkMembershipHandler(
			$talkLinkService,
			$container,
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			\OCA\Learniq\Tests\Support\TransitionScope::resolver()
		);

		$handler->handle($this->makeEvent('activate', ['cohortId' => 'cohort-1', 'learnerId' => 'learner-1']));

	}//end testActivateWithTalkUnavailableIsNoop()

	/**
	 * An Enrolment with no `cohortId` (individually enrolled learner) is a
	 * fast no-op regardless of action.
	 *
	 * @return void
	 */
	public function testActivateWithNoCohortIdIsNoop(): void {
		$talkLinkService = $this->createMock(TalkLinkService::class);
		$talkLinkService->expects(self::never())->method('isTalkAvailable');
		$talkLinkService->expects(self::never())->method('getLinkedRooms');

		$handler = new CohortTalkMembershipHandler(
			$talkLinkService,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			\OCA\Learniq\Tests\Support\TransitionScope::resolver()
		);

		$handler->handle($this->makeEvent('activate', ['cohortId' => null, 'learnerId' => 'learner-1']));

	}//end testActivateWithNoCohortIdIsNoop()

	/**
	 * A transition action other than activate/withdraw (e.g. `complete`) is
	 * a fast no-op.
	 *
	 * @return void
	 */
	public function testNonActivateWithdrawActionIsNoop(): void {
		$talkLinkService = $this->createMock(TalkLinkService::class);
		$talkLinkService->expects(self::never())->method('isTalkAvailable');

		$handler = new CohortTalkMembershipHandler(
			$talkLinkService,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			\OCA\Learniq\Tests\Support\TransitionScope::resolver()
		);

		$handler->handle($this->makeEvent('complete', ['cohortId' => 'cohort-1', 'learnerId' => 'learner-1']));

	}//end testNonActivateWithdrawActionIsNoop()
	/**
	 * Build a handler whose Talk link service, manager and participant service record every call.
	 *
	 * @param array<int,array<string,mixed>> $links The links getLinkedRooms() answers.
	 * @param array<int,string>              $calls Receives the recorded calls, in order.
	 *
	 * @return CohortTalkMembershipHandler
	 */
	private function recordingHandler(array $links, array &$calls): CohortTalkMembershipHandler {
		$talkLinkService = $this->createMock(TalkLinkService::class);
		$talkLinkService->method('isTalkAvailable')->willReturnCallback(
			function () use (&$calls): bool {
				$calls[] = 'available';
				return true;
			}
		);
		$talkLinkService->method('getLinkedRooms')->willReturnCallback(
			function (string $cohort) use (&$calls, $links): array {
				$calls[] = 'links:' . $cohort;
				return $links;
			}
		);

		$rooms   = [];
		$manager = $this->createMock(TalkManager::class);
		$manager->method('getRoomByToken')->willReturnCallback(
			function (string $token) use (&$calls, &$rooms): TalkRoom {
				$calls[] = 'find:' . $token;
				if ($token === 't-missing') {
					throw new \RuntimeException('no room');
				}

				$rooms[$token] = ($rooms[$token] ?? $this->createMock(TalkRoom::class));
				return $rooms[$token];
			}
		);

		$participantService = $this->createMock(TalkParticipantService::class);
		$participantService->method('addUsers')->willReturnCallback(
			function (TalkRoom $room, array $users) use (&$calls, &$rooms): void {
				$calls[] = 'add:' . array_search($room, $rooms, true) . ':' . $users[0]['actorId'];
			}
		);

		return new CohortTalkMembershipHandler(
			$talkLinkService,
			$this->makeContainer($manager, $participantService),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			\OCA\Learniq\Tests\Support\TransitionScope::resolver()
		);
	}//end recordingHandler()

	/**
	 * Each linked room is found and synced in turn; links without a usable
	 * token, and rooms Talk cannot find, are skipped without stopping the rest.
	 *
	 * @return void
	 */
	public function testEachLinkedRoomIsSyncedInTurnAndUnusableLinksAreSkipped(): void {
		$calls   = [];
		$handler = $this->recordingHandler(
			links: [['roomToken' => 't-a'], ['roomToken' => ''], ['roomToken' => 5], [], ['roomToken' => 't-missing'], ['roomToken' => 't-b']],
			calls: $calls
		);

		$handler->handle($this->makeEvent('activate', ['cohortId' => 'cohort-1', 'learnerId' => 'learner-1']));

		self::assertSame(
			['available', 'links:cohort-1', 'find:t-a', 'add:t-a:learner-1', 'find:t-missing', 'find:t-b', 'add:t-b:learner-1'],
			$calls
		);
	}//end testEachLinkedRoomIsSyncedInTurnAndUnusableLinksAreSkipped()

	/**
	 * Only learniq enrolment transitions with a cohort and a learner reach Talk.
	 *
	 * @return void
	 */
	public function testOtherObjectsAndMalformedEnrolmentsNeverReachTalk(): void {
		$calls   = [];
		$handler = $this->recordingHandler(links: [['roomToken' => 't-a']], calls: $calls);

		$otherSchema = $this->makeEvent('activate', ['cohortId' => 'cohort-1', 'learnerId' => 'learner-1']);
		$otherSchemaEvent = $this->createMock(ObjectTransitionedEvent::class);
		$otherSchemaEvent->method('getObject')->willReturn($otherSchema->getObject());
		$otherSchemaEvent->method('getRegister')->willReturn('learniq');
		$otherSchemaEvent->method('getSchema')->willReturn('cohort');
		$otherSchemaEvent->method('getAction')->willReturn('activate');

		$otherRegisterEvent = $this->createMock(ObjectTransitionedEvent::class);
		$otherRegisterEvent->method('getObject')->willReturn($otherSchema->getObject());
		$otherRegisterEvent->method('getRegister')->willReturn(\OCA\Learniq\Tests\Support\TransitionScope::OTHER_REGISTER_ID);
		$otherRegisterEvent->method('getSchema')->willReturn('enrolment');
		$otherRegisterEvent->method('getAction')->willReturn('activate');

		$handler->handle(new \OCP\EventDispatcher\Event());
		$handler->handle($otherSchemaEvent);
		$handler->handle($otherRegisterEvent);
		$handler->handle($this->makeEvent('withdraw', ['cohortId' => 42, 'learnerId' => 'learner-1']));
		$handler->handle($this->makeEvent('withdraw', ['cohortId' => '', 'learnerId' => 'learner-1']));
		$handler->handle($this->makeEvent('activate', ['cohortId' => 'cohort-1', 'learnerId' => '']));
		$handler->handle($this->makeEvent('activate', ['cohortId' => 'cohort-1']));

		self::assertSame([], $calls);
	}//end testOtherObjectsAndMalformedEnrolmentsNeverReachTalk()

	/**
	 * Each fail-soft stop logs its own reason with the learner and cohort.
	 *
	 * @return void
	 */
	public function testEachSkipLogsItsReason(): void {
		$cases = [
			'talk unavailable' => [false, [['roomToken' => 't-a']], '[CohortTalkMembershipHandler] Talk (spreed) is not installed/enabled — skipping participant sync for learner {learner} on Cohort {cohort}.'],
			'no linked room' => [true, [], '[CohortTalkMembershipHandler] Cohort {cohort} has no linked Talk conversation yet — skipping participant sync for learner {learner}.'],
			'no manager' => [true, [['roomToken' => 't-a']], '[CohortTalkMembershipHandler] Talk Manager could not be resolved — skipping participant sync for learner {learner} on Cohort {cohort}.'],
		];

		foreach ($cases as $label => [$available, $links, $message]) {
			$talkLinkService = $this->createMock(TalkLinkService::class);
			$talkLinkService->method('isTalkAvailable')->willReturn($available);
			$talkLinkService->method('getLinkedRooms')->willReturn($links);

			$logged = [];
			$logger = $this->createMock(LoggerInterface::class);
			$logger->method('info')->willReturnCallback(
				function (string $text, array $context) use (&$logged): void {
					$logged[] = [$text, $context];
				}
			);

			$handler = new CohortTalkMembershipHandler(
				$talkLinkService,
				$this->makeContainer(null, null),
				$this->createMock(IUserManager::class),
				$logger,
				\OCA\Learniq\Tests\Support\TransitionScope::resolver()
			);
			$handler->handle($this->makeEvent('withdraw', ['cohortId' => 'cohort-1', 'learnerId' => 'learner-1']));

			self::assertCount(1, $logged, $label);
			self::assertSame($message, $logged[0][0], $label);
			self::assertEqualsCanonicalizing(['learner' => 'learner-1', 'cohort' => 'cohort-1'], $logged[0][1], $label);
		}
	}//end testEachSkipLogsItsReason()
}//end class
