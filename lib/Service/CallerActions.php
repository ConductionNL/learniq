<?php

/**
 * Learniq caller actions.
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

use OCP\IUserSession;

/**
 * Who is calling and what they may do: the user session and the ADR-023
 * action matrix that a controller checks before it acts.
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
final class CallerActions {

	/**
	 * Constructor.
	 *
	 * @param IUserSession      $userSession The current user session.
	 * @param ActionAuthService $actionAuth  The ADR-023 action matrix.
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly ActionAuthService $actionAuth,
	) {
	}//end __construct()
}//end class
