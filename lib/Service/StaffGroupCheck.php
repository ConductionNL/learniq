<?php

/**
 * Learniq check whether a user is staff for one class.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Role-group plumbing shared by several classes, not a behavioural requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\IGroupManager;

/**
 * Whether a user is staff: an admin, or a member of one of the staff groups
 * the owning class names, asked in order. Built by the owner from its own
 * group manager and staff groups.
 *
 * @spec exclude Role-group plumbing shared by several classes, not a behavioural requirement of its own.
 */
final class StaffGroupCheck {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Answers admin and group membership.
	 * @param array<int, string> $staffGroups The groups whose members are staff.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly array $staffGroups,
	) {
	}//end __construct()

	/**
	 * Whether the user is an admin or in one of the staff groups.
	 *
	 * @param string $uid The user.
	 *
	 * @return bool
	 *
	 * @spec exclude Role-group plumbing shared by several classes, not a behavioural requirement of its own.
	 */
	public function isStaff(string $uid): bool {
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		foreach ($this->staffGroups as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isStaff()
}//end class
