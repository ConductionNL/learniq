<?php

/**
 * Learniq Session Change Batch Controller
 *
 * `GET /api/sessions/{id}/series` lists the lessons of the same weekly slot for
 * the substitution dialog; `POST /api/session-change-batches` applies one change
 * to the ticked lessons. The action matrix decides who may open the door; every
 * lesson is then checked by SessionChangeGuard as the caller.
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
 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use InvalidArgumentException;
use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\SessionChangeBatchService;
use OCA\Learniq\Timetabling\SessionSeries;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Apply one change to several weeks.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
 */
class SessionChangeBatchController extends Controller {

	public const ACTION = 'timetable.bulk-change';

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request     HTTP request.
	 * @param IUserSession              $userSession Current user session.
	 * @param ActionAuthService         $actionAuth  ADR-023 action matrix.
	 * @param SessionChangeBatchService $batches     Applies the change.
	 * @param SessionSeries             $series      Finds the weekly slot.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly SessionChangeBatchService $batches,
		private readonly SessionSeries $series,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The lessons of the same weekly slot, up to a date.
	 *
	 * @param string      $id    The lesson the dialog was opened on.
	 * @param string|null $until Last date to include (Y-m-d).
	 *
	 * @return JSONResponse `{sessions: [...]}`, or 401/403/404.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function series(string $id, ?string $until=null): JSONResponse {
		$denied = $this->denied();
		if ($denied !== null) {
			return $denied;
		}

		$series = $this->series->series(sessionId: $id, until: $until);
		if ($series === null) {
			return new JSONResponse(data: ['error' => 'This lesson cannot be found, or you cannot see it.'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['sessions' => $series]);
	}//end series()

	/**
	 * Apply one change to the ticked lessons.
	 *
	 * @return JSONResponse The batch with its results per lesson, or 400/401/403.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-every-lesson-in-a-batch-passes-the-same-checks
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$denied = $this->denied();
		if ($denied !== null) {
			return $denied;
		}

		$user = $this->userSession->getUser();
		if ($user instanceof IUser === false) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$input = [];
		foreach (['kind', 'sessionIds', 'changeReasonKind', 'changeReason', 'substituteTeacherId', 'roomId'] as $key) {
			$input[$key] = $this->request->getParam($key);
		}

		try {
			$batch = $this->batches->apply(input: $input, callerId: $user->getUID());
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $batch, statusCode: Http::STATUS_CREATED);
	}//end create()

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
