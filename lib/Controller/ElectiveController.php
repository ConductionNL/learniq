<?php

/**
 * Learniq Elective Controller
 *
 * The learner's door to optional lessons, and the coordinator's roster:
 *
 * - `GET /api/electives`: the open offers the caller may sign up for.
 * - `POST /api/electives/{offerId}/sign-up`: sign the caller up; the learner
 *   is always the signed-in user, never a value from the body.
 * - `POST /api/elective-sign-ups/{id}/withdraw`: withdraw the caller's own sign-up.
 * - `GET /api/electives/{offerId}/roster` and `POST /api/electives/{offerId}/place`:
 *   staff, behind the ADR-023 action `elective.manage`.
 *
 * Every write goes through ObjectService, so ElectiveSignUpRules runs; the
 * learner's writes skip RBAC because a learner may not write the schema
 * directly, and the rules are then the only rules.
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
 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\ElectiveBoard;
use OCA\Learniq\Service\ElectiveService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Sign up, withdraw, and the coordinator's roster.
 *
 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
 */
class ElectiveController extends Controller {

	public const ACTION = 'elective.manage';
	private const NO_OFFER = 'This offer cannot be found.';

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request       HTTP request.
	 * @param IUserSession      $userSession   Current user session.
	 * @param ActionAuthService $actionAuth    ADR-023 action matrix.
	 * @param ElectiveService   $electives     Offers and sign-ups.
	 * @param ElectiveBoard     $board         The pages' data.
	 * @param ObjectService     $objectService Writes the sign-ups.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly ElectiveService $electives,
		private readonly ElectiveBoard $board,
		private readonly ObjectService $objectService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The open offers the caller may sign up for.
	 *
	 * @return JSONResponse `{offers: [...]}` or 401.
	 *
	 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function mine(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthenticated();
		}

		return new JSONResponse(data: ['offers' => $this->board->forLearner(learnerId: $user->getUID())]);
	}//end mine()

	/**
	 * Sign the caller up for one lesson of an offer.
	 *
	 * @param string $offerId The offer.
	 *
	 * @return JSONResponse The sign-up (201), or 401/404/422 with the reason.
	 *
	 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
	 */
	#[NoAdminRequired]
	public function signUp(string $offerId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthenticated();
		}

		return $this->write(offerId: $offerId, learnerId: $user->getUID(), status: 'signed-up', asStaff: false);
	}//end signUp()

	/**
	 * Withdraw the caller's own sign-up.
	 *
	 * @param string $id The sign-up.
	 *
	 * @return JSONResponse The sign-up, or 401/404/422 with the reason.
	 *
	 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
	 */
	#[NoAdminRequired]
	public function withdraw(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthenticated();
		}

		$signUp = $this->electives->signUp(signUpId: $id);
		if ($signUp === null || (string)($signUp['learnerId'] ?? '') !== $user->getUID()) {
			return new JSONResponse(data: ['error' => 'This sign-up cannot be found.'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$signUp['status'] = ElectiveService::WITHDRAWN;

		return $this->save(signUp: $signUp, asStaff: false, created: false);
	}//end withdraw()

	/**
	 * Per lesson who signed up and which eligible learners did not.
	 *
	 * @param string $offerId The offer.
	 *
	 * @return JSONResponse The roster, or 401/403/404.
	 *
	 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-coordinator-places-learners-who-missed-the-window
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function roster(string $offerId): JSONResponse {
		$denied = $this->deniedToStaff();
		if ($denied !== null) {
			return $denied;
		}

		$roster = $this->board->roster(offerId: $offerId);
		if ($roster === null) {
			return new JSONResponse(data: ['error' => self::NO_OFFER], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $roster);
	}//end roster()

	/**
	 * Place a learner on a lesson, also after the window.
	 *
	 * @param string $offerId The offer.
	 *
	 * @return JSONResponse The sign-up (201), or 400/401/403/404/422.
	 *
	 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-coordinator-places-learners-who-missed-the-window
	 */
	#[NoAdminRequired]
	public function place(string $offerId): JSONResponse {
		$denied = $this->deniedToStaff();
		if ($denied !== null) {
			return $denied;
		}

		$learnerId = $this->request->getParam('learnerId');
		if (is_string($learnerId) === false || $learnerId === '') {
			return new JSONResponse(data: ['error' => 'Choose a learner to place.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return $this->write(offerId: $offerId, learnerId: $learnerId, status: 'placed', asStaff: true);
	}//end place()

	/**
	 * Build and save a new sign-up for the lesson named in the body.
	 *
	 * @param string $offerId   The offer.
	 * @param string $learnerId The learner.
	 * @param string $status    `signed-up` or `placed`.
	 * @param bool   $asStaff   Whether the write keeps the caller's RBAC.
	 *
	 * @return JSONResponse
	 */
	private function write(string $offerId, string $learnerId, string $status, bool $asStaff): JSONResponse {
		$offer = $this->electives->offer(offerId: $offerId);
		if ($offer === null) {
			return new JSONResponse(data: ['error' => self::NO_OFFER], statusCode: Http::STATUS_NOT_FOUND);
		}

		$signUp = [
			'offerId' => $offerId,
			'learnerId' => $learnerId,
			'status' => $status,
			'tenant_id' => (string)($offer['tenant_id'] ?? ''),
		];
		$sessionId = $this->request->getParam('sessionId');
		$ref = $this->request->getParam('timetableSessionRef');
		if (is_string($sessionId) === true && $sessionId !== '') {
			$signUp['sessionId'] = $sessionId;
		} else if (is_array($ref) === true) {
			$signUp['timetableSessionRef'] = [
				'sourceSystem' => (string)($ref['sourceSystem'] ?? ''),
				'externalRef' => (string)($ref['externalRef'] ?? ''),
			];
		}

		return $this->save(signUp: $signUp, asStaff: $asStaff, created: true);
	}//end write()

	/**
	 * Save a sign-up; the rules listener answers a refusal with its reason.
	 *
	 * @param array<string, mixed> $signUp  The sign-up.
	 * @param bool                 $asStaff Whether the write keeps the caller's RBAC.
	 * @param bool                 $created Whether it is new.
	 *
	 * @return JSONResponse
	 */
	private function save(array $signUp, bool $asStaff, bool $created): JSONResponse {
		try {
			$saved = $this->objectService->saveObject(
				object: $signUp,
				register: ElectiveService::REGISTER,
				schema: ElectiveService::SIGN_UP_SCHEMA,
				_rbac: $asStaff
			);
		} catch (Throwable $exception) {
			return new JSONResponse(data: ['error' => $this->reason(exception: $exception)], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$status = Http::STATUS_OK;
		if ($created === true) {
			$status = Http::STATUS_CREATED;
		}

		return new JSONResponse(data: (array)$saved->jsonSerialize(), statusCode: $status);
	}//end save()

	/**
	 * The reason a write was refused.
	 *
	 * @param Throwable $exception What the write threw.
	 *
	 * @return string
	 */
	private function reason(Throwable $exception): string {
		if (method_exists($exception, 'getErrors') === true) {
			$errors = $exception->getErrors();
			if (is_array($errors) === true && is_string($errors['message'] ?? null) === true && $errors['message'] !== '') {
				return $errors['message'];
			}
		}

		$message = trim($exception->getMessage());
		if ($message === '') {
			return 'This sign-up could not be saved.';
		}

		return $message;
	}//end reason()

	/**
	 * A refusal when there is no user or the action matrix says no.
	 *
	 * @return JSONResponse|null Null when the caller may go on.
	 */
	private function deniedToStaff(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthenticated();
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end deniedToStaff()

	/**
	 * The 401 answer.
	 *
	 * @return JSONResponse
	 */
	private function unauthenticated(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
	}//end unauthenticated()
}//end class
