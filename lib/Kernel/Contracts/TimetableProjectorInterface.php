<?php

/**
 * Contract of TimetableProjector
 *
 * The public surface of TimetableProjector that other components call, so they depend
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

/**
 * Contract of TimetableProjector.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimetableProjectorInterface {

	/**
	 * Resolve the requested window, defaulting to the current ISO week (UTC).
	 *
	 * @param string|null $from Requested inclusive window start.
	 * @param string|null $to Requested exclusive window end.
	 *
	 * @return array{0:string,1:string} The [from, to] ISO 8601 pair.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
	 */
	public function resolveWindow(?string $from, ?string $to): array;

	/**
	 * Project the raw sessions overlapping the requested window, ordered
	 * globally by `startsAt`.
	 *
	 * @param array<int,array<string,mixed>> $rawSessions Raw session data arrays, all cohorts.
	 * @param array{0: string, 1: string} $window Inclusive window start and exclusive window end (ISO 8601).
	 * @param array<string,array<string,mixed>> $roomCache Pre-loaded Room data keyed by UUID.
	 * @param array<string,array<int,array<string,mixed>>> $notes Visible lesson notes keyed by session id (timetabling-lesson-note).
	 *
	 * @return array<int,array<string,mixed>> The ordered, projected sessions.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
	 */
	public function windowedSessions(array $rawSessions, array $window, array $roomCache, array $notes = []): array;
}//end interface
