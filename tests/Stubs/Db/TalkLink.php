<?php

/**
 * Test stub for OCA\OpenRegister\Db\TalkLink.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * A link row between an object and a Talk room; only the room token is read
 * by learniq.
 */
class TalkLink {
	/**
	 * Constructor.
	 *
	 * @param string $roomToken The linked room's token.
	 */
	public function __construct(
		private readonly string $roomToken = '',
	) {
	}//end __construct()

	/**
	 * The linked room's token.
	 *
	 * @return string
	 */
	public function getRoomToken(): string {
		return $this->roomToken;
	}//end getRoomToken()
}//end class
