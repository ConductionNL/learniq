<?php

/**
 * Contract of CourseDayStatusService
 *
 * The public surface of CourseDayStatusService that other components call, so they depend
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
 * Contract of CourseDayStatusService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CourseDayStatusServiceInterface {

	/**
	 * Re-derive one course day and store its status when it moved.
	 *
	 * A cohort that is no course day (no `courseId`) is left alone.
	 *
	 * @param string $cohortId The cohort uuid.
	 *
	 * @return string|null The status, or null when the cohort is not a course day or is gone.
	 *
	 * @throws \Throwable When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function restamp(string $cohortId): ?string;

	/**
	 * Move a course day to a status by hand, as a drag on the board does.
	 *
	 * The move is logged and lasts until the next change to the day's facts
	 * (bookings, enrolments, sessions, minimum, maximum, decision or the day
	 * passing); then the derived status takes over again.
	 *
	 * @param string $cohortId The cohort uuid.
	 * @param string $status   One of the course-day statuses.
	 * @param string $userId   Who moved it.
	 *
	 * @return string The status.
	 *
	 * @throws \RuntimeException When the status is unknown or the cohort is no course day.
	 * @throws \Throwable        When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function move(string $cohortId, string $status, string $userId): string;

	/**
	 * Record the provider's go/no-go decision on a course day and re-derive it.
	 *
	 * @param string      $cohortId The cohort uuid.
	 * @param string|null $decision `go`, `no-go`, or null to undo the decision.
	 * @param string      $userId   Who decided.
	 *
	 * @return string The status after the decision.
	 *
	 * @throws \RuntimeException When the decision is unknown or the cohort is no course day.
	 * @throws \Throwable        When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function decide(string $cohortId, ?string $decision, string $userId): string;

	/**
	 * The other days of the same course that are still to come and have places.
	 *
	 * @param string $cohortId The cohort uuid.
	 *
	 * @return list<array{id: string, name: string, date: string|null, free: int|null}> Earliest first; free is null without a maximum.
	 *
	 * @throws \RuntimeException When the cohort is no course day.
	 * @throws \Throwable        When OpenRegister cannot be read.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function alternatives(string $cohortId): array;

	/**
	 * Move the participants of a cancelled course day to another day of the
	 * same course: its open bookings and the enrolments holding a place.
	 *
	 * @param string $cohortId The cancelled day.
	 * @param string $targetId The day to move them to.
	 * @param string $userId   Who moved them.
	 *
	 * @return array{bookings: int, enrolments: int, targetId: string} What moved.
	 *
	 * @throws \RuntimeException When the day is not cancelled, the target is not another coming
	 *                            day of the same course, or it lacks the places.
	 * @throws \Throwable        When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function moveParticipants(string $cohortId, string $targetId, string $userId): array;
}//end interface
