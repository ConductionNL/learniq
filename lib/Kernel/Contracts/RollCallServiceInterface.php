<?php

/**
 * Contract of RollCallService
 *
 * The public surface of RollCallService that other components call, so they depend
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

use OCA\Learniq\Service\Attendance\RollCallException;
use OCP\IUser;

/**
 * Contract of RollCallService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RollCallServiceInterface {

	/**
	 * The register of a group on a day, as the page shows it.
	 *
	 * @param IUser       $user      The caller.
	 * @param string|null $cohortId  The group; null for the caller's first group.
	 * @param string|null $date      The day, `Y-m-d`; null for today.
	 * @param string|null $sessionId The lesson; null for the day's first lesson.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RollCallException When the caller may not open it, or the date is not a date.
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
	 */
	public function open(IUser $user, ?string $cohortId, ?string $date, ?string $sessionId): array;

	/**
	 * Save the marks of a group's register on a day, and answer with the register.
	 *
	 * @param IUser                            $user      The caller.
	 * @param string                           $cohortId  The group.
	 * @param string                           $date      The day, `Y-m-d`.
	 * @param string|null                      $sessionId The lesson; null for the day's first lesson, created when none.
	 * @param array<int, mixed>                $marks     One mark per pupil: learnerId, status, lateMinutes, absenceReasonKind, reason.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RollCallException When the caller may not save it, or a mark is incomplete.
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
	 */
	public function save(IUser $user, string $cohortId, string $date, ?string $sessionId, array $marks): array;
}//end interface
