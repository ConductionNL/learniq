<?php

/**
 * Learniq Exam Schedule Controller
 *
 * Two read endpoints for the exam planner: the overview of one sitting
 * (accommodations and invigilator places) and the colleagues available to
 * invigilate it. Instructors, HR, compliance officers, team leads and admins
 * only, checked in each method; everyone else gets 403. The sitting itself is
 * read under the caller's OpenRegister access rules, so a sitting the caller
 * cannot read is 404.
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
 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-accommodations-in-the-schedule
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ExamSittingOverview;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Sitting overview and available invigilators.
 *
 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-accommodations-in-the-schedule
 */
class ExamScheduleController extends Controller {

	/**
	 * Groups that plan exams.
	 */
	private const PLANNER_GROUPS = ['instructors', 'hr', 'compliance-officers', 'team-leads'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ExamSittingOverview $overview Sitting overview reads.
	 * @param IUserSession $userSession The caller.
	 * @param IGroupManager $groupManager Group membership.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ExamSittingOverview $overview,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Accommodations and invigilator places for one sitting.
	 *
	 * @param string $id The ExamSitting uuid.
	 *
	 * @return JSONResponse 200 `{sitting, accommodations, invigilators}`, 403 or 404.
	 *
	 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-accommodations-in-the-schedule
	 */
	#[NoAdminRequired]
	public function overview(string $id): JSONResponse {
		if ($this->isPlanner() === false) {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$data = $this->overview->overview(sittingId: $id);
		if ($data === null) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $data);
	}//end overview()

	/**
	 * Colleagues available for the whole sitting and not already asked.
	 *
	 * @param string $id The ExamSitting uuid.
	 *
	 * @return JSONResponse 200 `{invigilators}`, 403 or 404.
	 *
	 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-invigilator-assignment
	 */
	#[NoAdminRequired]
	public function availableInvigilators(string $id): JSONResponse {
		if ($this->isPlanner() === false) {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$people = $this->overview->availableInvigilators(sittingId: $id);
		if ($people === null) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['invigilators' => $people]);
	}//end availableInvigilators()

	/**
	 * Whether the caller is an admin or in a planner group.
	 *
	 * @return bool
	 */
	private function isPlanner(): bool {
		$userId = (string)$this->userSession->getUser()?->getUID();
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		foreach (self::PLANNER_GROUPS as $group) {
			if ($this->groupManager->isInGroup($userId, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isPlanner()
}//end class
