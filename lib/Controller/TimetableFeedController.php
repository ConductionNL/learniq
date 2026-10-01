<?php

/**
 * Learniq Timetable Feed Controller
 *
 * A personal calendar feed of the user's own timetable. The signed-in user
 * creates, replaces or removes their feed address; a calendar app fetches the
 * address without a session, with only the unguessable token in it.
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
use OCA\Learniq\Service\TimetableFeedEventBuilder;
use OCA\Learniq\Service\TimetableFeedTokenService;
use OCA\Learniq\Service\TimetableIcsWriter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Serves and manages a user's timetable calendar feed.
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
	 * @param IUserSession              $userSession The signed-in user, and the feed owner as volatile user.
	 * @param IUserManager              $userManager Looks up the feed owner.
	 * @param TimetableFeedTokenService $tokens      Feed tokens.
	 * @param PersonalTimetableService  $timetable   The user's own lessons, as on My timetable.
	 * @param TimetableFeedEventBuilder $events      Lessons to calendar events.
	 * @param TimetableIcsWriter        $writer      Events to iCalendar.
	 * @param IURLGenerator             $urls        The feed address.
	 * @param IFactory                  $l10nFactory Strings in the owner's language.
	 * @param ITimeFactory              $time        The clock.
	 * @param LoggerInterface           $logger      Application logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly TimetableFeedTokenService $tokens,
		private readonly PersonalTimetableService $timetable,
		private readonly TimetableFeedEventBuilder $events,
		private readonly TimetableIcsWriter $writer,
		private readonly IURLGenerator $urls,
		private readonly IFactory $l10nFactory,
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
			$sessions = $this->timetable->forUser(uid: $uid, windowFrom: $from, windowTo: $to)['sessions'];
			$l10n = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($owner));
			$events = $this->events->events(uid: $uid, sessions: $sessions, l10n: $l10n);
			$name = $l10n->t('My timetable');
		} catch (RuntimeException $e) {
			$this->logger->warning('[TimetableFeedController] Timetable source unavailable: {msg}', ['msg' => $e->getMessage()]);
			return new DataDisplayResponse('', Http::STATUS_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '900']);
		} finally {
			$this->userSession->setVolatileActiveUser($previous);
		}

		return new DataDisplayResponse(
			$this->writer->write(name: $name, events: $events, now: $now),
			Http::STATUS_OK,
			[
				'Content-Type' => 'text/calendar; charset=utf-8',
				'Content-Disposition' => 'inline; filename="timetable.ics"',
				'Cache-Control' => 'private, max-age=900',
			]
		);
	}//end feed()

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
