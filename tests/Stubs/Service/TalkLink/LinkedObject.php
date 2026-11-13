<?php

/**
 * Test stub for OCA\OpenRegister\Service\TalkLink\LinkedObject.
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
 * The object a Talk room is linked to: its uuid, register id and schema id.
 * Same constructor as OpenRegister's value object.
 */
final class LinkedObject {
	/**
	 * Constructor.
	 *
	 * @param string $objectUuid The object's uuid.
	 * @param int    $registerId The object's register id.
	 * @param int    $schemaId   The object's schema id.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly int $registerId,
		public readonly int $schemaId,
	) {
	}//end __construct()
}//end class
