<?php

/**
 * Learniq Today First Controller
 *
 * `GET /api/today/first` answers the First today card of the signed-in user:
 * the first rule of her role's ordered list that has a row, with its count
 * and what the card names, or `rule: null` when nothing is first. A rule
 * Today has a card for also carries the card's `title`, `reason` and the
 * `page` its action opens (FirstTodayCard); Today draws one attention card
 * from them, so the page asks once. `GET /api/today/counts` answers the
 * role tiles of the same role: per tile that role acts on, its count.
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
use OCA\Learniq\Service\Today\FirstTodayCard;
use OCA\Learniq\Service\Today\FirstTodayService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

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
	 * @param FirstTodayService $firstToday  The First today rules; it logs a failed read.
	 * @param IL10N             $l10n        Translations.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly FirstTodayService $firstToday,
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
		return $this->answer(
			read: fn (IUser $user, string $view): ?array => $this->cardOf(user: $user, view: $view),
			failed: $this->l10n->t('What comes first today could not be read. Try again.')
		);
	}//end show()

	/**
	 * The role tiles on Today. Query parameter `view`: '' or `mentor`.
	 *
	 * @return JSONResponse `{role, tiles: [{id, count, ...}]}`, or 401 / 503.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
	 *
	 * @no-admin-idor-exempt the tiles are the caller's own: every count is read for the session user, with her
	 *  own rights, and the register groups through RollCallAccess::cohortsFor(), which returns only the groups
	 *  she may open. No id is taken from the request.
	 */
	#[NoAdminRequired]
	public function counts(): JSONResponse {
		return $this->answer(
			read: fn (IUser $user, string $view): ?array => $this->firstToday->tilesFor(user: $user, view: $view),
			failed: $this->l10n->t('The counts on Today could not be read. Try again.')
		);
	}//end counts()

	/**
	 * The card of the user, described for Today, or null when it could not be read.
	 *
	 * @param IUser  $user The caller.
	 * @param string $view The view asked for.
	 *
	 * @return array<string, mixed>|null
	 */
	private function cardOf(IUser $user, string $view): ?array {
		$card = $this->firstToday->cardFor(user: $user, view: $view);
		if ($card === null) {
			return null;
		}

		return (new FirstTodayCard(l10n: $this->l10n))->describe(card: $card);
	}//end cardOf()

	/**
	 * Reads for the signed-in user in the view asked: 401 without a user, 503 when the read failed.
	 *
	 * @param callable(IUser, string): (array<string, mixed>|null) $read   The read.
	 * @param string                                               $failed The message of a failed read.
	 *
	 * @return JSONResponse
	 */
	private function answer(callable $read, string $failed): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Sign in to open this page.')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$view = $this->request->getParam('view', '');
		if (is_string($view) === false) {
			$view = '';
		}

		$data = $read($user, $view);
		if ($data === null) {
			return new JSONResponse(data: ['error' => $failed], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(data: $data);
	}//end answer()
}//end class
