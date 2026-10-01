<?php

/**
 * Learniq Elective Slots Controller
 *
 * `GET /api/timetable/course-slots?courseIds=a,b&withCore=1`: the weekly time
 * slots of the electives a learner is choosing, and (for a learner choosing
 * for themselves) their own core lessons, so the subject choice picker can
 * show when each elective meets and warn about overlaps
 * (timetabling-student-choice-placement).
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-clash-warning-when-choosing
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ElectiveSlotService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Serves elective and core-lesson slots to the subject choice picker.
 */
class ElectiveSlotsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest            $request     HTTP request.
	 * @param IUserSession        $userSession The signed-in user.
	 * @param ElectiveSlotService $slots       Slots of courses and of the caller's lessons.
	 * @param IDateTimeZone       $zone        The reader's time zone.
	 * @param ITimeFactory        $time        The clock.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ElectiveSlotService $slots,
		private readonly IDateTimeZone $zone,
		private readonly ITimeFactory $time,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The weekly slots of the given courses, and of the caller's own lessons when asked.
	 *
	 * Courses the caller cannot read are left out. Each course is read with
	 * the caller's OpenRegister rights before any of its lessons.
	 *
	 * @param string $courseIds Comma-separated course UUIDs (at most 20).
	 * @param string $withCore  `1` to add the caller's own other lessons.
	 *
	 * @return JSONResponse `{courses: {id: slots}, core: slots}`; 401 without a user; 400 for too many courses; 503 when the source does not answer.
	 *
	 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-clash-warning-when-choosing
	 */
	#[NoAdminRequired]
	public function slots(string $courseIds = '', string $withCore = '0'): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$ids = array_values(array_filter(array_map('trim', explode(',', $courseIds)), static fn (string $id): bool => $id !== ''));
		if (count($ids) > ElectiveSlotService::MAX_COURSES) {
			return new JSONResponse(data: ['error' => 'Ask for at most 20 courses at once.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$data = $this->slots->slots(
				uid: $user->getUID(),
				courseIds: $ids,
				withCore: ($withCore === '1'),
				zone: $this->zone->getTimeZone(),
				now: $this->time->getTime()
			);
		} catch (RuntimeException) {
			return new JSONResponse(data: ['error' => 'The timetable could not be read.'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(data: $data);
	}//end slots()
}//end class
