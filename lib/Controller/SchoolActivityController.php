<?php

/**
 * Learniq School Activity Controller
 *
 * The staff page "Activiteiten" (school-activities-staff-side): the activities
 * with their places, opening one, its roster with photo consent, and attendance
 * per session. Every call is staff-only, behind the action `activity.manage`.
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
 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\SchoolActivityStaffInterface;
use OCA\Learniq\Service\ActionAuthService;
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
 * Staff endpoints of school activities.
 *
 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md
 */
class SchoolActivityController extends Controller {

	/**
	 * The action that gates every endpoint here.
	 */
	public const ACTION = 'activity.manage';

	/**
	 * HTTP status per refusal.
	 */
	private const STATUS = [
		SchoolActivityStaffInterface::REASON_NOT_FOUND => Http::STATUS_NOT_FOUND,
		SchoolActivityStaffInterface::REASON_NO_PLACES => Http::STATUS_UNPROCESSABLE_ENTITY,
		SchoolActivityStaffInterface::REASON_UNKNOWN_SESSION => Http::STATUS_UNPROCESSABLE_ENTITY,
		SchoolActivityStaffInterface::REASON_NOT_CONFIRMED => Http::STATUS_UNPROCESSABLE_ENTITY,
		SchoolActivityStaffInterface::REASON_INVALID_STATUS => Http::STATUS_UNPROCESSABLE_ENTITY,
		SchoolActivityStaffInterface::REASON_UNAVAILABLE => Http::STATUS_BAD_GATEWAY,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request     The request.
	 * @param IUserSession                 $userSession The session.
	 * @param ActionAuthService            $actionAuth  The action matrix.
	 * @param SchoolActivityStaffInterface $activities  The staff side.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly SchoolActivityStaffInterface $activities,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The activities with their places, confirmed sign-ups and waiting list.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-set-an-activitys-places-by-capacity-and-supervision
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$denied = $this->deniedToStaff();
		if ($denied instanceof JSONResponse) {
			return $denied;
		}

		$board = $this->activities->board();
		if ($board === null) {
			return $this->refusal(reason: SchoolActivityStaffInterface::REASON_UNAVAILABLE);
		}

		return new JSONResponse(data: ['results' => $board]);
	}//end index()

	/**
	 * Open an activity: 422 `no_places` when it has no place, and it stays a draft.
	 *
	 * @param string $id The activity.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-set-an-activitys-places-by-capacity-and-supervision
	 */
	#[NoAdminRequired]
	public function open(string $id): JSONResponse {
		$denied = $this->deniedToStaff();
		if ($denied instanceof JSONResponse) {
			return $denied;
		}

		return $this->answer(result: $this->activities->open(activityId: $id));
	}//end open()

	/**
	 * The roster of one activity.
	 *
	 * @param string $id The activity.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-where-photos-are-taken-the-staff-roster-must-show-each-childs-photo-consent
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function roster(string $id): JSONResponse {
		$denied = $this->deniedToStaff();
		if ($denied instanceof JSONResponse) {
			return $denied;
		}

		return $this->answer(result: $this->activities->roster(activityId: $id));
	}//end roster()

	/**
	 * Mark a child for a session.
	 *
	 * @param string $id        The activity.
	 * @param string $sessionId The session.
	 * @param string $childRef  The child.
	 * @param string $status    present, absent or excused.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-be-able-to-mark-attendance-per-session
	 */
	#[NoAdminRequired]
	public function attendance(string $id, string $sessionId = '', string $childRef = '', string $status = ''): JSONResponse {
		$staff = $this->deniedToStaff();
		if ($staff instanceof JSONResponse) {
			return $staff;
		}

		return $this->answer(
			result: $this->activities->mark(
				activityId: $id,
				sessionId: $sessionId,
				childRef: $childRef,
				status: $status,
				markedByRef: $staff->getUID()
			)
		);
	}//end attendance()

	/**
	 * The caller when they may manage activities, else the refusal.
	 *
	 * @return IUser|JSONResponse
	 */
	private function deniedToStaff(): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return $user;
	}//end deniedToStaff()

	/**
	 * A service answer as a response: the row, or its refusal.
	 *
	 * @param array<string, mixed> $result The answer.
	 *
	 * @return JSONResponse
	 */
	private function answer(array $result): JSONResponse {
		if (isset($result['error']) === true && is_string($result['error']) === true && isset(self::STATUS[$result['error']]) === true) {
			return $this->refusal(reason: $result['error']);
		}

		return new JSONResponse(data: $result);
	}//end answer()

	/**
	 * The refusal for a reason.
	 *
	 * @param string $reason One of the REASON_ constants.
	 *
	 * @return JSONResponse
	 */
	private function refusal(string $reason): JSONResponse {
		return new JSONResponse(data: ['error' => $reason], statusCode: self::STATUS[$reason]);
	}//end refusal()
}//end class
