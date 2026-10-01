<?php

/**
 * Learniq Timetable Feed Controller
 *
 * A personal calendar feed of the user's own timetable. A calendar app fetches
 * the address without a session, with only the unguessable token in it; the
 * signed-in user manages the address through {@see TimetableFeedAddressController}.
 *
 * The feed reads exactly what My timetable reads: the token's owner is made
 * the request's active user for the read only (IUserSession volatile user),
 * so OpenRegister's RBAC and multitenancy scope the lessons as they do on the
 * page, and the read goes through the same {@see PersonalTimetableService}
 * (attendance-timetable-calendar-feed D2, D3). The volatile user is cleared
 * again before the response leaves, and nothing is written.
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
 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\PersonalTimetableService;
use OCA\Learniq\Service\TimetableFeedRenderer;
use OCA\Learniq\Service\TimetableFeedTokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Serves a user's timetable calendar feed.
 */
class TimetableFeedController extends Controller {
	/**
	 * Days before today the feed starts.
	 *
	 * @var int
	 */
	private const DAYS_BACK = 14;

	/**
	 * Days after today the feed ends (twelve weeks).
	 *
	 * @var int
	 */
	private const DAYS_AHEAD = 84;

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request     HTTP request.
	 * @param IUserSession              $userSession The feed owner, as volatile user for the read.
	 * @param IUserManager              $userManager Looks up the feed owner.
	 * @param TimetableFeedTokenService $tokens      Feed tokens.
	 * @param PersonalTimetableService  $timetable   The user's own lessons, as on My timetable.
	 * @param TimetableFeedRenderer     $renderer    Lessons to iCalendar in the owner's language.
	 * @param ITimeFactory              $time        The clock.
	 * @param LoggerInterface           $logger      Application logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly TimetableFeedTokenService $tokens,
		private readonly PersonalTimetableService $timetable,
		private readonly TimetableFeedRenderer $renderer,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Serve the feed of the token's owner as iCalendar.
	 *
	 * An unknown, replaced or malformed token, and an owner who no longer
	 * exists or is disabled, all answer 404 with no body.
	 *
	 * @param string $token The feed token.
	 *
	 * @return DataDisplayResponse
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 3600)]
	#[UserRateLimit(limit: 120, period: 3600)]
	public function feed(string $token): DataDisplayResponse {
		$uid = $this->tokens->userFor(token: $token);
		$owner = null;
		if ($uid !== null) {
			$owner = $this->userManager->get($uid);
		}

		if ($uid === null || $owner === null || $owner->isEnabled() === false) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		}

		$now = $this->time->getTime();
		$today = (int)(floor($now / 86400) * 86400);
		$from = gmdate(DATE_ATOM, ($today - (self::DAYS_BACK * 86400)));
		$to = gmdate(DATE_ATOM, ($today + (self::DAYS_AHEAD * 86400)));

		$previous = $this->userSession->getUser();
		$this->userSession->setVolatileActiveUser($owner);
		try {
			$ics = $this->renderer->render(
				owner: $owner,
				sessions: $this->timetable->forUser(uid: $uid, windowFrom: $from, windowTo: $to)['sessions'],
				now: $now
			);
		} catch (RuntimeException $e) {
			$this->logger->warning('[TimetableFeedController] Timetable source unavailable: {msg}', ['msg' => $e->getMessage()]);
			return new DataDisplayResponse('', Http::STATUS_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '900']);
		} finally {
			$this->userSession->setVolatileActiveUser($previous);
		}

		return new DataDisplayResponse(
			$ics,
			Http::STATUS_OK,
			[
				'Content-Type' => 'text/calendar; charset=utf-8',
				'Content-Disposition' => 'inline; filename="timetable.ics"',
				'Cache-Control' => 'private, max-age=900',
			]
		);
	}//end feed()
}//end class
