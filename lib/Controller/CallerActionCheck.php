<?php

/**
 * Learniq check that the caller is signed in and may take a controller's action.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec exclude Controller plumbing, not a behavioural requirement: the
 *       ADR-023 action check several controllers run before they act.
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Service\ActionAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUserSession;

/**
 * The response that refuses the caller, or null when the caller is signed in
 * and the action matrix lets them take the action. Built by the controller
 * from its own user session and action matrix.
 *
 * @spec exclude Controller plumbing, not a behavioural requirement.
 */
final class CallerActionCheck {

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Who is calling.
	 * @param ActionAuthService $actionAuth The ADR-023 action matrix.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
	) {
	}//end __construct()

	/**
	 * 401 when nobody is signed in, 403 with the matrix's message when the
	 * action is refused, null when the caller may take it.
	 *
	 * @param string $action The ADR-023 action.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec exclude Controller plumbing, not a behavioural requirement.
	 */
	public function refusal(string $action): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: $action);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refusal()
}//end class
