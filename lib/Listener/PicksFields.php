<?php

/**
 * Learniq field picking helper.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

/**
 * Copies a fixed list of fields from a computed result into a stored row.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
 */
trait PicksFields {

	/**
	 * The row's values for the given keys, in the order of the keys.
	 *
	 * @param array<string,mixed> $row  The row.
	 * @param list<string>        $keys The keys.
	 *
	 * @return array<string,mixed>
	 */
	private static function pick(array $row, array $keys): array {
		$picked = [];
		foreach ($keys as $key) {
			$picked[$key] = $row[$key];
		}

		return $picked;
	}//end pick()
}//end trait
