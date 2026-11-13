<?php

/**
 * Test stub for OCA\OpenRegister\Service\TalkLink\RoomCreation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TalkLink;

/**
 * A request to create a Talk room linked to an object. Same constructor as
 * OpenRegister's value object.
 */
final class RoomCreation {
	/**
	 * Constructor.
	 *
	 * @param LinkedObject $object      The object the room is linked to.
	 * @param string       $roomName    The room's name.
	 * @param string|null  $description The room's description.
	 * @param int          $roomType    2 = group (default), 3 = public.
	 */
	public function __construct(
		public readonly LinkedObject $object,
		public readonly string $roomName,
		public readonly ?string $description,
		public readonly int $roomType = 2,
	) {
	}//end __construct()
}//end class
