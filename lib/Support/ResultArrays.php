<?php

/**
 * ResultArrays: a findAll() result list as array rows.
 *
 * `ObjectService::findAll()` answers arrays or serialisable entities. Several
 * readers keep the arrays, turn the serialisable objects into their data and
 * drop anything else.
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
 * Turns a result list into a list of arrays.
 *
 * @spec exclude shared conversion helper: it carries the row reading of the callers' own specs and adds no behaviour of its own
 */
final class ResultArrays {

	/**
	 * The arrays among the results, serialisable objects as their data, in
	 * order and renumbered; anything else is left out.
	 *
	 * @param array<int|string, mixed> $objects The findAll() result.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec exclude shared conversion helper: it carries the row reading of the callers' own specs and adds no behaviour of its own
	 */
	public function arraysOf(array $objects): array {
		$rows = [];
		foreach ($objects as $object) {
			if (is_array($object) === true) {
				$rows[] = $object;
				continue;
			}

			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$rows[] = (array)$object->jsonSerialize();
			}
		}

		return $rows;
	}//end arraysOf()
}//end class
