<?php

/**
 * Learniq conference slot teacher guard
 *
 * Lifecycle guard for ConferenceSlot's `acknowledge` and `decline`
 * transitions. A parent booked this time with one teacher, so that teacher
 * answers it: the caller must be the slot's `teacherId`. A coordinator, a
 * team lead, an administration manager or an admin may answer for an absent
 * colleague. Any other teacher is refused, and so is a call without a user.
 *
 * Legitimate PHP per ADR-031: a lifecycle guard that needs the caller's
 * identity, resolved by OpenRegister from the session and never from the
 * request body.
 *
 * Referenced from ConferenceSlot's
 * x-openregister-lifecycle.transitions.{acknowledge,decline}.requires in
 * learniq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;

/**
 * Only the slot's own teacher, or school staff who may stand in, answers a
 * booking.
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */
class ConferenceSlotTeacherGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 */
	private const DENIAL = 'Only the teacher of this conversation can acknowledge or decline it.';

	/**
	 * Groups whose members may answer for another teacher.
	 */
	private const STAND_IN_GROUPS = ['admin', 'coordinators', 'team-leads', 'administration-managers'];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groups Group membership of the caller.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * Allow the slot's teacher and the stand-in groups.
	 *
	 * @param array<string, mixed> $object The slot at its target state, transition inputs merged in.
	 * @param string $action The transition being applied.
	 * @param string $userId The caller's uid.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($userId === '') {
			return GuardResult::deny(self::DENIAL);
		}

		if ($userId === (string)($object['teacherId'] ?? '')) {
			return GuardResult::allow();
		}

		foreach (self::STAND_IN_GROUPS as $group) {
			if ($this->groups->isInGroup($userId, $group) === true) {
				return GuardResult::allow();
			}
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()
}//end class
