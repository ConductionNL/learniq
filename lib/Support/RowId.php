<?php

/**
 * RowId: the id of an OpenRegister row.
 *
 * A row names itself by `id`, by `uuid`, or only in its `@self` block.
 * Several readers take the first of those that is set, and only a string.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Learniq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared conversion helper: it carries the row reading of the callers' own specs and adds no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Learniq\Support;

/**
 * Reads a row's id.
 *
 * @spec exclude shared conversion helper: it carries the row reading of the callers' own specs and adds no behaviour of its own
 */
final class RowId {

	/**
	 * The row's id, else its uuid, else its `@self.id` when neither is set;
	 * '' when that is not a string.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec exclude shared conversion helper: it carries the row reading of the callers' own specs and adds no behaviour of its own
	 */
	public function idOf(array $row): string {
		$id = $row['id'] ?? $row['uuid'] ?? null;
		if ($id === null && is_array($row['@self'] ?? null) === true) {
			$id = $row['@self']['id'] ?? null;
		}

		if (is_string($id) === false) {
			return '';
		}

		return $id;
	}//end idOf()
}//end class
