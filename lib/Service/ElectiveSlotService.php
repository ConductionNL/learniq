<?php

/**
 * Learniq Elective Slot Service
 *
 * The weekly time slots of elective courses, for the subject choice picker:
 * each course's lessons over the next four weeks reduced to distinct
 * (weekday, start, end) slots in the reader's time zone. Optionally the
 * caller's own core lessons as slots too, so the picker can warn about an
 * elective that meets during one (timetabling-student-choice-placement D4).
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
 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-clash-warning-when-choosing
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Kernel\Contracts\ElectiveSlotServiceInterface;
use OCA\Learniq\Kernel\Contracts\TimetableSourceResolverInterface;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;
use Throwable;

/**
 * Weekly slots of courses and of the caller's core lessons.
 */
class ElectiveSlotService implements ElectiveSlotServiceInterface {
	/**
	 * Days ahead the slots are read from.
	 *
	 * @var int
	 */
	private const DAYS_AHEAD = 28;

	/**
	 * Constructor.
	 *
	 * @param ObjectService            $objectService OR object service; a course is read with RBAC on first.
	 * @param TimetableSourceResolverInterface  $sources       Where sessions are read from.
	 * @param PersonalTimetableService $timetable     The caller's own lessons.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TimetableSourceResolverInterface $sources,
		private readonly PersonalTimetableService $timetable,
	) {
	}//end __construct()

	/**
	 * The slots of each readable course, and of the caller's core lessons when asked.
	 *
	 * A course the caller cannot read is left out, and none of its lessons is read.
	 *
	 * @param string                                                                          $uid   The caller.
	 * @param array{courseIds: array<int,string>, withCore: bool, zone: DateTimeZone, now: int} $query The electives, whether to add the
	 *     caller's own other lessons, the reader's time zone and now (unix seconds).
	 *
	 * @return array{courses: array<string,array<int,array<string,mixed>>>, core: array<int,array<string,mixed>>}
	 *
	 * @throws RuntimeException When the timetable source does not answer.
	 *
	 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-clash-warning-when-choosing
	 */
	public function slots(string $uid, array $query): array {
		$window = ['from' => $query['now'], 'to' => ($query['now'] + (self::DAYS_AHEAD * 86400))];
		$courses = $this->courseSessions(courseIds: $query['courseIds'], window: $window);

		$core = [];
		if ($query['withCore'] === true) {
			$core = $this->coreSessions(uid: $uid, scope: ['courses' => $courses, 'window' => $window]);
		}

		return [
			'courses' => array_map(fn (array $list): array => $this->toSlots(sessions: $list, zone: $query['zone']), $courses),
			'core' => $this->toSlots(sessions: $core, zone: $query['zone']),
		];
	}//end slots()

	/**
	 * The sessions of each readable course inside the window, by course id.
	 *
	 * @param array<int,string>          $courseIds The electives.
	 * @param array{from: int, to: int} $window    The window (unix seconds).
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function courseSessions(array $courseIds, array $window): array {
		$readable = array_values(array_filter(array_unique($courseIds), fn (string $id): bool => $this->canReadCourse(courseId: $id)));
		$courses = array_fill_keys($readable, []);
		if ($readable === []) {
			return $courses;
		}

		$sessions = $this->sources->current()->sessionsForCourses(
			courseIds: $readable,
			from: gmdate(DATE_ATOM, $window['from']),
			to: gmdate(DATE_ATOM, $window['to'])
		);
		foreach ($sessions as $session) {
			$courseId = (string)($session['courseId'] ?? '');
			if (isset($courses[$courseId]) === true && $this->inWindow(session: $session, window: $window) === true) {
				$courses[$courseId][] = $session;
			}
		}

		return $courses;
	}//end courseSessions()

	/**
	 * The caller's own lessons in the window that are not one of the asked courses and not cancelled.
	 *
	 * @param string                                                                              $uid   The caller.
	 * @param array{courses: array<string,array<int,mixed>>, window: array{from: int, to: int}} $scope The asked courses by id and the window.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function coreSessions(string $uid, array $scope): array {
		$window = $scope['window'];
		$core = [];
		$own = $this->timetable->forUser(uid: $uid, windowFrom: gmdate(DATE_ATOM, $window['from']), windowTo: gmdate(DATE_ATOM, $window['to']));
		foreach ($own['sessions'] as $session) {
			if (isset($scope['courses'][(string)($session['courseId'] ?? '')]) === false && ($session['lifecycle'] ?? '') !== 'cancelled') {
				$core[] = $session;
			}
		}

		return $core;
	}//end coreSessions()

	/**
	 * Whether the caller can read a course, through OpenRegister RBAC; fails closed.
	 *
	 * @param string $courseId The course UUID.
	 *
	 * @return bool
	 */
	private function canReadCourse(string $courseId): bool {
		if ($courseId === '') {
			return false;
		}

		try {
			return $this->objectService->find(id: $courseId, register: 'learniq', schema: 'course') !== null;
		} catch (Throwable) {
			return false;
		}
	}//end canReadCourse()

	/**
	 * Whether a session starts inside the window.
	 *
	 * @param array<string,mixed>       $session The session.
	 * @param array{from: int, to: int} $window  The window (unix seconds).
	 *
	 * @return bool
	 */
	private function inWindow(array $session, array $window): bool {
		$start = strtotime((string)($session['startsAt'] ?? ''));
		return $start !== false && $start >= $window['from'] && $start < $window['to'];
	}//end inWindow()

	/**
	 * Distinct weekly slots of sessions, sorted by weekday and start.
	 *
	 * @param array<int,array<string,mixed>> $sessions The sessions.
	 * @param DateTimeZone                   $zone     The reader's time zone.
	 *
	 * @return array<int,array<string,mixed>> `{weekday, start, end, label}` slots.
	 */
	private function toSlots(array $sessions, DateTimeZone $zone): array {
		$slots = [];
		foreach ($sessions as $session) {
			$start = strtotime((string)($session['startsAt'] ?? ''));
			$end = strtotime((string)($session['endsAt'] ?? ''));
			if ($start === false || $end === false || $end <= $start) {
				continue;
			}

			$begin = (new DateTimeImmutable('@' . $start))->setTimezone($zone);
			$finish = (new DateTimeImmutable('@' . $end))->setTimezone($zone);
			$slot = [
				'weekday' => (int)$begin->format('N'),
				'start' => $begin->format('H:i'),
				'end' => $finish->format('H:i'),
				'label' => (string)($session['title'] ?? ''),
			];
			$slots[$slot['weekday'] . ' ' . $slot['start'] . ' ' . $slot['end'] . ' ' . $slot['label']] = $slot;
		}

		ksort($slots);
		return array_values($slots);
	}//end toSlots()
}//end class
