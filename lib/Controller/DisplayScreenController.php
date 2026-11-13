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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\DisplayScreenServiceInterface;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerActions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Create, renew and revoke a screen's address.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
 */
class DisplayScreenController extends Controller {

	public const ACTION = 'display-screen.manage';
	private const NOT_FOUND = 'This screen cannot be found, or you cannot see it.';

	/**
	 * Current user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * ADR-023 action matrix.
	 *
	 * @var ActionAuthService
	 */
	private readonly ActionAuthService $actionAuth;

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request     HTTP request.
	 * @param CallerActions $caller The user session and the ADR-023 action matrix.
	 * @param DisplayScreenServiceInterface $screens     Issues and revokes addresses.
	 * @param IURLGenerator        $urls        Builds the screen's address.
	 */
	public function __construct(
		IRequest $request,
		CallerActions $caller,
		private readonly DisplayScreenServiceInterface $screens,
		private readonly IURLGenerator $urls,
	) {
		$this->userSession = $caller->userSession;
		$this->actionAuth = $caller->actionAuth;
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Create or renew the address; it is answered only here, once.
	 *
	 * @param string $id The screen's uuid.
	 *
	 * @return JSONResponse `{address, note}`, or 401/403/404.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
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
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
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
		return (new CallerActionCheck(userSession: $this->userSession, actionAuth: $this->actionAuth))->refusal(action: self::ACTION);
	}//end denied()
}//end class
