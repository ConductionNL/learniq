<?php

/**
 * Contract of SchoolActivityStaff
 *
 * The public surface of SchoolActivityStaff that other components call, so they depend
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
 * Contract of SchoolActivityStaff.
 *
 * Every write answers either the saved row or `['error' => <reason>]` with one
 * of the REASON_ constants.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SchoolActivityStaffInterface {

	/**
	 * The activity is unknown.
	 */
	public const REASON_NOT_FOUND = 'not_found';

	/**
	 * The activity has no place, so it does not open.
	 */
	public const REASON_NO_PLACES = 'no_places';

	/**
	 * The session is not one of the activity's sessions.
	 */
	public const REASON_UNKNOWN_SESSION = 'unknown_session';

	/**
	 * The child has no confirmed place.
	 */
	public const REASON_NOT_CONFIRMED = 'not_confirmed';

	/**
	 * The status is not present, absent or excused.
	 */
	public const REASON_INVALID_STATUS = 'invalid_status';

	/**
	 * The records could not be read or written.
	 */
	public const REASON_UNAVAILABLE = 'unavailable';

	/**
	 * Every activity with its places, confirmed sign-ups and waiting list size.
	 *
	 * @return array<int, array<string, mixed>>|null The activities, or null when the records cannot be read.
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-set-an-activitys-places-by-capacity-and-supervision
	 */
	public function board(): ?array;

	/**
	 * Open a draft activity, refused when it has no place.
	 *
	 * @param string $activityId The activity.
	 *
	 * @return array<string, mixed> The opened activity, or `['error' => reason]`.
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-set-an-activitys-places-by-capacity-and-supervision
	 */
	public function open(string $activityId): array;

	/**
	 * The roster: places, confirmed children and waiting list, with photo consent where photos are taken.
	 *
	 * @param string $activityId The activity.
	 *
	 * @return array<string, mixed> The roster, or `['error' => reason]`.
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-where-photos-are-taken-the-staff-roster-must-show-each-childs-photo-consent
	 */
	public function roster(string $activityId): array;

	/**
	 * Mark one confirmed child for one session; a second mark updates the first.
	 *
	 * @param string $activityId  The activity.
	 * @param string $sessionId   One of the activity's sessions.
	 * @param string $childRef    The child.
	 * @param string $status      present, absent or excused.
	 * @param string $markedByRef Who marks.
	 *
	 * @return array<string, mixed> The attendance row, or `['error' => reason]`.
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-be-able-to-mark-attendance-per-session
	 */
	public function mark(string $activityId, string $sessionId, string $childRef, string $status, string $markedByRef): array;
}//end interface
