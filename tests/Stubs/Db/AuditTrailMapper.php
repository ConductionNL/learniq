<?php

/**
 * Test stub for OCA\OpenRegister\Db\AuditTrailMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for AuditTrailMapper.
 */
abstract class AuditTrailMapper {
	/**
	 * Same parameter order as OpenRegister's real AuditTrailMapper::findAll():
	 * a double's callback receives positional arguments in THIS order, so a
	 * stub that put `$filters` first passed locally and broke against the real
	 * class in CI.
	 *
	 * @param int|null $limit
	 * @param int|null $offset
	 * @param array<string,mixed>|null $filters
	 * @param array<string,mixed>|null $sort
	 * @param string|null $search
	 * @return array<int,mixed>
	 */
	abstract public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $sort = ['created' => 'DESC'],
		?string $search = null,
	): array;
}//end class
