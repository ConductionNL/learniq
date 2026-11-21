<?php

/**
 * Contract of SessionSeries
 *
 * The public surface of SessionSeries that other components call, so they depend
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
use DateTimeZone;

/**
 * Contract of SessionSeries.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SessionSeriesInterface {

	public const TIMEZONE = 'Europe/Amsterdam';

	public const OPEN_STATES = ['scheduled', 'in-progress'];

	/**
	 * Load one lesson as the caller.
	 *
	 * @param string $sessionId The lesson's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
	 */
	public function loadSession(string $sessionId): ?array;

	/**
	 * Parse a date-time into the school's time zone.
	 *
	 * @param string       $value An ISO 8601 date-time.
	 * @param DateTimeZone $tz    The school's time zone.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
	 */
	public function localTime(string $value, DateTimeZone $tz): ?DateTimeImmutable;

	/**
	 * The lessons of the same weekly slot as this one (same group, course,
	 * weekday and start time), from this lesson up to a date.
	 *
	 * @param string      $sessionId The lesson the dialog was opened on.
	 * @param string|null $until     Last date to include (Y-m-d); default eight weeks on.
	 *
	 * @return array<int, array<string, mixed>>|null The lessons, oldest first, or null when the lesson is not readable.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
	 */
	public function series(string $sessionId, ?string $until=null): ?array;

	/**
	 * An OpenRegister row as an array.
	 *
	 * @param mixed $row An entity or an array.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
	 */
	public function toArray(mixed $row): array;
}//end interface
