<?php

/**
 * Learniq Hour Plan Controller
 *
 * `GET /api/hour-plans/activities?academicYear=YYYY-YYYY`: the teaching
 * activities a school year needs, derived from the hour plans, for the
 * activities page, its CSV export, and a timetabler's tools. Readable by
 * `instructors`, `team-leads` and `compliance-officers` (and admins); the
 * check is in the method body (gate 7).
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
 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\HourPlanActivityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves the teaching activities of a school year to staff.
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
 */
class HourPlanController extends Controller {

	/**
	 * Groups that read the activity list.
	 */
	private const READER_GROUPS = ['instructors', 'team-leads', 'compliance-officers'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request      HTTP request.
	 * @param IUserSession            $userSession  The caller.
	 * @param IGroupManager           $groupManager Group checks.
	 * @param HourPlanActivityService $activities   Derives the activities.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly HourPlanActivityService $activities,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The teaching activities of a school year.
	 *
	 * @param string $academicYear The school year, `YYYY-YYYY`.
	 *
	 * @return JSONResponse 200 with `activities` and `cohortsWithoutPlan`; 400 for an unreadable year; 401 without a user; 403 outside the staff groups.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function activities(string $academicYear = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->mayRead(uid: $user->getUID()) === false) {
			return new JSONResponse(
				data: ['error' => 'Only teachers, team leads and compliance officers can read the teaching activities.'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		if ($this->activities->intakeYearOf(academicYear: $academicYear, programmeYear: 1) === null) {
			return new JSONResponse(data: ['error' => 'The school year must read like 2026-2027.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $this->activities->forYear(academicYear: $academicYear), statusCode: Http::STATUS_OK);
	}//end activities()

	/**
	 * Whether the caller is an admin or in a reader group.
	 *
	 * @param string $uid The caller.
	 *
	 * @return bool
	 */
	private function mayRead(string $uid): bool {
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		foreach (self::READER_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end mayRead()
}//end class
