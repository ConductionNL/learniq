<?php

/**
 * Learniq Personal Timetable Service
 *
 * The caller's own lessons for a window: the cohorts they teach or learn in
 * (Cohort.teacherIds, Cohort.learnerIds, Enrolment.cohortId), those cohorts'
 * lessons and the lessons the caller teaches or covers, from the current
 * timetable source, projected for the caller. My timetable and the calendar
 * feed both read through here, so the two cannot show different lessons
 * (attendance-timetable-calendar-feed D2).
 *
 * Every read goes through OpenRegister's ObjectService as the active user, so
 * RBAC and multitenancy scope the result exactly as on the page.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolves and projects one user's own lessons for a window.
 */
class PersonalTimetableService {
	/**
	 * OpenRegister register slug that owns the Learniq schemas.
	 *
	 * @var string
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Enrolment lifecycles that no longer put a learner in a lesson.
	 *
	 * @var array<int,string>
	 */
	private const ENDED_ENROLMENT = ['withdrawn', 'completed', 'failed'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService           $objectService OR object query service (RBAC-scoped).
	 * @param TimetableProjector      $projector     Window resolution and Session projection.
	 * @param TimetableSourceResolver $sources       Where sessions are read from: planninq when installed, else Session.
	 * @param LoggerInterface         $logger        Application logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TimetableProjector $projector,
		private readonly TimetableSourceResolver $sources,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The user's own lessons in a resolved window, with today's changes.
	 *
	 * A user with no lessons at all gets empty lists, never an error.
	 *
	 * @param string $uid        The user's Nextcloud user id.
	 * @param string $windowFrom Inclusive window start (ISO 8601, already resolved).
	 * @param string $windowTo   Exclusive window end (ISO 8601, already resolved).
	 *
	 * @return array{sessions: array<int,array<string,mixed>>, changes: array<int,array<string,mixed>>, source: string}
	 *
	 * @throws RuntimeException When the timetable source does not answer.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
	 */
	public function forUser(string $uid, string $windowFrom, string $windowTo): array {
		$taughtCohortIds = [];
		$electiveCourseIds = [];
		$cohortIds = $this->resolveCallerCohortIds(uid: $uid, taught: $taughtCohortIds, electives: $electiveCourseIds);
		$source = $this->sources->current();

		// With planninq a teacher can have lessons of their own, and with
		// learniq's own sessions a substitute has the lessons they cover
		// (learniq#1134): both come from sessionsForTeacher(), so a caller
		// without cohorts is still asked for them.
		$cohortSessions = $source->sessionsForCohorts(cohortIds: $cohortIds, from: $windowFrom, to: $windowTo);
		$teacherSessions = $source->sessionsForTeacher(userId: $uid, from: $windowFrom, to: $windowTo);

		// Electives: a course the caller is enrolled in without a cohort (an
		// approved subject choice) brings that course's lessons, whichever
		// cohort holds them (timetabling-student-choice-placement D1, D3).
		if ($electiveCourseIds !== []) {
			$cohortSessions = array_merge(
				$cohortSessions,
				$source->sessionsForCourses(courseIds: $electiveCourseIds, from: $windowFrom, to: $windowTo)
			);
		}

		if (empty($cohortSessions) === true && empty($teacherSessions) === true) {
			$this->logger->debug(
				'[PersonalTimetableService] No sessions resolved for {uid}; returning empty timetable.',
				['uid' => $uid, 'from' => $windowFrom, 'to' => $windowTo]
			);
			return ['sessions' => [], 'changes' => [], 'source' => $source->name()];
		}

		$rawSessions = $this->mergeById(first: $cohortSessions, second: $teacherSessions);
		$roomCache = $this->preloadRooms(sessions: $rawSessions);

		// Each lesson carries the notes the caller may read, and whether the
		// caller may add one (timetabling-lesson-note).
		$sessions = $this->projector->personalSessions(
			rawSessions: $rawSessions,
			windowFrom: $windowFrom,
			windowTo: $windowTo,
			roomCache: $roomCache,
			uid: $uid,
			taughtCohortIds: $taughtCohortIds
		);

		return [
			'sessions' => $sessions,
			'changes' => $this->projector->todaysChanges(rawSessions: $rawSessions, roomCache: $roomCache),
			'source' => $source->name(),
		];
	}//end forUser()

	/**
	 * Pre-load every distinct Room referenced by `roomId` across the given
	 * raw sessions, so the projection step never issues an N+1 query.
	 *
	 * @param array<int,array<string,mixed>> $sessions Raw session data arrays.
	 *
	 * @return array<string,array<string,mixed>> Room data keyed by room UUID.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
	 */
	public function preloadRooms(array $sessions): array {
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
	 * An enrolment that is withdrawn, completed or failed reaches nothing. A
	 * live enrolment with no cohort names its course in $electives.
	 *
	 * @param string            $uid       The caller's Nextcloud user id.
	 * @param array<int,string> $taught    Filled with the cohorts the caller teaches, which read every note of their lessons.
	 * @param array<int,string> $electives Filled with the courses the caller is enrolled in without a cohort.
	 *
	 * @return array<int,string> The unique cohort UUIDs (may be empty).
	 */
	private function resolveCallerCohortIds(string $uid, array &$taught, array &$electives): array {
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
			$cohortId = (string)($cohort['id'] ?? ($cohort['uuid'] ?? ''));
			$role = $this->membership(uid: $uid, cohort: $cohort);
			if ($cohortId === '' || $role === null) {
				continue;
			}

			$cohortIds[$cohortId] = true;
			if ($role === 'teacher') {
				$taught[] = $cohortId;
			}
		}

		// Cohorts and elective courses reached through the caller's own enrolments.
		$this->addEnrolments(uid: $uid, cohortIds: $cohortIds, electives: $electives);

		return array_keys($cohortIds);
	}//end resolveCallerCohortIds()

	/**
	 * Add what the caller's live enrolments reach: a cohort, or a course when
	 * the enrolment has no cohort. A withdrawn, completed or failed enrolment
	 * reaches nothing.
	 *
	 * @param string             $uid       The caller's Nextcloud user id.
	 * @param array<string,bool> $cohortIds Cohort ids reached so far, keyed.
	 * @param array<int,string>  $electives Courses reached without a cohort.
	 *
	 * @return void
	 */
	private function addEnrolments(string $uid, array &$cohortIds, array &$electives): void {
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

			if (in_array((string)($enrolment['lifecycle'] ?? ''), self::ENDED_ENROLMENT, true) === true) {
				continue;
			}

			$cohortId = (string)($enrolment['cohortId'] ?? '');
			$courseId = (string)($enrolment['courseId'] ?? '');
			if ($cohortId !== '') {
				$cohortIds[$cohortId] = true;
			} else if ($courseId !== '' && in_array($courseId, $electives, true) === false) {
				$electives[] = $courseId;
			}
		}
	}//end addEnrolments()

	/**
	 * The caller's place in a cohort: `teacher`, `learner`, or null.
	 *
	 * @param string              $uid    The caller's Nextcloud user id.
	 * @param array<string,mixed> $cohort The cohort.
	 *
	 * @return string|null
	 */
	private function membership(string $uid, array $cohort): ?string {
		if (in_array($uid, $this->toStringList(value: ($cohort['teacherIds'] ?? [])), true) === true) {
			return 'teacher';
		}

		if (in_array($uid, $this->toStringList(value: ($cohort['learnerIds'] ?? [])), true) === true) {
			return 'learner';
		}

		return null;
	}//end membership()

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
