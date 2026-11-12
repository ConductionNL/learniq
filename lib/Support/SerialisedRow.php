<?php

/**
 * SerialisedRow: an OpenRegister result as an array row, strictly.
 *
 * `ObjectService::findAll()` hands back arrays or serialisable entities.
 * Several readers accept a result as a row only when it is an array or
 * serialises to one: an array stays as it is, a serialisable object whose
 * data is an array becomes that data, and anything else reads as an empty
 * row.
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
 * Turns one OpenRegister result into an array row, or an empty one.
 *
 * @spec exclude shared conversion helper: it carries the row reading of the callers' own specs and adds no behaviour of its own
 */
final class SerialisedRow {

	/**
	 * The result as an array: an array as is, a serialisable object's data
	 * when that is an array, anything else as an empty array.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude shared conversion helper: it carries the row reading of the callers' own specs and adds no behaviour of its own
	 */
	public function asArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$row = $object->jsonSerialize();
			if (is_array($row) === true) {
				return $row;
			}
		}

		return [];
	}//end asArray()
}//end class
