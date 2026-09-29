<?php

/**
 * Learniq Work Group Controller
 *
 * Work groups for the signed-in learner in the app
 * (enrolment-self-join-work-group): the overview of their classes' work
 * groups, join (or move), and leave. Every method is `#[NoAdminRequired]`
 * with its check in the body: the caller acts only for themselves, and
 * WorkGroupMembershipService checks cohort membership, the sign-up date, the
 * free place and one group per set.
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
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Service\WorkGroup\WorkGroupMembershipService;
use OCA\Learniq\Service\WorkGroup\WorkGroupMessages;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The learner's work groups.
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */
class WorkGroupController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request     The request.
	 * @param IUserSession               $userSession The signed-in user.
	 * @param WorkGroupMembershipService $memberships The overview and the join, move and leave rules.
	 * @param WorkGroupMessages          $messages    Plain refusal reasons.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly WorkGroupMembershipService $memberships,
		private readonly WorkGroupMessages $messages,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The work groups of the caller's classes.
	 *
	 * @return JSONResponse 200 `{sets}`, or 401.
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	#[NoAdminRequired]
	public function mine(): JSONResponse {
		$learner = $this->learner();
		if ($learner === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return $this->answer(outcome: $this->memberships->mine(learner: $learner), learner: $learner);
	}//end mine()

	/**
	 * Join (or move to) a work group.
	 *
	 * @param string $id The WorkGroup uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-a-learner-joins-a-group
	 */
	#[NoAdminRequired]
	public function join(string $id): JSONResponse {
		$learner = $this->learner();
		if ($learner === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return $this->answer(outcome: $this->memberships->join(learner: $learner, groupId: $id), learner: $learner);
	}//end join()

	/**
	 * Leave a work group.
	 *
	 * @param string $id The WorkGroup uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	#[NoAdminRequired]
	public function leave(string $id): JSONResponse {
		$learner = $this->learner();
		if ($learner === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return $this->answer(outcome: $this->memberships->leave(learner: $learner, groupId: $id), learner: $learner);
	}//end leave()

	/**
	 * The signed-in user as a learner.
	 *
	 * @return PortalLearner|null
	 */
	private function learner(): ?PortalLearner {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return new PortalLearner(profileRef: '', ncUserId: $user->getUID(), tenantId: '', user: $user);
	}//end learner()

	/**
	 * An outcome as a response, with the reason in the learner's words.
	 *
	 * @param PortalOutcome $outcome The outcome.
	 * @param PortalLearner $learner The learner.
	 *
	 * @return JSONResponse
	 */
	private function answer(PortalOutcome $outcome, PortalLearner $learner): JSONResponse {
		$body = $outcome->body;
		if ($outcome->reason !== null) {
			$body['message'] = $this->messages->message(reason: $outcome->reason, user: $learner->user);
		}

		return new JSONResponse(data: $body, statusCode: $outcome->status);
	}//end answer()
}//end class
