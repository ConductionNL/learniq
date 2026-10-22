<?php

/**
 * Learniq Timetable Visibility Controller
 *
 * Another group's, teacher's or room's timetable, as far as the school's
 * policy allows the caller (timetabling-visibility-rules):
 * - `GET /api/timetable/of?kind=cohort|teacher|room&id=&from=&to=` projects
 *   that timetable, or answers 403 with the reason;
 * - `GET /api/timetable/of/options?kind=` lists what the caller may open.
 * The policy check is in the method body (gate 7), against the caller's own
 * timetable, which the register grammar cannot express.
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\TimetableDirectory;
use OCA\Learniq\Service\TimetableProjector;
use OCA\Learniq\Service\TimetableVisibilityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Serves other timetables within the school's visibility policy.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
 */
class TimetableVisibilityController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request     HTTP request.
	 * @param IUserSession               $userSession The caller.
	 * @param TimetableVisibilityService $visibility  The policy and the lessons.
	 * @param TimetableProjector         $projector   Window and lesson shape, as the personal timetable.
	 * @param TimetableDirectory         $directory   The lessons of a group, teacher or room.
	 * @param IL10N|null                 $l10n        Kind names in the user's language.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly TimetableVisibilityService $visibility,
		private readonly TimetableProjector $projector,
		private readonly TimetableDirectory $directory,
		private readonly ?IL10N $l10n = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * One group's, teacher's or room's lessons in a window.
	 *
	 * @param string      $kind `cohort`, `teacher` or `room`.
	 * @param string      $id   The cohort, teacher or room id.
	 * @param string|null $from Inclusive window start (defaults to this week).
	 * @param string|null $to   Exclusive window end.
	 * @param string      $target `<kind>:<id>` in place of kind and id, as the page's picker writes it.
	 *
	 * @return JSONResponse 200 with `sessions` as the personal timetable; 401 or 403 when refused; 503 when the timetable cannot be read.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function timetable(string $kind = '', string $id = '', ?string $from = null, ?string $to = null, string $target = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($kind === '' && $id === '' && str_contains($target, ':') === true) {
			[$kind, $id] = explode(':', $target, 2);
		}

		if ($this->visibility->mayOpen(uid: $user->getUID(), kind: $kind, id: $id) === false) {
			return new JSONResponse(
				data: ['error' => 'Your school does not let you see this timetable.'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		[$windowFrom, $windowTo] = $this->projector->resolveWindow(from: $from, to: $to);
		try {
			$lessons = $this->directory->lessonsOf(kind: $kind, id: $id, from: $windowFrom, to: $windowTo);
		} catch (RuntimeException $exception) {
			return new JSONResponse(
				data: ['error' => 'The timetable could not be read.', 'sessions' => [], 'from' => $windowFrom, 'to' => $windowTo],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$sessions = $this->projector->windowedSessions(
			rawSessions: $lessons['sessions'],
			windowFrom: $windowFrom,
			windowTo: $windowTo,
			roomCache: []
		);

		$data = ['sessions' => $sessions, 'from' => $windowFrom, 'to' => $windowTo, 'source' => $lessons['source'], 'kind' => $kind, 'id' => $id];
		return new JSONResponse(data: $data, statusCode: Http::STATUS_OK);
	}//end timetable()

	/**
	 * What the caller may open for a kind, or for every kind when none is named.
	 *
	 * @param string $kind `cohort`, `teacher` or `room`; empty for all three.
	 *
	 * @return JSONResponse 200 with `options` (and the policy `scope` for one kind); 400 for an unknown kind; 401 without a user.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function options(string $kind = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($kind === '') {
			return new JSONResponse(data: ['options' => $this->allOptions(uid: $user->getUID())], statusCode: Http::STATUS_OK);
		}

		if (in_array($kind, TimetableVisibilityService::KINDS, true) === false) {
			return new JSONResponse(data: ['error' => 'Pick a group, a teacher or a room.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$uid = $user->getUID();
		return new JSONResponse(
			data: ['options' => $this->visibility->options(uid: $uid, kind: $kind), 'scope' => $this->visibility->scope(uid: $uid, kind: $kind)],
			statusCode: Http::STATUS_OK
		);
	}//end options()

	/**
	 * What the caller may open of every kind, as `{id: "<kind>:<id>", label}`,
	 * the label led by the kind ("Group: 1A"): groups, then teachers, then rooms.
	 *
	 * @param string $uid The caller.
	 *
	 * @return array<int,array{id:string,label:string}>
	 */
	private function allOptions(string $uid): array {
		$out = [];
		foreach (['cohort' => 'Group', 'teacher' => 'Teacher', 'room' => 'Room'] as $kind => $name) {
			$prefix = $name;
			if ($this->l10n !== null) {
				$prefix = $this->l10n->t($name);
			}

			foreach ($this->visibility->options(uid: $uid, kind: $kind) as $option) {
				$out[] = ['id' => $kind . ':' . $option['id'], 'label' => $prefix . ': ' . $option['label']];
			}
		}

		return $out;
	}//end allOptions()
	/**
	 * The school's visibility policy, the object that holds it, and whether the caller may change it.
	 *
	 * @return JSONResponse 200 with `policy`, `policyId` (or null) and `canEdit`; 401 without a user.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function policy(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			data: [
				'policy' => $this->visibility->policy(),
				'policyId' => $this->visibility->policyId(),
				'canEdit' => $this->visibility->role(uid: $user->getUID()) === 'all',
			],
			statusCode: Http::STATUS_OK
		);
	}//end policy()
}//end class
