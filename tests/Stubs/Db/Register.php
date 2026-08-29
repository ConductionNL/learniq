<?php

/**
 * Test stub for OCA\OpenRegister\Db\Register.
 *
 * Only exists so the mirrored `ObjectService` signatures can name the same
 * union types the real class names. Learniq never passes a Register instance —
 * it always passes the register slug as a string — so no surface is mirrored
 * beyond the class existing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Mirror of OpenRegister's Register entity for standalone Learniq unit tests.
 */
class Register extends Entity implements JsonSerializable {

	protected ?string $slug = null;

	/**
	 * Serialize the register.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return ['id' => $this->id, 'slug' => $this->slug];
	}//end jsonSerialize()

}//end class
