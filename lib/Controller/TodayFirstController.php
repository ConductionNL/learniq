<?php

/**
 * Learniq Today First Controller
 *
 * `GET /api/today/first` answers the First today card of the signed-in user:
 * the first rule of her role's ordered list that has a row, with its count
 * and what the card names, or `rule: null` when nothing is first. Today draws
 * one card per rule and shows the one this answer names.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Service\Today\FirstTodayService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The First today card of the signed-in user.
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */
class TodayFirstController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request     The request.
	 * @param IUserSession      $userSession The signed-in user.
	 * @param FirstTodayService $firstToday  The First today rules.
	 * @param LoggerInterface   $logger      Logger.
	 * @param IL10N             $l10n        Translations.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly FirstTodayService $firstToday,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The First today card. Query parameter `view`: '' or `mentor`.
	 *
	 * @return JSONResponse `{role, rule, count, ...facts}`, or 401 / 503.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-a-mentor-switches-today-to-her-mentor-class
	 *
	 * @no-admin-idor-exempt the card is the caller's own: every count is read for the session user, with her
	 *  own rights, and the register groups through RollCallAccess::cohortsFor(), which returns only the groups
	 *  she may open. No id is taken from the request.
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Sign in to open this page.')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$view = $this->request->getParam('view', '');
		try {
			return new JSONResponse(data: $this->firstToday->forUser(user: $user, view: is_string($view) === true ? $view : ''));
		} catch (Throwable $exception) {
			$this->logger->error(
				'[TodayFirstController] The First today card could not be read: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new JSONResponse(
				data: ['error' => $this->l10n->t('What comes first today could not be read. Try again.')],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}
	}//end show()
}//end class
