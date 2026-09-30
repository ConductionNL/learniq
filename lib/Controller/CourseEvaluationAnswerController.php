<?php

/**
 * Learniq Course Evaluation Answer Controller
 *
 * `GET /api/evaluations/mine` lists the caller's open evaluation
 * invitations; `POST /api/evaluations/{invitationId}/answer` answers one of
 * them (the caller's own only). `GET /api/evaluations/campaigns/{campaignId}/results`
 * gives staff a campaign's invitation and response counts and the mean
 * overall score, withheld under five responses; a learner gets 403.
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
 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-learner-answers-an-evaluation
 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-campaign-results-for-staff
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use DateTimeImmutable;
use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\CourseEvaluationAnswerService;
use OCA\Learniq\Service\DashboardRoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The learner's evaluation page and the staff result figures.
 *
 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-learner-answers-an-evaluation
 */
class CourseEvaluationAnswerController extends Controller {

	/**
	 * Learniq views (DashboardRoleService::resolveViews) that run evaluations.
	 */
	private const STAFF_VIEWS = ['admin', 'teacher'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request              The request.
	 * @param IUserSession                  $userSession          The caller.
	 * @param CourseEvaluationAnswerService $answers              Lists, answers and sums up invitations.
	 * @param DashboardRoleService          $dashboardRoleService Staff check.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly CourseEvaluationAnswerService $answers,
		private readonly DashboardRoleService $dashboardRoleService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The caller's open invitations. Only the caller's own: the learner id
	 * comes from the session, never from the request.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function mine(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: ['invitations' => $this->answers->openInvitations(learnerId: $user->getUID(), now: new DateTimeImmutable())]);
	}//end mine()

	/**
	 * Answer one of the caller's invitations. The service refuses an
	 * invitation that is not the caller's (404), answered or closed (409) or
	 * answers that miss a required question (422).
	 *
	 * @param string $invitationId The invitation.
	 * @param array  $answers      Map of questionId to rating or text.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-cannot-answer-twice
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-an-uninvited-user-is-refused
	 */
	#[NoAdminRequired]
	public function answer(string $invitationId, array $answers = []): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->answers->answer(learnerId: $user->getUID(), invitationId: $invitationId, answers: $answers, now: new DateTimeImmutable());
		$status = $result['status'];
		unset($result['status']);

		return new JSONResponse(data: $result, statusCode: $status);
	}//end answer()

	/**
	 * A campaign's result figures, for staff only.
	 *
	 * @param string $campaignId The campaign.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-small-groups-are-protected
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function results(string $campaignId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$views = $this->dashboardRoleService->resolveViews(user: $user);
		if (count(array_intersect($views, self::STAFF_VIEWS)) === 0) {
			return new JSONResponse(data: ['error' => 'Only staff can read evaluation results.'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$results = $this->answers->results(campaignId: $campaignId);
		if ($results === null) {
			return new JSONResponse(data: ['error' => 'Campaign not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $results);
	}//end results()
}//end class
