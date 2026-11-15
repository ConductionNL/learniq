<?php

/**
 * Learniq caller groups.
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
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Who is calling and which groups they are in: the user session and the
 * group manager that a controller checks before it acts.
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
final class CallerGroups {

	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  The current user session.
	 * @param IGroupManager $groupManager Answers group membership and admin checks.
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
