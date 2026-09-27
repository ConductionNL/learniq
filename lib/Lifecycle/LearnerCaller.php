<?php

/**
 * Learniq Learner Caller
 *
 * The one rule the learner-side transition guards share: the caller must be
 * the person named on the row. Administrators and system calls (OpenRegister
 * passes an empty uid when there is no session) are not refused.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-learner-runs-the-transitions-on-their-own-rows
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCP\IGroupManager;

/**
 * Decides whether the caller is the person named on a row.
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-learner-runs-the-transitions-on-their-own-rows
 */
class LearnerCaller {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Resolves whether the caller is an administrator.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether the caller is the named person, an administrator or a system call.
	 *
	 * @param string $userId The caller, or '' without a session.
	 * @param mixed  $named  The row's person field.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-learner-runs-the-transitions-on-their-own-rows
	 */
	public function isNamed(string $userId, mixed $named): bool {
		if ($userId === '' || $this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		return is_string($named) === true && $named === $userId;
	}//end isNamed()
}//end class
