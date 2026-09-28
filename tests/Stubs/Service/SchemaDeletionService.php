<?php

/**
 * Test stub for OCA\OpenRegister\Service\SchemaDeletionService.
 *
 * Mirrors the two calls learniq's retired-schema prune makes, with
 * OpenRegister's signatures. Doubles configure them; the bodies are never run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;

/**
 * Mirror of OpenRegister's SchemaDeletionService for standalone Learniq unit tests.
 */
class SchemaDeletionService {

	/**
	 * The number of objects a cascade delete of the schema would remove.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return int
	 */
	public function countObjectsCascadeWouldDelete(Schema $schema): int {
		return 0;
	}//end countObjectsCascadeWouldDelete()

	/**
	 * Delete the schema, its objects and its table.
	 *
	 * @param Schema $schema           The schema.
	 * @param bool   $archivalOverride Destroy a legally retained schema too.
	 *
	 * @return array{deletedCount: int, deletedUuids: array<int, string>, tableDropped: bool}
	 */
	public function cascadeDeleteSchema(Schema $schema, bool $archivalOverride = false): array {
		return ['deletedCount' => 0, 'deletedUuids' => [], 'tableDropped' => false];
	}//end cascadeDeleteSchema()
}//end class
