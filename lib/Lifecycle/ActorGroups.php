<?php

/**
 * Learniq actor group membership.
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
 * @spec exclude Role-group plumbing shared by several guards, not a behavioural requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Whether the acting user is in one of a guard's role groups. Built by the
 * guard from its own user and group managers.
 *
 * @spec exclude Role-group plumbing shared by several guards, not a behavioural requirement of its own.
 */
final class ActorGroups {

	/**
	 * Constructor.
	 *
	 * @param IUserManager  $userManager  Resolves the actor's uid to a user.
	 * @param IGroupManager $groupManager Resolves the user's group ids.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether the actor is a known user in at least one of the groups.
	 *
	 * @param string            $actor  The acting user's uid.
	 * @param array<int,string> $groups The role groups that qualify.
	 *
	 * @return bool
	 *
	 * @spec exclude Role-group plumbing shared by several guards, not a behavioural requirement of its own.
	 */
	public function isInAny(string $actor, array $groups): bool {
		$user = $this->userManager->get($actor);
		if ($user === null) {
			return false;
		}

		$actorGroups = $this->groupManager->getUserGroupIds($user);

		return count(array_intersect($actorGroups, $groups)) > 0;
	}//end isInAny()
}//end class
