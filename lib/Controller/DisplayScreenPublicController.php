<?php

/**
 * Learniq Display Screen Public Controller
 *
 * The door a hall screen opens without a signed-in user:
 * `GET /display/{token}` (the full-screen page) and
 * `GET /api/public/display/{token}` (its data). An unknown, wrong or revoked
 * token answers 404 and counts towards brute-force protection.
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\DisplayScreenBoard;
use OCA\Learniq\Service\DisplayScreenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A screen's page and data, public, addressed by its secret token.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-never-shows-personal-data
 */
class DisplayScreenPublicController extends Controller {

	private const THROTTLE_ACTION = 'learniq-display';

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request      HTTP request.
	 * @param DisplayScreenService $screens      Resolves the token.
	 * @param DisplayScreenBoard   $board        The lessons the screen shows.
	 * @param IInitialState        $initialState Hands the token to the page.
	 * @param IThrottler           $throttler    Brute-force protection.
	 * @param LoggerInterface      $logger       Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly DisplayScreenService $screens,
		private readonly DisplayScreenBoard $board,
		private readonly IInitialState $initialState,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The screen's lessons, or 404.
	 *
	 * @param string $token The address token.
	 *
	 * @return JSONResponse `{name, updatedAt, lessons}` or 404.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-never-shows-personal-data
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function data(string $token): JSONResponse {
		$screen = $this->screens->screenForToken(token: $token);
		if ($screen === null) {
			$this->registerMiss();
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		try {
			$board = $this->board->forScreen(screen: $screen);
		} catch (Throwable $exception) {
			$this->logger->warning('[DisplayScreenPublicController] Board failed: {msg}', ['msg' => $exception->getMessage()]);
			return new JSONResponse(data: ['error' => 'The timetable could not be read.'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(data: $board);
	}//end data()

	/**
	 * The full-screen page, or 404.
	 *
	 * @param string $token The address token.
	 *
	 * @return TemplateResponse|JSONResponse The page, or 404.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function page(string $token): TemplateResponse|JSONResponse {
		if ($this->screens->screenForToken(token: $token) === null) {
			$this->registerMiss();
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$this->initialState->provideInitialState('display-token', $token);

		return new TemplateResponse(Application::APP_ID, 'display', [], TemplateResponse::RENDER_AS_BASE);
	}//end page()

	/**
	 * Count a wrong token towards brute-force protection.
	 *
	 * @return void
	 */
	private function registerMiss(): void {
		try {
			$this->throttler->registerAttempt(action: self::THROTTLE_ACTION, ip: $this->request->getRemoteAddress());
		} catch (Throwable $exception) {
			$this->logger->warning('[DisplayScreenPublicController] registerAttempt failed: {msg}', ['msg' => $exception->getMessage()]);
		}
	}//end registerMiss()
}//end class
