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
	 * The schema ids the register lists (ints or numeric strings, per import era).
	 *
	 * @var array<int, mixed>|null
	 */
	protected ?array $schemas = [];

	/**
	 * The schema ids the register lists.
	 *
	 * @return array<int, mixed>
	 */
	public function getSchemas(): array {
		return ($this->schemas ?? []);
	}//end getSchemas()

	/**
	 * Replace the schema ids the register lists.
	 *
	 * @param array<int, mixed>|string $schemas Schema ids, or their JSON.
	 *
	 * @return static
	 */
	public function setSchemas($schemas): static {
		if (is_string($schemas) === true) {
			$schemas = (json_decode($schemas, true) ?? []);
		}

		$this->schemas = is_array($schemas) === true ? $schemas : [];
		$this->markFieldUpdated('schemas');

		return $this;
	}//end setSchemas()

	/**
	 * Serialize the register.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return ['id' => $this->id, 'slug' => $this->slug];
	}//end jsonSerialize()

}//end class
