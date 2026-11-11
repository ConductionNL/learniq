<?php

/**
 * EntityRow: an OpenRegister result, array or entity, as an array row.
 *
 * `ObjectService::findAll()` hands back arrays or ObjectEntity instances.
 * Several readers take either as a row: an array stays as it is, and an
 * entity becomes its serialised data.
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
 * Turns an array or an entity into an array row.
 *
 * @spec exclude shared conversion helper: it carries the row reading of the callers' own specs and adds no behaviour of its own
 */
final class EntityRow {

	/**
	 * The row: an array as is, an entity as its serialised data.
	 *
	 * @param mixed $row An array or an entity.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude shared conversion helper: it carries the row reading of the callers' own specs and adds no behaviour of its own
	 */
	public function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		return $row->jsonSerialize();
	}//end asArray()
}//end class
