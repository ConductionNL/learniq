<?php

/**
 * Learniq Timetable Feed Address Controller
 *
 * The signed-in user's own calendar feed address: whether one exists, make
 * a new one (which replaces any earlier one, so the old address answers 404
 * at once), and remove it. Every call acts only on the caller's own token;
 * there is no object id to reach someone else's (attendance-timetable-calendar-feed D6).
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\TimetableFeedTokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Manages the signed-in user's calendar feed address.
 */
class TimetableFeedAddressController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request     HTTP request.
	 * @param IUserSession              $userSession The signed-in user.
	 * @param TimetableFeedTokenService $tokens      Feed tokens.
	 * @param IURLGenerator             $urls        The feed address.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly TimetableFeedTokenService $tokens,
		private readonly IURLGenerator $urls,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether the signed-in user has a feed address.
	 *
	 * @return JSONResponse `{exists}`; 401 without a user.
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
	 */
	#[NoAdminRequired]
	public function status(): JSONResponse {
		$uid = $this->callerId();
		if ($uid === null) {
			return $this->unauthenticated();
		}

		return new JSONResponse(data: ['exists' => $this->tokens->exists(uid: $uid)]);
	}//end status()

	/**
	 * Create a new feed address for the signed-in user; any earlier one stops working.
	 *
	 * The address is in the answer once: only a hash of its token is stored.
	 *
	 * @return JSONResponse `{exists, url, webcalUrl}`; 401 without a user.
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 3600)]
	public function create(): JSONResponse {
		$uid = $this->callerId();
		if ($uid === null) {
			return $this->unauthenticated();
		}

		$url = $this->urls->linkToRouteAbsolute(
			Application::APP_ID . '.timetableFeed.feed',
			['token' => $this->tokens->issue(uid: $uid)]
		);

		return new JSONResponse(
			data: [
				'exists' => true,
				'url' => $url,
				'webcalUrl' => (string)preg_replace('#^https?://#', 'webcal://', $url),
			],
			statusCode: Http::STATUS_CREATED
		);
	}//end create()

	/**
	 * Remove the signed-in user's feed address.
	 *
	 * @return JSONResponse `{exists: false}`; 401 without a user.
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
	 */
	#[NoAdminRequired]
	public function revoke(): JSONResponse {
		$uid = $this->callerId();
		if ($uid === null) {
			return $this->unauthenticated();
		}

		$this->tokens->revoke(uid: $uid);

		return new JSONResponse(data: ['exists' => false]);
	}//end revoke()

	/**
	 * The signed-in user's id, or null.
	 *
	 * @return string|null
	 */
	private function callerId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end callerId()

	/**
	 * A 401 answer.
	 *
	 * @return JSONResponse
	 */
	private function unauthenticated(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
	}//end unauthenticated()
}//end class
