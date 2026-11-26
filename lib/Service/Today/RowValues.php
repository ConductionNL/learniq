<?php

/**
 * Learniq Row Values
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reading an OpenRegister row's id and a stored date-time, for the First
 * today facts.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Today
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Today;

use DateTimeImmutable;
use Exception;

/**
 * A row's id and a stored moment.
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/tasks.md#T1
 */
trait RowValues {

	/**
	 * A row's id: `id`, else `uuid`, else `@self.id`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? '')));
	}//end idOf()

	/**
	 * A date-time value as a moment, or null when it is empty or not a date.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception) {
			return null;
		}
	}//end moment()
}//end trait
