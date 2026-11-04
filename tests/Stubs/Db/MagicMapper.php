<?php

/**
 * Test stub for OCA\OpenRegister\Db\MagicMapper.
 *
 * Mirrors the one method learniq calls (openregister development 45bf4d47a0,
 * lib/Db/MagicMapper.php): `update()` with the real parameters. Where
 * OpenRegister is loaded, the real class wins and this file is never read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Stub for MagicMapper.
 */
class MagicMapper {

	/**
	 * Update an object row.
	 *
	 * @param Entity            $entity    Entity to update.
	 * @param Register|null     $register  Optional register for routing.
	 * @param Schema|null       $schema    Optional schema for routing.
	 * @param ObjectEntity|null $oldEntity Old entity for comparison.
	 *
	 * @return Entity
	 */
	public function update(Entity $entity, ?Register $register=null, ?Schema $schema=null, ?ObjectEntity $oldEntity=null): Entity {
		return $entity;
	}//end update()
}//end class
