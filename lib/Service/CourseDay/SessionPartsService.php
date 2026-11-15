<?php

/**
 * Learniq SessionPartsService
 *
 * Reads a course day's session with its attendance per part for the trainer's
 * Today: only a trainer of the day (the cohort's `teacherIds`) or a planner
 * (action `course-day.plan`) may read it.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CourseDay
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseDay;

use OCA\Learniq\Kernel\Contracts\SessionPartsServiceInterface;
use OCA\Learniq\Service\ActionAuthService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;

/**
 * The trainer's view of a session's parts.
 *
 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
 */
class SessionPartsService implements SessionPartsServiceInterface {
	use CourseDayRows;

	/**
	 * The planner's action.
	 *
	 * @var string
	 */
	public const ACTION = 'course-day.plan';

	/**
	 * Constructor.
	 *
	 * @param ObjectService          $objectService The OpenRegister object service.
	 * @param CourseDayStatusService $days          The course day rules (places taken).
	 * @param ActionAuthService      $actionAuth    The action matrix.
	 * @param ITimeFactory           $time          The clock.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CourseDayStatusService $days,
		private readonly ActionAuthService $actionAuth,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The session's parts with their attendance, for a trainer of the day or a planner.
	 *
	 * @param string $sessionId The session uuid.
	 * @param IUser  $user      The signed-in user.
	 *
	 * @return array{sessionId: string, cohortId: string, parts: list<array<string, mixed>>, due: string|null}
	 *
	 * @throws CourseDayException When the session or its course day is gone (404) or the user may not read it (403).
	 * @throws \Throwable         When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
	 */
	public function forSession(string $sessionId, IUser $user): array {
		$session  = $this->one(schema: 'session', id: $sessionId);
		$cohortId = (string)($session['cohortId'] ?? '');
		$cohort   = $this->one(schema: 'cohort', id: $cohortId);
		if ($session === null || $cohort === null || (string)($cohort['courseId'] ?? '') === '') {
			throw new CourseDayException(message: 'Session not found.', status: 404);
		}

		if ($this->mayRead(cohort: $cohort, user: $user) === false) {
			throw new CourseDayException(message: 'Only a trainer of this course day or a planner may read its attendance.', status: 403);
		}

		$summary = SessionPartAttendance::summarise(
			session: $session,
			records: $this->records(sessionId: $sessionId),
			participants: $this->days->placesTakenOn(cohortId: $cohortId),
			now: $this->time->getDateTime()
		);

		return ['sessionId' => $sessionId, 'cohortId' => $cohortId, 'parts' => $summary['parts'], 'due' => $summary['due']];
	}//end forSession()

	/**
	 * The attendance records of one session.
	 *
	 * @param string $sessionId The session uuid.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function records(string $sessionId): array {
		return $this->many(schema: 'attendance-record', filters: ['sessionId' => $sessionId]);
	}//end records()

	/**
	 * Whether the user trains this course day or plans course days.
	 *
	 * @param array<string, mixed> $cohort The cohort row.
	 * @param IUser                $user   The user.
	 *
	 * @return bool
	 */
	private function mayRead(array $cohort, IUser $user): bool {
		$teachers = ($cohort['teacherIds'] ?? []);
		if (is_array($teachers) === true && in_array($user->getUID(), $teachers, true) === true) {
			return true;
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION);
		} catch (OCSForbiddenException $exception) {
			return false;
		}

		return true;
	}//end mayRead()
}//end class
