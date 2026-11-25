<?php

/**
 * Learniq Today Messages Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GET /api/today/parent-messages: the signed-in teacher's newest
 * conversations with parents, read through portaliq's staff messaging
 * (`?view=mentor` narrows them to her mentor class). Read only.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/today-first-per-school-role/tasks.md#T5
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Service\Today\ParentMessagesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The "Messages from parents" card on Today.
 *
 * @spec openspec/changes/today-first-per-school-role/tasks.md#T5
 */
class TodayMessagesController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request     The request.
	 * @param IUserSession          $userSession The session.
	 * @param ParentMessagesService $messages    The conversations with parents.
	 * @param IL10N                 $l10n        Translations.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ParentMessagesService $messages,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The caller's newest conversations with parents.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T5
	 */
	#[NoAdminRequired]
	public function parentMessages(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Sign in to open this page.')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$view = $this->request->getParam('view', '');
		if (is_string($view) === false) {
			$view = '';
		}

		return new JSONResponse(data: $this->messages->for(user: $user, view: $view));
	}//end parentMessages()
}//end class
