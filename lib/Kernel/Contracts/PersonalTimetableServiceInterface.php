<?php

/**
 * Contract of PersonalTimetableService
 *
 * The public surface of PersonalTimetableService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of PersonalTimetableService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PersonalTimetableServiceInterface {

	/**
	 * The user's own lessons in a resolved window, with today's changes.
	 *
	 * A user with no lessons at all gets empty lists, never an error.
	 *
	 * @param string $uid        The user's Nextcloud user id.
	 * @param string $windowFrom Inclusive window start (ISO 8601, already resolved).
	 * @param string $windowTo   Exclusive window end (ISO 8601, already resolved).
	 *
	 * @return array{sessions: array<int,array<string,mixed>>, changes: array<int,array<string,mixed>>, source: string}
	 *
	 * @throws RuntimeException When the timetable source does not answer.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
	 */
	public function forUser(string $uid, string $windowFrom, string $windowTo): array;

	/**
	 * Pre-load every distinct Room referenced by `roomId` across the given
	 * raw sessions, so the projection step never issues an N+1 query.
	 *
	 * @param array<int,array<string,mixed>> $sessions Raw session data arrays.
	 *
	 * @return array<string,array<string,mixed>> Room data keyed by room UUID.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
	 */
	public function preloadRooms(array $sessions): array;
}//end interface
