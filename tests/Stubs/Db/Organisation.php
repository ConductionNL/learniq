<?php

/**
 * Test stub for OCA\OpenRegister\Db\Organisation.
 *
 * Mirrors the uuid accessors of the real entity (openregister development
 * 45bf4d47a0, lib/Db/Organisation.php). Where OpenRegister is loaded, the real
 * class wins and this file is never read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Stub for Organisation.
 */
class Organisation extends Entity {

	/**
	 * The organisation uuid.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The organisation uuid.
	 *
	 * @return string|null
	 */
	public function getUuid(): ?string {
		return $this->uuid;
	}//end getUuid()

	/**
	 * Set the organisation uuid.
	 *
	 * @param string|null $uuid The uuid.
	 *
	 * @return void
	 */
	public function setUuid(?string $uuid): void {
		$this->uuid = $uuid;
	}//end setUuid()
}//end class
