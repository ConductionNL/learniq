<?php

/**
 * Learniq CourseDayRows
 *
 * The register reads the course day services share: one row by uuid, and the
 * rows of a schema whose fields equal a set of filters.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CourseDay
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseDay;

use OCA\Learniq\Support\SerialisedRow;

/**
 * Row reads over `$this->objectService` in the learniq register.
 *
 * The using class holds an `ObjectService $objectService` property.
 *
 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
 */
trait CourseDayRows {

	/**
	 * The row with this uuid, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
	 */
	private function one(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objects = $this->objectService->findAll(
			config: ['filters' => ['register' => 'learniq', 'schema' => $schema], 'ids' => [$id], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($objects as $object) {
			$row = (new SerialisedRow())->asArray(object: $object);
			if (($row['id'] ?? ($row['uuid'] ?? null)) === $id) {
				return $row;
			}
		}

		return null;
	}//end one()

	/**
	 * The rows of a schema whose fields equal the filters.
	 *
	 * @param string                $schema  The schema slug.
	 * @param array<string, string> $filters Field equals value.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
	 */
	private function many(string $schema, array $filters): array {
		$objects = $this->objectService->findAll(
			config: ['filters' => array_merge(['register' => 'learniq', 'schema' => $schema], $filters), 'limit' => 500],
			_rbac: false,
			_multitenancy: false
		);
		$rows = [];
		foreach ($objects as $object) {
			$row = (new SerialisedRow())->asArray(object: $object);
			// Never trust the store to have applied a filter on a field.
			if (self::rowMatches(row: $row, filters: $filters) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end many()

	/**
	 * Whether a row carries every filtered value.
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param array<string, mixed> $filters Field => value.
	 *
	 * @return bool
	 */
	private static function rowMatches(array $row, array $filters): bool {
		foreach ($filters as $field => $value) {
			if (($row[$field] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end rowMatches()
}//end trait
