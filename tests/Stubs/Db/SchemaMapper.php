<?php

/**
 * Test stub for OCA\OpenRegister\Db\SchemaMapper.
 *
 * Mirrors the one lookup learniq's retired-schema prune calls, with
 * OpenRegister's signature. Doubles configure it; the body is never run.
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
 * Mirror of OpenRegister's SchemaMapper for standalone Learniq unit tests.
 */
class SchemaMapper {

	/**
	 * Every schema row under (application, slug), oldest id first.
	 *
	 * @param string $slug        The schema slug.
	 * @param string $application The owning application id.
	 *
	 * @return array<int, Schema>
	 */
	public function findAllByApplicationAndSlug(string $slug, string $application): array {
		return [];
	}//end findAllByApplicationAndSlug()
}//end class
