<?php

/**
 * Contract of RoomUtilisationService
 *
 * The public surface of RoomUtilisationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use DateTimeImmutable;
use RuntimeException;

/**
 * Contract of RoomUtilisationService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RoomUtilisationServiceInterface {

	/**
	 * Room use from `$from` (inclusive) to `$to` (exclusive), both `Y-m-d`.
	 *
	 * @param string      $from     First day.
	 * @param string      $to       Day after the last day.
	 * @param string|null $kind     Only rooms of this kind, or null.
	 * @param string|null $building Only rooms in this building, or null.
	 *
	 * @return array<string,mixed> `rooms`, `grid`, `unassigned`, `openHoursPerRoom`, `teachingDays`, `source`.
	 *
	 * @throws RuntimeException When the timetable source does not answer.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function forPeriod(string $from, string $to, ?string $kind = null, ?string $building = null): array;

	/**
	 * A `Y-m-d` day at midnight, or null when it is not a real date.
	 *
	 * @param string $day The day.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function parseDay(string $day): ?DateTimeImmutable;
}//end interface
