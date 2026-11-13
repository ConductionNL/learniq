<?php

/**
 * Learniq Cohort Talk Room Provisioner
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Listens for OpenRegister's ObjectTransitionedEvent and gives a Cohort that
 * becomes `active` with no Talk conversation linked its own one: a group
 * conversation named after the cohort, created and linked through
 * OpenRegister's `TalkLinkService::createAndLinkRoom()`, with the cohort's
 * active learners added as participants. From then on
 * `CohortTalkMembershipHandler` keeps the participant list in step with the
 * cohort's Enrolments.
 *
 * Fails soft in every degraded case (Talk not installed or not enabled, the
 * cohort already has a conversation, no user session to create the room for,
 * Talk refusing the room): logs and returns, never throws, never blocks the
 * transition itself, which OpenRegister has already committed. A coordinator
 * can still link a room by hand on the Cohort detail page.
 *
 * ADR-031 legitimate exception: event-to-external-API bridge (Cohort
 * activation -> a Talk conversation), not expressible as a schema declaration.
 * Same category as `CohortTalkMembershipHandler` and
 * `CohortGroupProvisioningHandler`.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/school-structure/spec.md#requirement-a-new-cohort-gets-its-own-talk-room-automatically
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Support\SerialisedRow;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TalkLink\LinkedObject;
use OCA\OpenRegister\Service\TalkLink\RoomCreation;
use OCA\OpenRegister\Service\TalkLinkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bridges Cohort activation -> its own linked Talk conversation.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/school-structure/spec.md#requirement-a-new-cohort-gets-its-own-talk-room-automatically
 */
class CohortTalkRoomProvisioner implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const COHORT_SCHEMA = 'cohort';
	private const ENROLMENT_SCHEMA = 'enrolment';
	private const ACTIVE = 'active';
	private const GROUP_ROOM = 2;
	private const TALK_MANAGER_FQCN = 'OCA\\Talk\\Manager';
	private const PARTICIPANT_SERVICE_FQCN = 'OCA\\Talk\\Service\\ParticipantService';

	/**
	 * Constructor.
	 *
	 * @param TalkLinkService                 $talkLinkService OpenRegister's Talk-linking abstraction.
	 * @param ObjectService                   $objectService   OpenRegister object access, for the cohort's active Enrolments.
	 * @param ContainerInterface              $container       DI container used to late-bind Talk's Manager and
	 *                                                         ParticipantService (they exist only when Talk is installed).
	 * @param LoggerInterface                 $logger          PSR logger.
	 * @param ListenerSchemaResolverInterface $schemas         Resolves the transition event's register and schema ids to slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TalkLinkService $talkLinkService,
		private readonly ObjectService $objectService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ListenerSchemaResolverInterface $schemas,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/school-structure/spec.md#scenario-a-coordinator-activates-a-new-cohort
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false || $this->isCohortActivation(event: $event) === false) {
			return;
		}

		$creation = $this->roomCreation(event: $event);
		if ($creation === null || $this->needsRoom(cohortId: $creation->object->objectUuid) === false) {
			return;
		}

		$roomToken = $this->createRoom(creation: $creation);
		if ($roomToken === null) {
			return;
		}

		$this->addActiveLearners(cohortId: $creation->object->objectUuid, roomToken: $roomToken);

	}//end handle()

	/**
	 * Whether the event moves a learniq Cohort to `active`.
	 *
	 * @param ObjectTransitionedEvent $event The transition.
	 *
	 * @return bool
	 */
	private function isCohortActivation(ObjectTransitionedEvent $event): bool {
		return $event->getTo() === self::ACTIVE
			&& $this->schemas->eventRegister(event: $event) === self::LEARNIQ_REGISTER
			&& $this->schemas->eventSchema(event: $event) === self::COHORT_SCHEMA;
	}//end isCohortActivation()

	/**
	 * The room to create for the activated cohort, or null when the cohort
	 * has no uuid or name, or the event names no numeric register and schema.
	 *
	 * @param ObjectTransitionedEvent $event The activation.
	 *
	 * @return RoomCreation|null
	 */
	private function roomCreation(ObjectTransitionedEvent $event): ?RoomCreation {
		$entity = $event->getObject();
		$cohort = $entity->jsonSerialize();
		$cohortId = $entity->getUuid() ?? ($cohort['id'] ?? null);
		$name = $cohort['name'] ?? null;
		$registerId = $event->getRegister();
		$schemaId = $event->getSchema();

		if (is_string($cohortId) === false || $cohortId === ''
			|| is_string($name) === false || trim($name) === ''
			|| ctype_digit($registerId) === false || ctype_digit($schemaId) === false
		) {
			$this->logger->info('[CohortTalkRoomProvisioner] Activated cohort lacks a uuid, a name or a numeric register/schema; no Talk room created.');
			return null;
		}

		return new RoomCreation(
			object: new LinkedObject(objectUuid: $cohortId, registerId: (int)$registerId, schemaId: (int)$schemaId),
			roomName: trim($name),
			description: null,
			roomType: self::GROUP_ROOM
		);
	}//end roomCreation()

	/**
	 * Whether the cohort should get a room: Talk is available and no
	 * conversation is linked to it yet.
	 *
	 * @param string $cohortId The cohort uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/school-structure/spec.md#scenario-a-cohort-that-already-has-a-room
	 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/school-structure/spec.md#scenario-talk-is-not-available
	 */
	private function needsRoom(string $cohortId): bool {
		if ($this->talkLinkService->isTalkAvailable() === false) {
			$this->logger->info(
				'[CohortTalkRoomProvisioner] Talk is not installed or enabled; no room created for Cohort {cohort}.',
				['cohort' => $cohortId]
			);
			return false;
		}

		return empty($this->talkLinkService->getLinkedRooms($cohortId)) === true;
	}//end needsRoom()

	/**
	 * Create and link the room; its token, or null (logged) when Talk or
	 * OpenRegister refuses.
	 *
	 * @param RoomCreation $creation The room to create.
	 *
	 * @return string|null
	 */
	private function createRoom(RoomCreation $creation): ?string {
		try {
			$roomToken = $this->talkLinkService->createAndLinkRoom($creation)->getRoomToken();
		} catch (Throwable $e) {
			$this->logger->warning(
				'[CohortTalkRoomProvisioner] Could not create a Talk room for Cohort {cohort}: {msg}',
				['cohort' => $creation->object->objectUuid, 'msg' => $e->getMessage()]
			);
			return null;
		}

		if (is_string($roomToken) === false || $roomToken === '') {
			return null;
		}

		return $roomToken;
	}//end createRoom()

	/**
	 * Add the cohort's active learners to the new room. Fails soft.
	 *
	 * @param string $cohortId  The cohort uuid.
	 * @param string $roomToken The new room's token.
	 *
	 * @return void
	 */
	private function addActiveLearners(string $cohortId, string $roomToken): void {
		$learnerIds = $this->activeLearnerIds(cohortId: $cohortId);
		if ($learnerIds === []) {
			return;
		}

		try {
			$room = $this->container->get(self::TALK_MANAGER_FQCN)->getRoomByToken($roomToken);
			$participants = array_map(
				static fn (string $learnerId): array => ['actorType' => 'users', 'actorId' => $learnerId],
				$learnerIds
			);
			$this->container->get(self::PARTICIPANT_SERVICE_FQCN)->addUsers($room, $participants);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[CohortTalkRoomProvisioner] Could not add the learners of Cohort {cohort} to its new Talk room: {msg}',
				['cohort' => $cohortId, 'msg' => $e->getMessage()]
			);
		}

	}//end addActiveLearners()

	/**
	 * The user ids of the cohort's active Enrolments, each once.
	 *
	 * @param string $cohortId The cohort uuid.
	 *
	 * @return list<string>
	 */
	private function activeLearnerIds(string $cohortId): array {
		$enrolments = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::ENROLMENT_SCHEMA,
					'cohortId' => $cohortId,
					'lifecycle' => self::ACTIVE,
				],
			]
		);

		$ids = [];
		foreach ($enrolments as $enrolment) {
			$learnerId = (new SerialisedRow())->asArray(object: $enrolment)['learnerId'] ?? null;
			if (is_string($learnerId) === true && $learnerId !== '') {
				$ids[] = $learnerId;
			}
		}

		return array_values(array_unique($ids));
	}//end activeLearnerIds()
}//end class
