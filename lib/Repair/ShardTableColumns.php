<?php

/**
 * Learniq shard table and its columns, for RenameDutchColumns.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

/**
 * One OpenRegister shard table and the column names it carries, read once
 * per table while RenameDutchColumns moves its Dutch columns.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 */
final class ShardTableColumns {

	/**
	 * Constructor.
	 *
	 * @param string             $table   The shard table name.
	 * @param array<int, string> $columns The column names present in it.
	 */
	public function __construct(
		public readonly string $table,
		public readonly array $columns,
	) {
	}//end __construct()
}//end class
