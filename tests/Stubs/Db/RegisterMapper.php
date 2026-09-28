<?php

/**
 * Test stub for OCA\OpenRegister\Db\RegisterMapper.
 *
 * Mirrors the two calls learniq's retired-schema prune makes, with
 * OpenRegister's signatures. Doubles configure them; the bodies are never run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Mirror of OpenRegister's RegisterMapper for standalone Learniq unit tests.
 */
class RegisterMapper {

	/**
	 * Every register.
	 *
	 * @param int|null $limit            Page size.
	 * @param int|null $offset           Page offset.
	 * @param array|null $filters        Filters.
	 * @param array|null $searchConditions Search conditions.
	 * @param array|null $searchParams   Search parameters.
	 * @param bool $_rbac                Apply RBAC.
	 * @param bool $_multitenancy        Apply multitenancy.
	 *
	 * @return array<int, Register>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $searchConditions = [],
		?array $searchParams = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		return [];
	}//end findAll()

	/**
	 * Persist a register.
	 *
	 * @param Entity $entity The register.
	 *
	 * @return Entity
	 */
	public function update(Entity $entity): Entity {
		return $entity;
	}//end update()
}//end class
