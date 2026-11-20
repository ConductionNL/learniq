<?php

/**
 * Learniq PlannerTodayService
 *
 * Reads what the planner's Today shows: the course days that ask for a
 * go/no-go decision now (with the next day of the course that has places),
 * the bookings and enrolments still to confirm, the occupancy of the coming
 * course days, and their trainers and rooms.
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseDay;

use OCA\Learniq\Kernel\Contracts\CourseDayStatusServiceInterface;
use OCA\Learniq\Kernel\Contracts\PlannerTodayServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The planner's Today.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */
class PlannerTodayService implements PlannerTodayServiceInterface {
	use CourseDayRows;

	/**
	 * Course names already read, by course id.
	 *
	 * @var array<string, string>
	 */
	private array $courseNames = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectService                   $objectService The OpenRegister object service.
	 * @param CourseDayStatusServiceInterface $days          The course day rules (alternatives).
	 * @param ITimeFactory                    $time          The clock.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CourseDayStatusServiceInterface $days,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * What the planner's Today shows.
	 *
	 * @return array<string, mixed> `today` (Y-m-d), `decisions` (days asking for a go/no-go now),
	 *                              `toConfirm` {bookings, enrolments} and `occupancy` {until, days}.
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-four-of-six
	 */
	public function today(): array {
		$today   = $this->time->getDateTime()->format('Y-m-d');
		$until   = PlannerToday::occupancyUntil(today: $today);
		$coming  = $this->comingDays(today: $today);
		$facts   = [];
		$toCheck = [];
		foreach ($coming as $id => $cohort) {
			$facts[$id] = $this->dayFacts(cohort: $cohort, cohortId: $id);
			$date = $facts[$id]['date'];
			if ($until !== null && $date !== null && $date >= $today && $date <= $until) {
				$toCheck[$id] = true;
			}
		}

		return [
			'today' => $today,
			'decisions' => $this->decisions(coming: $coming, facts: $facts, today: $today),
			'toConfirm' => $this->toConfirm(facts: $facts),
			'occupancy' => ['until' => $until, 'days' => $this->occupancy(coming: $coming, facts: $facts, ids: array_keys($toCheck))],
		];
	}//end today()

	/**
	 * The course days (cohorts of a course) that are still to come or have no date yet, by id.
	 *
	 * @param string $today Today (Y-m-d).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function comingDays(string $today): array {
		$days = [];
		foreach ($this->many(schema: 'cohort', filters: []) as $cohort) {
			$id   = (string)($cohort['id'] ?? ($cohort['uuid'] ?? ''));
			$date = $cohort['dayDate'] ?? null;
			if ($id === '' || (string)($cohort['courseId'] ?? '') === '') {
				continue;
			}

			if (is_string($date) === true && $date !== '' && substr($date, 0, 10) < $today) {
				continue;
			}

			$days[$id] = $cohort;
		}

		return $days;
	}//end comingDays()

	/**
	 * One course day's facts, read once: its date, participants and what waits for confirmation.
	 *
	 * @param array<string, mixed> $cohort   The course day.
	 * @param string               $cohortId Its uuid.
	 *
	 * @return array<string, mixed> date, decision, minimum, capacity, participants, decideBy, sessions,
	 *                              bookingsToConfirm and enrolmentsToConfirm.
	 */
	private function dayFacts(array $cohort, string $cohortId): array {
		$bookings   = $this->many(schema: 'course-booking', filters: ['cohortId' => $cohortId]);
		$enrolments = $this->many(schema: 'enrolment', filters: ['cohortId' => $cohortId]);
		$sessions   = $this->many(schema: 'session', filters: ['cohortId' => $cohortId]);

		return [
			'date' => self::dateOrNull(value: ($cohort['dayDate'] ?? null)) ?? self::firstSessionDate(sessions: $sessions),
			'decision' => self::decisionOf(value: ($cohort['goDecision'] ?? null)),
			'minimum' => self::intOf(value: ($cohort['minParticipants'] ?? null)),
			'capacity' => self::intOf(value: ($cohort['capacity'] ?? null)),
			'participants' => CourseDayStatusService::participants(bookings: $bookings, enrolments: $enrolments),
			'decideBy' => self::dateOrNull(value: ($cohort['goDecisionBy'] ?? null)),
			'sessions' => $sessions,
			'bookingsToConfirm' => count(array_filter($bookings, static fn (array $row): bool => ($row['lifecycle'] ?? 'received') === 'received')),
			'enrolmentsToConfirm' => count(
				array_filter(
					$enrolments,
					static fn (array $row): bool => ($row['lifecycle'] ?? 'pending') === 'pending' && (string)($row['bookingRef'] ?? '') === ''
				)
			),
		];
	}//end dayFacts()

	/**
	 * The course days that ask for a decision now, the soonest decision first,
	 * each with the next day of the same course that has places.
	 *
	 * @param array<string, array<string, mixed>> $coming The coming course days.
	 * @param array<string, array<string, mixed>> $facts  Their facts.
	 * @param string                              $today  Today (Y-m-d).
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-four-of-six
	 */
	private function decisions(array $coming, array $facts, string $today): array {
		$rows = [];
		foreach ($coming as $id => $cohort) {
			$day = $facts[$id];
			if (PlannerToday::needsDecision(day: $day, today: $today) === false) {
				continue;
			}

			$rows[] = [
				'id' => $id,
				'name' => (string)($cohort['name'] ?? ''),
				'courseName' => $this->courseName(courseId: (string)$cohort['courseId']),
				'date' => $day['date'],
				'participants' => $day['participants'],
				'minimum' => $day['minimum'],
				'decideBy' => $day['decideBy'],
				'workingDaysLeft' => PlannerToday::workingDaysUntil(today: $today, date: (string)$day['decideBy']),
				'next' => ($this->days->alternatives(cohortId: $id)[0] ?? null),
			];
		}

		usort($rows, static fn (array $a, array $b): int => strcmp((string)$a['decideBy'], (string)$b['decideBy']));

		return $rows;
	}//end decisions()

	/**
	 * The bookings and loose enrolments of coming course days still to confirm.
	 *
	 * @param array<string, array<string, mixed>> $facts The coming days' facts.
	 *
	 * @return array{bookings: int, enrolments: int}
	 */
	private static function toConfirm(array $facts): array {
		$counts = ['bookings' => 0, 'enrolments' => 0];
		foreach ($facts as $day) {
			$counts['bookings']   += $day['bookingsToConfirm'];
			$counts['enrolments'] += $day['enrolmentsToConfirm'];
		}

		return $counts;
	}//end toConfirm()

	/**
	 * The occupancy, trainers and rooms of the course days up to the occupancy date, earliest first.
	 *
	 * @param array<string, array<string, mixed>> $coming The coming course days.
	 * @param array<string, array<string, mixed>> $facts  Their facts.
	 * @param list<string>                        $ids    The days within the period.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function occupancy(array $coming, array $facts, array $ids): array {
		$rows = [];
		foreach ($ids as $id) {
			$cohort = $coming[$id];
			$day    = $facts[$id];
			$rows[] = [
				'id' => $id,
				'name' => (string)($cohort['name'] ?? ''),
				'courseName' => $this->courseName(courseId: (string)$cohort['courseId']),
				'date' => $day['date'],
				'participants' => $day['participants'],
				'capacity' => $day['capacity'],
				'minimum' => $day['minimum'],
				'status' => ($cohort['dayStatus'] ?? null),
				'trainers' => self::trainersOf(cohort: $cohort, sessions: $day['sessions']),
				'rooms' => self::roomsOf(sessions: $day['sessions']),
			];
		}

		usort($rows, static fn (array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));

		return $rows;
	}//end occupancy()

	/**
	 * The day's trainers: the cohort's teachers and any substitute on its sessions.
	 *
	 * @param array<string, mixed>       $cohort   The course day.
	 * @param list<array<string, mixed>> $sessions Its sessions.
	 *
	 * @return list<string>
	 */
	private static function trainersOf(array $cohort, array $sessions): array {
		$trainers = [];
		$teachers = ($cohort['teacherIds'] ?? []);
		if (is_array($teachers) === true) {
			foreach ($teachers as $teacher) {
				$trainers[] = (string)$teacher;
			}
		}

		foreach ($sessions as $session) {
			$trainers[] = (string)($session['substituteTeacherId'] ?? '');
		}

		return array_values(array_unique(array_filter($trainers, static fn (string $id): bool => $id !== '')));
	}//end trainersOf()

	/**
	 * The rooms of the day's sessions (room id, else the location text).
	 *
	 * @param list<array<string, mixed>> $sessions The sessions.
	 *
	 * @return list<string>
	 */
	private static function roomsOf(array $sessions): array {
		$rooms = [];
		foreach ($sessions as $session) {
			$room = (string)($session['roomId'] ?? '');
			if ($room === '') {
				$room = (string)($session['location'] ?? '');
			}

			$rooms[] = $room;
		}

		return array_values(array_unique(array_filter($rooms, static fn (string $room): bool => $room !== '')));
	}//end roomsOf()

	/**
	 * A course's name, read once per course.
	 *
	 * @param string $courseId The course uuid.
	 *
	 * @return string The name, or '' when the course is gone.
	 */
	private function courseName(string $courseId): string {
		if (array_key_exists($courseId, $this->courseNames) === false) {
			$course = $this->one(schema: 'course', id: $courseId);
			$this->courseNames[$courseId] = (string)($course['name'] ?? '');
		}

		return $this->courseNames[$courseId];
	}//end courseName()

	/**
	 * The earliest session date, or null.
	 *
	 * @param list<array<string, mixed>> $sessions The sessions.
	 *
	 * @return string|null
	 */
	private static function firstSessionDate(array $sessions): ?string {
		$first = null;
		foreach ($sessions as $session) {
			$date = self::dateOrNull(value: ($session['startsAt'] ?? null));
			if ($date !== null && ($first === null || $date < $first)) {
				$first = $date;
			}
		}

		return $first;
	}//end firstSessionDate()

	/**
	 * The Y-m-d part of a date value, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function dateOrNull(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$date = substr($value, 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return null;
		}

		return $date;
	}//end dateOrNull()

	/**
	 * A go/no-go decision, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null
	 */
	private static function decisionOf(mixed $value): ?string {
		if ($value === 'go' || $value === 'no-go') {
			return $value;
		}

		return null;
	}//end decisionOf()

	/**
	 * An integer, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return int|null
	 */
	private static function intOf(mixed $value): ?int {
		if (is_int($value) === true || (is_string($value) === true && ctype_digit($value) === true)) {
			return (int)$value;
		}

		return null;
	}//end intOf()
}//end class
