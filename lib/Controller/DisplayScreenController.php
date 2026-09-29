<?php

/**
 * Learniq Display Screen Controller
 *
 * `POST /api/display-screens/{id}/token` creates or renews a screen's secret
 * address and answers it once; `POST /api/display-screens/{id}/revoke` stops
 * it at once. Both open on the ADR-023 action `display-screen.manage`.
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
 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\DisplayScreenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Create, renew and revoke a screen's address.
 *
 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
 */
class DisplayScreenController extends Controller {

	public const ACTION = 'display-screen.manage';
	private const NOT_FOUND = 'This screen cannot be found, or you cannot see it.';

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request     HTTP request.
	 * @param IUserSession         $userSession Current user session.
	 * @param ActionAuthService    $actionAuth  ADR-023 action matrix.
	 * @param DisplayScreenService $screens     Issues and revokes addresses.
	 * @param IURLGenerator        $urls        Builds the screen's address.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly DisplayScreenService $screens,
		private readonly IURLGenerator $urls,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Create or renew the address; it is answered only here, once.
	 *
	 * @param string $id The screen's uuid.
	 *
	 * @return JSONResponse `{address, note}`, or 401/403/404.
	 *
	 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
	 */
	#[NoAdminRequired]
	public function token(string $id): JSONResponse {
		$denied = $this->denied();
		if ($denied !== null) {
			return $denied;
		}

		$token = $this->screens->issueToken(screenId: $id);
		if ($token === null) {
			return new JSONResponse(data: ['error' => self::NOT_FOUND], statusCode: Http::STATUS_NOT_FOUND);
		}

		$address = $this->urls->getAbsoluteURL(
			$this->urls->linkToRoute('learniq.displayScreenPublic.page', ['token' => $token])
		);

		return new JSONResponse(
			data: ['address' => $address, 'note' => 'This address is shown once. Copy it into the screen\'s browser now.'],
			statusCode: Http::STATUS_CREATED
		);
	}//end token()

	/**
	 * Revoke the address.
	 *
	 * @param string $id The screen's uuid.
	 *
	 * @return JSONResponse `{status: revoked}`, or 401/403/404.
	 *
	 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
	 */
	#[NoAdminRequired]
	public function revoke(string $id): JSONResponse {
		$denied = $this->denied();
		if ($denied !== null) {
			return $denied;
		}

		if ($this->screens->revoke(screenId: $id) === false) {
			return new JSONResponse(data: ['error' => self::NOT_FOUND], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['status' => 'revoked']);
	}//end revoke()

	/**
	 * A refusal when there is no user or the action matrix says no.
	 *
	 * @return JSONResponse|null Null when the caller may go on.
	 */
	private function denied(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end denied()
}//end class
