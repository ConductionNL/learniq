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
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;
use Throwable;

/**
 * Weekly slots of courses and of the caller's core lessons.
 */
class ElectiveSlotService {
	/**
	 * Days ahead the slots are read from.
	 *
	 * @var int
	 */
	private const DAYS_AHEAD = 28;

	/**
	 * Most courses asked for at once.
	 *
	 * @var int
	 */
	public const MAX_COURSES = 20;

	/**
	 * Constructor.
	 *
	 * @param ObjectService            $objectService OR object service; a course is read with RBAC on first.
	 * @param TimetableSourceResolver  $sources       Where sessions are read from.
	 * @param PersonalTimetableService $timetable     The caller's own lessons.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TimetableSourceResolver $sources,
		private readonly PersonalTimetableService $timetable,
	) {
	}//end __construct()

	/**
	 * The slots of each readable course, and of the caller's core lessons when asked.
	 *
	 * A course the caller cannot read is left out, and none of its lessons is read.
	 *
	 * @param string            $uid       The caller.
	 * @param array<int,string> $courseIds The electives.
	 * @param bool              $withCore  Whether to add the caller's own other lessons.
	 * @param DateTimeZone      $zone      The reader's time zone.
	 * @param int               $now       Now (unix seconds).
	 *
	 * @return array{courses: array<string,array<int,array<string,mixed>>>, core: array<int,array<string,mixed>>}
	 *
	 * @throws RuntimeException When the timetable source does not answer.
	 *
	 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-clash-warning-when-choosing
	 */
	public function slots(string $uid, array $courseIds, bool $withCore, DateTimeZone $zone, int $now): array {
		$from = gmdate(DATE_ATOM, $now);
		$to = gmdate(DATE_ATOM, ($now + (self::DAYS_AHEAD * 86400)));

		$readable = array_values(array_filter(array_unique($courseIds), fn (string $id): bool => $this->canReadCourse(courseId: $id)));
		$courses = array_fill_keys($readable, []);
		if ($readable !== []) {
			$sessions = $this->sources->current()->sessionsForCourses(courseIds: $readable, from: $from, to: $to);
			foreach ($sessions as $session) {
				$courseId = (string)($session['courseId'] ?? '');
				if (isset($courses[$courseId]) === true && $this->inWindow(session: $session, from: $now, to: ($now + (self::DAYS_AHEAD * 86400))) === true) {
					$courses[$courseId][] = $session;
				}
			}
		}

		$core = [];
		if ($withCore === true) {
			foreach ($this->timetable->forUser(uid: $uid, windowFrom: $from, windowTo: $to)['sessions'] as $session) {
				if (isset($courses[(string)($session['courseId'] ?? '')]) === false && ($session['lifecycle'] ?? '') !== 'cancelled') {
					$core[] = $session;
				}
			}
		}

		return [
			'courses' => array_map(fn (array $list): array => $this->toSlots(sessions: $list, zone: $zone), $courses),
			'core' => $this->toSlots(sessions: $core, zone: $zone),
		];
	}//end slots()

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
	 * @param array<string,mixed> $session The session.
	 * @param int                 $from    Window start (unix seconds).
	 * @param int                 $to      Window end (unix seconds).
	 *
	 * @return bool
	 */
	private function inWindow(array $session, int $from, int $to): bool {
		$start = strtotime((string)($session['startsAt'] ?? ''));
		return $start !== false && $start >= $from && $start < $to;
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
