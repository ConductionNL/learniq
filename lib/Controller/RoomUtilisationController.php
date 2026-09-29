<?php

/**
 * Learniq Room Utilisation Controller
 *
 * The room use report (timetabling-room-utilisation):
 * `GET /api/reports/room-use?from=&to=&kind=&building=` for `instructors`,
 * `team-leads` and `compliance-officers`, and the opening hours it counts from,
 * readable by the same groups and written by `team-leads` and
 * `compliance-officers` at `GET|PUT /api/reports/room-use/opening-hours`.
 * Every group check is in the method body (gate 7).
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
 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\OpeningHoursSettings;
use OCA\Learniq\Service\RoomUtilisationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Serves the room use report and its opening hours.
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 */
class RoomUtilisationController extends Controller {

	/**
	 * Groups that read the report.
	 */
	private const READERS = ['instructors', 'team-leads', 'compliance-officers'];

	/**
	 * Groups that change the opening hours.
	 */
	private const WRITERS = ['team-leads', 'compliance-officers'];

	/**
	 * The longest window one report covers, in days.
	 */
	private const MAX_DAYS = 400;

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request      HTTP request.
	 * @param IUserSession           $userSession  The caller.
	 * @param IGroupManager          $groupManager Group checks.
	 * @param RoomUtilisationService $report       Computes room use.
	 * @param OpeningHoursSettings   $openingHours Opening hours per weekday.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly RoomUtilisationService $report,
		private readonly OpeningHoursSettings $openingHours,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Room use over a period.
	 *
	 * @param string      $from     First day, `Y-m-d`.
	 * @param string      $to       Last day, `Y-m-d` (inclusive).
	 * @param string|null $kind     Only rooms of this kind.
	 * @param string|null $building Only rooms in this building.
	 *
	 * @return JSONResponse 200 with the report; 400 for a bad window; 401 or 403 for the wrong caller; 503 when the timetable cannot be read.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function report(string $from = '', string $to = '', ?string $kind = null, ?string $building = null): JSONResponse {
		$refusal = $this->refusal(groups: self::READERS);
		if ($refusal !== null) {
			return $refusal;
		}

		$start = $this->report->parseDay(day: $from);
		$last = $this->report->parseDay(day: $to);
		if ($start === null || $last === null || $last < $start || $start->diff($last)->days > self::MAX_DAYS) {
			return new JSONResponse(
				data: ['error' => 'Pick a first and a last day, at most 400 days apart.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$data = $this->report->forPeriod(
				from: $start->format('Y-m-d'),
				to: $last->modify('+1 day')->format('Y-m-d'),
				kind: $kind,
				building: $building
			);
		} catch (RuntimeException $exception) {
			return new JSONResponse(
				data: ['error' => 'The timetable could not be read: ' . $exception->getMessage()],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse(data: $data, statusCode: Http::STATUS_OK);
	}//end report()

	/**
	 * The opening hours the report counts from.
	 *
	 * @return JSONResponse 200 with the opening hours and whether the caller may change them.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function openingHours(): JSONResponse {
		$refusal = $this->refusal(groups: self::READERS);
		if ($refusal !== null) {
			return $refusal;
		}

		$canEdit = $this->refusal(groups: self::WRITERS) === null;
		return new JSONResponse(data: ['openingHours' => $this->openingHours->get(), 'canEdit' => $canEdit], statusCode: Http::STATUS_OK);
	}//end openingHours()

	/**
	 * Change the opening hours.
	 *
	 * @param array<string,mixed> $openingHours The new opening hours.
	 *
	 * @return JSONResponse 200 with what was stored; 400 when invalid; 401/403 outside the writer groups.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	#[NoAdminRequired]
	public function saveOpeningHours(array $openingHours = []): JSONResponse {
		$refusal = $this->refusal(groups: self::WRITERS);
		if ($refusal !== null) {
			return $refusal;
		}

		$problem = $this->openingHours->validate(value: $openingHours);
		if ($problem !== null) {
			return new JSONResponse(data: ['error' => $problem], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: ['openingHours' => $this->openingHours->save(value: $openingHours)], statusCode: Http::STATUS_OK);
	}//end saveOpeningHours()

	/**
	 * A 401 or 403 answer when the caller is not in one of the groups, else null.
	 *
	 * @param array<int,string> $groups The allowed groups (admins always pass).
	 *
	 * @return JSONResponse|null
	 */
	private function refusal(array $groups): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) === true) {
			return null;
		}

		foreach ($groups as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return null;
			}
		}

		return new JSONResponse(
			data: ['error' => 'Your role cannot open the room use report or change its opening hours.'],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end refusal()
}//end class
