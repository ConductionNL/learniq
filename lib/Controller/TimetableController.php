<?php

/**
 * Learniq Personal Timetable Controller
 *
 * Read-only personal timetable: returns the signed-in caller's own scheduled
 * `Session` objects for a time window. The caller's sessions are resolved by
 * first determining the cohorts the caller belongs to — as a teacher via
 * `Cohort.teacherIds`, and as a learner via `Cohort.learnerIds` and/or
 * `Enrolment.cohortId` — then returning the `Session` objects whose `cohortId`
 * is one of those cohorts and whose `startsAt`/`endsAt` overlap the requested
 * window, ordered by `startsAt`.
 *
 * All reads go through OpenRegister's `ObjectService` so RBAC and multitenancy
 * scope the result (ADR-022): the caller never receives a session for a cohort
 * they do not belong to, and a caller with no cohorts receives an empty
 * timetable (HTTP 200), never an error. This controller introduces NO new
 * schema or storage and NEVER creates or mutates an object — it only reads the
 * existing `Session`, `Cohort`, `Room`, and `Enrolment` objects.
 *
 * timetabling-and-substitution additionally projects each Session's `roomId`
 * (with resolved `Room` name/capacity/facilities when set), `lifecycle`,
 * `substituteTeacherId`, `changeReasonKind`, and `changeReason`, plus a
 * same-day `changes` list (Sessions whose `cancel`/`substitute-teacher`
 * transition — server-stamped onto `changedAt` — occurred today, regardless
 * of the requested window) — the "dagrooster" surface. Still read-only: no
 * new write endpoint, no new schema.
 *
 * sessions-from-planninq: the sessions come from the current timetable
 * source ({@see \OCA\Learniq\Timetabling\Source\TimetableSourceResolver}):
 * planninq's school timetable when planninq is installed (decision D10),
 * learniq's own `Session` otherwise. `cohort()` serves the cohort timetable
 * page the same way, after an RBAC read of the cohort.
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\TimetableProjector;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Personal timetable read surface over existing Session/Cohort/Enrolment objects.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-the-timetable-is-a-read-surface-only-over-existing-objects
 */
class TimetableController extends Controller {
	/**
	 * OpenRegister register slug that owns the Learniq schemas.
	 *
	 * @var string
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param IUserSession $userSession Current user session.
	 * @param ObjectService $objectService OR object query service (RBAC-scoped).
	 * @param TimetableProjector $projector Window resolution and Session projection.
	 * @param TimetableSourceResolver $sources Where sessions are read from: planninq when installed, else Session.
	 * @param LoggerInterface $logger Application logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
		private readonly TimetableProjector $projector,
		private readonly TimetableSourceResolver $sources,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the caller's own sessions for a time window.
	 *
	 * The window defaults to the current ISO week (Monday 00:00 → Sunday
	 * 23:59:59, UTC) when `from`/`to` are not supplied. Cohort membership is
	 * resolved from the caller's Nextcloud user id against `Cohort.teacherIds`,
	 * `Cohort.learnerIds` and `Enrolment.cohortId`. A caller with no cohorts
	 * receives an empty list (HTTP 200).
	 *
	 * @param string|null $from Inclusive ISO 8601 window start (optional).
	 * @param string|null $to Exclusive ISO 8601 window end (optional).
	 *
	 * @return JSONResponse The ordered session list plus the resolved window.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function mine(?string $from = null, ?string $to = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$uid = $user->getUID();

		[$windowFrom, $windowTo] = $this->projector->resolveWindow(from: $from, to: $to);

		$cohortIds = $this->resolveCallerCohortIds(uid: $uid);
		$source = $this->sources->current();

		// With planninq a teacher can have lessons of their own, and with
		// learniq's own sessions a substitute has the lessons they cover
		// (learniq#1134): both come from sessionsForTeacher(), so a caller
		// without cohorts is still asked for them.
		try {
			$cohortSessions = $source->sessionsForCohorts(cohortIds: $cohortIds, from: $windowFrom, to: $windowTo);
			$teacherSessions = $source->sessionsForTeacher(userId: $uid, from: $windowFrom, to: $windowTo);
		} catch (RuntimeException $e) {
			return $this->sourceUnavailable(message: $e->getMessage(), from: $windowFrom, to: $windowTo);
		}

		// A caller with no lessons at all gets an empty timetable, not an error.
		if (empty($cohortSessions) === true && empty($teacherSessions) === true) {
			$this->logger->debug(
				'[TimetableController] No sessions resolved for {uid}; returning empty timetable.',
				['uid' => $uid, 'from' => $windowFrom, 'to' => $windowTo]
			);
			return new JSONResponse(
				data: ['sessions' => [], 'from' => $windowFrom, 'to' => $windowTo, 'changes' => [], 'source' => $source->name()],
				statusCode: Http::STATUS_OK
			);
		}

		$rawSessions = $this->mergeById(first: $cohortSessions, second: $teacherSessions);

		$roomCache = $this->preloadRooms(sessions: $rawSessions);

		$sessions = $this->projector->windowedSessions(
			rawSessions: $rawSessions,
			windowFrom: $windowFrom,
			windowTo: $windowTo,
			roomCache: $roomCache
		);
		$changes = $this->projector->todaysChanges(rawSessions: $rawSessions, roomCache: $roomCache);

		return new JSONResponse(
			data: ['sessions' => $sessions, 'from' => $windowFrom, 'to' => $windowTo, 'changes' => $changes, 'source' => $source->name()],
			statusCode: Http::STATUS_OK
		);
	}//end mine()

	/**
	 * Return one cohort's sessions for a window, from the current timetable source.
	 *
	 * The cohort is read first, through OpenRegister with RBAC on: a caller who
	 * cannot read it gets 403 and no session is read. The window defaults to
	 * eight weeks from this week's Monday, so a year of lessons is not loaded
	 * at once.
	 *
	 * @param string      $cohortId The cohort UUID.
	 * @param string|null $from     Inclusive ISO 8601 window start (optional).
	 * @param string|null $to       Exclusive ISO 8601 window end (optional).
	 *
	 * @return JSONResponse 200 with sessions; 401 without a user; 403 when the cohort cannot be read; 503 when the source does not answer.
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function cohort(string $cohortId, ?string $from = null, ?string $to = null): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->canReadCohort(cohortId: $cohortId) === false) {
			return new JSONResponse(
				data: ['error' => 'This group cannot be found, or you cannot see it.'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		if (($to === null || trim($to) === '') && ($from === null || trim($from) === '')) {
			[$from] = $this->projector->resolveWindow(from: null, to: null);
			$to = gmdate(DATE_ATOM, ((int)strtotime($from) + (56 * 86400)));
		}

		[$windowFrom, $windowTo] = $this->projector->resolveWindow(from: $from, to: $to);
		$source = $this->sources->current();

		try {
			$rawSessions = $source->sessionsForCohorts(cohortIds: [$cohortId], from: $windowFrom, to: $windowTo);
		} catch (RuntimeException $e) {
			return $this->sourceUnavailable(message: $e->getMessage(), from: $windowFrom, to: $windowTo);
		}

		$sessions = $this->projector->windowedSessions(
			rawSessions: $rawSessions,
			windowFrom: $windowFrom,
			windowTo: $windowTo,
			roomCache: $this->preloadRooms(sessions: $rawSessions)
		);

		return new JSONResponse(
			data: ['sessions' => $sessions, 'from' => $windowFrom, 'to' => $windowTo, 'source' => $source->name()],
			statusCode: Http::STATUS_OK
		);
	}//end cohort()

	/**
	 * Whether the caller can read the cohort, through OpenRegister RBAC.
	 *
	 * Fails closed: a missing cohort, a refused read or an error all answer false.
	 *
	 * @param string $cohortId The cohort UUID.
	 *
	 * @return bool
	 */
	private function canReadCohort(string $cohortId): bool {
		try {
			$cohort = $this->objectService->find(id: $cohortId, register: self::LEARNIQ_REGISTER, schema: 'cohort');
		} catch (\Exception $e) {
			$this->logger->debug('[TimetableController] Cohort {id} not readable: {msg}', ['id' => $cohortId, 'msg' => $e->getMessage()]);
			return false;
		}

		return $cohort !== null;
	}//end canReadCohort()

	/**
	 * A 503 answer when the timetable source does not answer.
	 *
	 * @param string $message Why the source did not answer.
	 * @param string $from    The resolved window start.
	 * @param string $to      The resolved window end.
	 *
	 * @return JSONResponse
	 */
	private function sourceUnavailable(string $message, string $from, string $to): JSONResponse {
		$this->logger->warning('[TimetableController] Timetable source unavailable: {msg}', ['msg' => $message]);

		return new JSONResponse(
			data: ['error' => 'The timetable could not be read.', 'sessions' => [], 'from' => $from, 'to' => $to, 'changes' => []],
			statusCode: Http::STATUS_SERVICE_UNAVAILABLE
		);
	}//end sourceUnavailable()

	/**
	 * Merge two session lists, keeping the first occurrence of each id.
	 *
	 * @param array<int,array<string,mixed>> $first  Sessions read by cohort.
	 * @param array<int,array<string,mixed>> $second Sessions read by teacher.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function mergeById(array $first, array $second): array {
		$merged = [];
		foreach (array_merge($first, $second) as $index => $session) {
			$key = (string)($session['id'] ?? ($session['uuid'] ?? ''));
			if ($key === '') {
				$key = '#' . $index;
			}

			if (isset($merged[$key]) === false) {
				$merged[$key] = $session;
				continue;
			}

			// A lesson of the caller's own cohort that they also cover keeps
			// its cohort row and gains the cover mark (learniq#1134).
			if (($session['cover'] ?? false) === true) {
				$merged[$key]['cover'] = true;
			}
		}

		return array_values($merged);
	}//end mergeById()

	/**
	 * Resolve the set of cohort UUIDs the caller belongs to.
	 *
	 * Teacher membership: the caller's uid appears in `Cohort.teacherIds`.
	 * Learner membership: the caller's uid appears in `Cohort.learnerIds`, or
	 * the caller has an `Enrolment` whose `learnerId` is the caller and whose
	 * `cohortId` is set. All reads are RBAC/multitenancy-scoped by ObjectService.
	 *
	 * @param string $uid The caller's Nextcloud user id.
	 *
	 * @return array<int,string> The unique cohort UUIDs (may be empty).
	 */
	private function resolveCallerCohortIds(string $uid): array {
		$cohortIds = [];

		// Cohorts where the caller is a teacher or a listed learner. teacherIds
		// and learnerIds are arrays, so membership is filtered in PHP over the
		// RBAC-scoped cohort set rather than via an equality filter.
		$cohorts = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => 'cohort',
				],
			]
		);

		foreach ($cohorts as $row) {
			$cohort = $this->toArray(row: $row);
			$teacherIds = $this->toStringList(value: ($cohort['teacherIds'] ?? []));
			$learnerIds = $this->toStringList(value: ($cohort['learnerIds'] ?? []));

			if (in_array($uid, $teacherIds, true) === true || in_array($uid, $learnerIds, true) === true) {
				$cohortId = (string)($cohort['id'] ?? ($cohort['uuid'] ?? ''));
				if ($cohortId !== '') {
					$cohortIds[$cohortId] = true;
				}
			}
		}

		// Cohorts reached through the caller's own enrolments.
		$enrolments = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => 'enrolment',
					'learnerId' => $uid,
				],
			]
		);

		foreach ($enrolments as $row) {
			$enrolment = $this->toArray(row: $row);
			// Defensive: the RBAC-scoped filter should already guarantee this,
			// but never trust a mismatched learnerId to reach another's cohort.
			if ((string)($enrolment['learnerId'] ?? '') !== $uid) {
				continue;
			}

			$cohortId = (string)($enrolment['cohortId'] ?? '');
			if ($cohortId !== '') {
				$cohortIds[$cohortId] = true;
			}
		}

		return array_keys($cohortIds);
	}//end resolveCallerCohortIds()

	/**
	 * Pre-load every distinct Room referenced by `roomId` across the given
	 * raw sessions, so the projection step never issues an N+1 query.
	 *
	 * @param array<int,array<string,mixed>> $sessions Raw session data arrays.
	 *
	 * @return array<string,array<string,mixed>> Room data keyed by room UUID.
	 */
	private function preloadRooms(array $sessions): array {
		$roomIds = [];
		foreach ($sessions as $session) {
			$roomId = (string)($session['roomId'] ?? '');
			if ($roomId !== '') {
				$roomIds[$roomId] = true;
			}
		}

		$rooms = [];
		foreach (array_keys($roomIds) as $roomId) {
			$results = $this->objectService->findAll(
				[
					'ids' => [$roomId],
					'filters' => [
						'register' => self::LEARNIQ_REGISTER,
						'schema' => 'room',
					],
					'limit' => 1,
				]
			);

			if (empty($results) === false) {
				$rooms[$roomId] = $this->toArray(row: $results[0]);
			}
		}

		return $rooms;
	}//end preloadRooms()

	/**
	 * Normalise an ObjectService row (entity or array) to a plain array.
	 *
	 * @param mixed $row The row returned by ObjectService::findAll.
	 *
	 * @return array<string,mixed> The serialized object data.
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

	/**
	 * Coerce a schema array-of-strings value into a list of strings.
	 *
	 * @param mixed $value The raw property value.
	 *
	 * @return array<int,string> The string list (empty when not an array).
	 */
	private function toStringList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $item) {
			if (is_string($item) === true || is_numeric($item) === true) {
				$out[] = (string)$item;
			}
		}

		return $out;
	}//end toStringList()
}//end class
