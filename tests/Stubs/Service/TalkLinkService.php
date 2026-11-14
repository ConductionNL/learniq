<?php

/**
 * Test stub for OCA\OpenRegister\Service\TalkLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal TalkLinkService stub for Learniq unit tests (CohortTalkMembershipHandler,
 * CohortTalkRoomProvisioner).
 *
 * Declares only the methods learniq consumes: `isTalkAvailable()`,
 * `getLinkedRooms()` and `createAndLinkRoom()`. The real class exposes a
 * larger Tier-2 REST surface (linkRoom, unlinkRoom,
 * getAvailableRoomsForUser) that learniq does not call directly — it is
 * reached only via OpenRegister's own `TalkLinksController` from the
 * frontend `integration`/`talk` manifest widgets.
 */
abstract class TalkLinkService {
	/**
	 * Whether NC Talk (spreed) is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	abstract public function isTalkAvailable(): bool;

	/**
	 * Return the Talk conversations currently linked to an OR object.
	 *
	 * Each row carries at least `roomToken` (the real class also caches
	 * `roomName`, `roomType`, `subtitle`, `participantCount`,
	 * `lastMessageData`, `lastActivity`, `linkedBy`, `linkedAt`).
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	abstract public function getLinkedRooms(string $objectUuid): array;

	/**
	 * Create a Talk room and link it to an object.
	 *
	 * @param \OCA\OpenRegister\Service\TalkLink\RoomCreation $creation The object, room name, description and room type.
	 *
	 * @return \OCA\OpenRegister\Db\TalkLink The inserted link row.
	 */
	abstract public function createAndLinkRoom(\OCA\OpenRegister\Service\TalkLink\RoomCreation $creation): \OCA\OpenRegister\Db\TalkLink;
}//end class
