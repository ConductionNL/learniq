<?php

/**
 * ObjectRow: an OpenRegister result row as a plain array.
 *
 * `ObjectService::findAll()` hands back arrays or serialisable entities.
 * Many readers turn one such result into an array the same way: an array
 * stays as it is, a serialisable object becomes its serialised data, and
 * anything else reads as an empty row.
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
 * Turns one OpenRegister result into a plain array.
 *
 * @spec exclude shared conversion helper: it carries the row reading of the callers' own specs and adds no behaviour of its own
 */
final class ObjectRow {

	/**
	 * The result as an array: an array as is, a serialisable object as its
	 * serialised data, anything else as an empty array.
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
			return (array)$object->jsonSerialize();
		}

		return [];
	}//end asArray()
}//end class
