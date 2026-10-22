<?php

/**
 * Learniq Standby Controller
 *
 * Standby hours (timetabling-standby-slots):
 * - `GET /api/substitution/candidates?sessionId=`: the teachers who can cover
 *   a lesson, standby first. Open to the callers SessionChangeGuard lets assign
 *   a substitute: a teacher of the lesson's cohort, or a member of `admin` or
 *   `coordinators` (check in the body, gate 7).
 * - `GET /api/standby/mine?from=&to=`: the caller's own standby blocks in a
 *   window, for their personal timetable. Every signed-in user reads standby
 *   slots (StandbySlot authorization), and this returns only the caller's.
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
 * @spec openspec/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\StandbyCalendar;
use OCA\Learniq\Service\SubstitutionCandidateService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Serves the substitution candidates of a lesson and the caller's standby blocks.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
 */
class StandbyController extends Controller {

	/**
	 * Groups that may assign a substitute to any lesson, as in SessionChangeGuard.
	 */
	private const OVERRIDE_GROUPS = ['admin', 'coordinators'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request       HTTP request.
	 * @param IUserSession                 $userSession   The caller.
	 * @param IGroupManager                $groupManager  Group checks.
	 * @param ObjectService                $objectService Reads the lesson and its cohort.
	 * @param SubstitutionCandidateService $candidates    Lists the candidates.
	 * @param StandbyCalendar              $calendar      A teacher's standby blocks.
	 * @param IL10N|null                   $l10n          The candidates' labels in the user's language.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ObjectService $objectService,
		private readonly SubstitutionCandidateService $candidates,
		private readonly StandbyCalendar $calendar,
		private readonly ?IL10N $l10n = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The teachers who can cover a lesson.
	 *
	 * @param string $sessionId The lesson (a learniq Session).
	 *
	 * @return JSONResponse 200 with `candidates`; 401 without a user; 403 for a caller who may not assign a substitute; 404 for an unknown lesson.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function candidates(string $sessionId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$session = $this->load(schema: 'session', id: $sessionId);
		if ($session === null) {
			return new JSONResponse(data: ['error' => 'This lesson cannot be found.'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$cohort = ($this->load(schema: 'cohort', id: (string)($session['cohortId'] ?? '')) ?? []);
		if ($this->mayAssign(uid: $user->getUID(), cohort: $cohort) === false) {
			return new JSONResponse(
				data: ['error' => 'Only the teachers of this group and coordinators can assign a substitute.'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$candidates = array_map(
			fn (array $candidate): array => array_merge($candidate, ['label' => $this->candidateLabel(candidate: $candidate)]),
			$this->candidates->forSession(session: $session, cohort: $cohort)
		);

		return new JSONResponse(data: ['candidates' => $candidates], statusCode: Http::STATUS_OK);
	}//end candidates()

	/**
	 * A candidate as the picker shows it: the name and why they are listed,
	 * "Eva (On standby 10:15 to 11:05)".
	 *
	 * @param array<string,mixed> $candidate A candidate row.
	 *
	 * @return string
	 */
	private function candidateLabel(array $candidate): string {
		$texts = [
			'standby' => ['On standby %1$s to %2$s', [(string)($candidate['from'] ?? ''), (string)($candidate['to'] ?? '')]],
			'busy' => ['On standby, but has a lesson then', []],
		];
		[$text, $vars] = ($texts[$candidate['group'] ?? ''] ?? ['Free then', []]);
		$reason = vsprintf($text, $vars);
		if ($this->l10n !== null) {
			$reason = $this->l10n->t($text, $vars);
		}

		return (string)($candidate['displayName'] ?? '') . ' (' . $reason . ')';
	}//end candidateLabel()

	/**
	 * The caller's own standby blocks in a window.
	 *
	 * @param string $from Window start, ISO 8601.
	 * @param string $to   Window end (exclusive), ISO 8601.
	 *
	 * @return JSONResponse 200 with `standby`; 400 for a missing window; 401 without a user.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function mine(string $from = '', string $to = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($from === '' || $to === '') {
			return new JSONResponse(data: ['error' => 'Name the window with from and to.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: ['standby' => $this->calendar->blocksFor(uid: $user->getUID(), from: $from, to: $to)], statusCode: Http::STATUS_OK);
	}//end mine()

	/**
	 * Whether the caller may assign a substitute to a lesson of this cohort.
	 *
	 * @param string              $uid    The caller.
	 * @param array<string,mixed> $cohort The lesson's cohort.
	 *
	 * @return bool
	 */
	private function mayAssign(string $uid, array $cohort): bool {
		foreach (self::OVERRIDE_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return in_array($uid, (array)($cohort['teacherIds'] ?? []), true);
	}//end mayAssign()

	/**
	 * One learniq object, or null. Read without the caller's RBAC: mayAssign() decides.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id     Object uuid.
	 *
	 * @return array<string,mixed>|null
	 */
	private function load(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(id: $id, register: 'learniq', schema: $schema, _rbac: false);
		} catch (Throwable $exception) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return (array)$object->jsonSerialize();
	}//end load()
}//end class
