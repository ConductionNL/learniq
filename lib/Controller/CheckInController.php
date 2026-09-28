<?php

/**
 * Learniq Check-in Controller
 *
 * Self check-in in the app (attendance-self-check-in). A signed-in learner
 * lists the open check-ins of their own lessons, reads what a check-in page
 * shows and checks in with the code. Every method is `#[NoAdminRequired]`
 * with its check in CheckInService: the caller must be in the lesson's group.
 * The teacher's code read lives in CheckInCodeController.
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
 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\CheckIn\CheckInMessages;
use OCA\Learniq\Service\CheckIn\CheckInService;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The learner's check-in and the teacher's code.
 *
 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */
class CheckInController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request      The request.
	 * @param IUserSession       $userSession  The signed-in user.
	 * @param CheckInService     $checkIns     The check-in rules and write.
	 * @param CheckInMessages    $messages     Plain refusal reasons.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly CheckInService $checkIns,
		private readonly CheckInMessages $messages,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The open check-ins of the signed-in learner's own lessons.
	 *
	 * @return JSONResponse 200 `{checkIns: [...]}`, or 401.
	 *
	 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
	 */
	#[NoAdminRequired]
	public function mine(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: ['checkIns' => $this->checkIns->openFor(userId: $user->getUID())]);
	}//end mine()

	/**
	 * Check the signed-in learner in with only the code on the board.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#scenario-a-learner-scans-the-code-at-the-start-of-the-lesson
	 */
	#[NoAdminRequired]
	public function checkInWithCode(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$outcome = $this->checkIns->checkInWithCode(code: $this->code(), userId: $user->getUID());

		return $this->answer(outcome: $outcome, user: $user);
	}//end checkInWithCode()

	/**
	 * What the learner's check-in page shows.
	 *
	 * @param string $windowId The CheckInWindow uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
	 */
	#[NoAdminRequired]
	public function show(string $windowId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return $this->answer(outcome: $this->checkIns->show(windowId: $windowId, userId: $user->getUID()), user: $user);
	}//end show()

	/**
	 * Check the signed-in learner in with `code`.
	 *
	 * @param string $windowId The CheckInWindow uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#scenario-a-learner-scans-the-code-at-the-start-of-the-lesson
	 */
	#[NoAdminRequired]
	public function checkIn(string $windowId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$outcome = $this->checkIns->checkIn(windowId: $windowId, code: $this->code(), userId: $user->getUID());

		return $this->answer(outcome: $outcome, user: $user);
	}//end checkIn()

	/**
	 * The `code` request parameter as a string.
	 *
	 * @return string
	 */
	private function code(): string {
		$code = $this->request->getParam('code', '');
		if (is_string($code) === false) {
			return '';
		}

		return $code;
	}//end code()

	/**
	 * An outcome as a response, with the reason in the learner's words.
	 *
	 * @param PortalOutcome $outcome The outcome.
	 * @param IUser         $user    The learner.
	 *
	 * @return JSONResponse
	 */
	private function answer(PortalOutcome $outcome, IUser $user): JSONResponse {
		$body = $outcome->body;
		if ($outcome->reason !== null) {
			$body['message'] = $this->messages->message(reason: $outcome->reason, user: $user);
		}

		return new JSONResponse(data: $body, statusCode: $outcome->status);
	}//end answer()
}//end class
