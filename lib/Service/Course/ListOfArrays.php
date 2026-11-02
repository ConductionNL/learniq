<?php

/**
 * Learniq list-of-arrays helper.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Course
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Course;

/**
 * Reads a stored list value as a list of rows.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */
trait ListOfArrays {

	/**
	 * The array entries of a list value, re-indexed; anything else is an empty list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, 'is_array'));
	}//end listOf()
}//end trait
