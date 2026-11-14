<?php

/**
 * Learniq CourseDayStatusService
 *
 * Re-derives a course day's status from what is stored: its bookings and
 * enrolments, its sessions' dates and the provider's decision, and writes it
 * back only when it moved.
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
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseDay;

use OCA\Learniq\Support\SerialisedRow;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Keeps `cohort.dayStatus` in step with the day's facts.
 *
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */
class CourseDayStatusService {

	/**
	 * The register.
	 *
	 * @var string
	 */
	private const REGISTER = 'learniq';

	/**
	 * Enrolment states that hold a place.
	 *
	 * @var list<string>
	 */
	private const PLACED = ['pending', 'active', 'completed'];

	/**
	 * Enrolment states that leave a result or certificate open after the day.
	 *
	 * @var list<string>
	 */
	private const OPEN_RESULT = ['pending', 'active'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param ITimeFactory  $time          The clock.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Re-derive one course day and store its status when it moved.
	 *
	 * A cohort that is no course day (no `courseId`) is left alone.
	 *
	 * @param string $cohortId The cohort uuid.
	 *
	 * @return string|null The status, or null when the cohort is not a course day or is gone.
	 *
	 * @throws \Throwable When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function restamp(string $cohortId): ?string {
		$cohort = $this->courseDay(cohortId: $cohortId);
		if ($cohort === null) {
			return null;
		}

		$facts   = $this->facts(cohort: $cohort, cohortId: $cohortId);
		$derived = CourseDayStatus::derive(...$facts);
		$move    = self::standingMove(move: ($cohort['dayStatusMove'] ?? null), fingerprint: self::fingerprint(facts: $facts));
		$status  = ($move['status'] ?? $derived);

		$hadMove = ($cohort['dayStatusMove'] ?? null) !== null;
		$from    = ($cohort['dayStatus'] ?? null);
		if ($from === $status && ($hadMove === false || $move !== null)) {
			return $status;
		}

		$changes = ['dayStatus' => $status];
		if ($hadMove === true) {
			$changes['dayStatusMove'] = $move;
		}

		if ($from !== $status) {
			$changes['dayStatusLog'] = self::logged(
				log: ($cohort['dayStatusLog'] ?? null),
				entry: ['kind' => 'derived', 'from' => $from, 'to' => $status, 'by' => null, 'at' => $this->now()]
			);
		}

		$this->save(cohort: $cohort, cohortId: $cohortId, changes: $changes);

		return $status;
	}//end restamp()

	/**
	 * Move a course day to a status by hand, as a drag on the board does.
	 *
	 * The move is logged and lasts until the next change to the day's facts
	 * (bookings, enrolments, sessions, minimum, maximum, decision or the day
	 * passing); then the derived status takes over again.
	 *
	 * @param string $cohortId The cohort uuid.
	 * @param string $status   One of {@see CourseDayStatus::ALL}.
	 * @param string $userId   Who moved it.
	 *
	 * @return string The status.
	 *
	 * @throws CourseDayException When the status is unknown or the cohort is no course day.
	 * @throws \Throwable         When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function move(string $cohortId, string $status, string $userId): string {
		if (in_array($status, CourseDayStatus::ALL, true) === false) {
			throw new CourseDayException(message: 'Unknown course day status.', status: 400);
		}

		$cohort = $this->requireCourseDay(cohortId: $cohortId);
		$from   = ($cohort['dayStatus'] ?? null);
		$at     = $this->now();
		$this->save(
			cohort: $cohort,
			cohortId: $cohortId,
			changes: [
				'dayStatus' => $status,
				'dayStatusMove' => [
					'status' => $status,
					'from' => $from,
					'movedBy' => $userId,
					'movedAt' => $at,
					'facts' => self::fingerprint(facts: $this->facts(cohort: $cohort, cohortId: $cohortId)),
				],
				'dayStatusLog' => self::logged(
					log: ($cohort['dayStatusLog'] ?? null),
					entry: ['kind' => 'manual', 'from' => $from, 'to' => $status, 'by' => $userId, 'at' => $at]
				),
			]
		);

		return $status;
	}//end move()

	/**
	 * Record the provider's go/no-go decision on a course day and re-derive it.
	 *
	 * @param string      $cohortId The cohort uuid.
	 * @param string|null $decision `go`, `no-go`, or null to undo the decision.
	 * @param string      $userId   Who decided.
	 *
	 * @return string The status after the decision.
	 *
	 * @throws CourseDayException When the decision is unknown or the cohort is no course day.
	 * @throws \Throwable         When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function decide(string $cohortId, ?string $decision, string $userId): string {
		if ($decision !== null && $decision !== 'go' && $decision !== 'no-go') {
			throw new CourseDayException(message: 'A decision is go or no-go.', status: 400);
		}

		$cohort = $this->requireCourseDay(cohortId: $cohortId);
		if (self::decision(value: ($cohort['goDecision'] ?? null)) !== $decision) {
			$this->save(
				cohort: $cohort,
				cohortId: $cohortId,
				changes: [
					'goDecision' => $decision,
					'dayStatusLog' => self::logged(
						log: ($cohort['dayStatusLog'] ?? null),
						entry: [
							'kind' => 'decision',
							'from' => ($cohort['goDecision'] ?? null),
							'to' => $decision,
							'by' => $userId,
							'at' => $this->now(),
						]
					),
				]
			);
		}

		return (string)$this->restamp(cohortId: $cohortId);
	}//end decide()

	/**
	 * The other days of the same course that are still to come and have places.
	 *
	 * @param string $cohortId The cohort uuid.
	 *
	 * @return list<array{id: string, name: string, date: string|null, free: int|null}> Earliest first; free is null without a maximum.
	 *
	 * @throws CourseDayException When the cohort is no course day.
	 * @throws \Throwable         When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function alternatives(string $cohortId): array {
		$cohort = $this->requireCourseDay(cohortId: $cohortId);
		$today  = $this->time->getDateTime()->format('Y-m-d');
		$days   = [];
		foreach ($this->many(schema: 'cohort', filters: ['courseId' => (string)$cohort['courseId']]) as $other) {
			$id = (string)($other['id'] ?? ($other['uuid'] ?? ''));
			if ($id === '' || $id === $cohortId || self::decision(value: ($other['goDecision'] ?? null)) === 'no-go') {
				continue;
			}

			$date = self::lastDay(sessions: $this->many(schema: 'session', filters: ['cohortId' => $id]));
			if ($date === null || $date < $today) {
				continue;
			}

			$free     = null;
			$capacity = self::intOrNull(value: ($other['capacity'] ?? null));
			if ($capacity !== null && $capacity > 0) {
				$free = ($capacity - $this->placesTaken(cohortId: $id));
				if ($free <= 0) {
					continue;
				}
			}

			$days[] = ['id' => $id, 'name' => (string)($other['name'] ?? ''), 'date' => $date, 'free' => $free];
		}

		usort($days, static fn (array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));

		return $days;
	}//end alternatives()

	/**
	 * Move the participants of a cancelled course day to another day of the
	 * same course: its open bookings and the enrolments holding a place.
	 *
	 * @param string $cohortId The cancelled day.
	 * @param string $targetId The day to move them to.
	 * @param string $userId   Who moved them.
	 *
	 * @return array{bookings: int, enrolments: int, targetId: string} What moved.
	 *
	 * @throws CourseDayException When the day is not cancelled, the target is not another coming
	 *                            day of the same course, or it lacks the places.
	 * @throws \Throwable         When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function moveParticipants(string $cohortId, string $targetId, string $userId): array {
		$cohort = $this->requireCourseDay(cohortId: $cohortId);
		if (self::decision(value: ($cohort['goDecision'] ?? null)) !== 'no-go') {
			throw new CourseDayException(message: 'Only the participants of a day that does not go ahead are moved.', status: 409);
		}

		$target = null;
		foreach ($this->alternatives(cohortId: $cohortId) as $day) {
			if ($day['id'] === $targetId) {
				$target = $day;
			}
		}

		if ($target === null) {
			throw new CourseDayException(message: 'Choose another coming day of the same course with places.', status: 409);
		}

		$moving = $this->placesTaken(cohortId: $cohortId);
		if ($target['free'] !== null && $moving > $target['free']) {
			throw new CourseDayException(message: 'The chosen day has too few places for every participant.', status: 409);
		}

		$bookings = 0;
		foreach ($this->many(schema: 'course-booking', filters: ['cohortId' => $cohortId]) as $booking) {
			if ((string)($booking['lifecycle'] ?? 'received') === 'cancelled') {
				continue;
			}

			$this->moveRow(schema: 'course-booking', row: $booking, targetId: $targetId);
			$bookings++;
		}

		$enrolments = 0;
		foreach ($this->many(schema: 'enrolment', filters: ['cohortId' => $cohortId]) as $enrolment) {
			if (in_array((string)($enrolment['lifecycle'] ?? 'pending'), self::PLACED, true) === false) {
				continue;
			}

			$this->moveRow(schema: 'enrolment', row: $enrolment, targetId: $targetId);
			$enrolments++;
		}

		$this->save(
			cohort: $cohort,
			cohortId: $cohortId,
			changes: [
				'dayStatusLog' => self::logged(
					log: ($cohort['dayStatusLog'] ?? null),
					entry: ['kind' => 'participants-moved', 'from' => $cohortId, 'to' => $targetId, 'by' => $userId, 'at' => $this->now()]
				),
			]
		);
		$this->restamp(cohortId: $cohortId);
		$this->restamp(cohortId: $targetId);

		return ['bookings' => $bookings, 'enrolments' => $enrolments, 'targetId' => $targetId];
	}//end moveParticipants()

	/**
	 * The places taken on a course day, as the status counts them.
	 *
	 * @param string $cohortId The cohort uuid.
	 *
	 * @return int
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
	 */
	public function placesTakenOn(string $cohortId): int {
		return $this->placesTaken(cohortId: $cohortId);
	}//end placesTakenOn()

	/**
	 * The places taken on a course day.
	 *
	 * @param string $cohortId The cohort uuid.
	 *
	 * @return int
	 */
	private function placesTaken(string $cohortId): int {
		return self::participants(
			bookings: $this->many(schema: 'course-booking', filters: ['cohortId' => $cohortId]),
			enrolments: $this->many(schema: 'enrolment', filters: ['cohortId' => $cohortId])
		);
	}//end placesTaken()

	/**
	 * The arguments of {@see CourseDayStatus::derive()} for a course day.
	 *
	 * @param array<string, mixed> $cohort   The cohort row.
	 * @param string               $cohortId The cohort uuid.
	 *
	 * @return array{
	 *     participants: int, capacity: int|null, minimum: int|null, decision: string|null,
	 *     dayDate: string|null, today: string, openResults: bool
	 * }
	 */
	private function facts(array $cohort, string $cohortId): array {
		$enrolments = $this->many(schema: 'enrolment', filters: ['cohortId' => $cohortId]);

		return [
			'participants' => self::participants(bookings: $this->many(schema: 'course-booking', filters: ['cohortId' => $cohortId]), enrolments: $enrolments),
			'capacity' => self::intOrNull(value: $cohort['capacity'] ?? null),
			'minimum' => self::intOrNull(value: $cohort['minParticipants'] ?? null),
			'decision' => self::decision(value: $cohort['goDecision'] ?? null),
			'dayDate' => self::lastDay(sessions: $this->many(schema: 'session', filters: ['cohortId' => $cohortId])),
			'today' => $this->time->getDateTime()->format('Y-m-d'),
			'openResults' => self::hasOpenResults(enrolments: $enrolments),
		];
	}//end facts()

	/**
	 * A fingerprint of the facts a status derives from. Today counts only as
	 * whether the day has passed, so a manual move outlives a new date but not
	 * the day itself.
	 *
	 * @param array<string, mixed> $facts The facts from {@see self::facts()}.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public static function fingerprint(array $facts): string {
		$day  = ($facts['dayDate'] ?? null);
		$past = ($day !== null && $day !== '' && $day < (string)($facts['today'] ?? ''));
		unset($facts['today']);
		$facts['past'] = $past;
		ksort($facts);

		return hash('sha256', (string)json_encode($facts));
	}//end fingerprint()

	/**
	 * The stored manual move when it still stands, else null.
	 *
	 * @param mixed  $move        The stored `dayStatusMove`.
	 * @param string $fingerprint The facts' fingerprint now.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function standingMove(mixed $move, string $fingerprint): ?array {
		if (is_array($move) === false
			|| in_array(($move['status'] ?? null), CourseDayStatus::ALL, true) === false
			|| ($move['facts'] ?? null) !== $fingerprint
		) {
			return null;
		}

		return $move;
	}//end standingMove()

	/**
	 * The log with one entry added, keeping the latest fifty.
	 *
	 * @param mixed                $log   The stored `dayStatusLog`.
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function logged(mixed $log, array $entry): array {
		$entries = [];
		if (is_array($log) === true) {
			$entries = array_values(array_filter($log, 'is_array'));
		}

		$entries[] = $entry;

		return array_slice($entries, -50);
	}//end logged()

	/**
	 * Now, as an ISO 8601 timestamp.
	 *
	 * @return string
	 */
	private function now(): string {
		return $this->time->getDateTime()->format(DATE_ATOM);
	}//end now()

	/**
	 * The cohort when it is a course day (it has a course), else null.
	 *
	 * @param string $cohortId The cohort uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function courseDay(string $cohortId): ?array {
		$cohort = $this->one(schema: 'cohort', id: $cohortId);
		if ($cohort === null || (string)($cohort['courseId'] ?? '') === '') {
			return null;
		}

		return $cohort;
	}//end courseDay()

	/**
	 * The course day, or a 404 refusal.
	 *
	 * @param string $cohortId The cohort uuid.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws CourseDayException When the cohort is gone or no course day.
	 */
	private function requireCourseDay(string $cohortId): array {
		$cohort = $this->courseDay(cohortId: $cohortId);
		if ($cohort === null) {
			throw new CourseDayException(message: 'Course day not found.', status: 404);
		}

		return $cohort;
	}//end requireCourseDay()

	/**
	 * Write fields onto a cohort.
	 *
	 * @param array<string, mixed> $cohort   The cohort row.
	 * @param string               $cohortId The cohort uuid.
	 * @param array<string, mixed> $changes  The fields to write.
	 *
	 * @return void
	 */
	private function save(array $cohort, string $cohortId, array $changes): void {
		$this->objectService->saveObject(
			object: array_merge($cohort, $changes),
			register: self::REGISTER,
			schema: 'cohort',
			uuid: $cohortId,
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * Point a booking or enrolment at another course day.
	 *
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $row      The row.
	 * @param string               $targetId The day to move it to.
	 *
	 * @return void
	 */
	private function moveRow(string $schema, array $row, string $targetId): void {
		$this->objectService->saveObject(
			object: array_merge($row, ['cohortId' => $targetId]),
			register: self::REGISTER,
			schema: $schema,
			uuid: (string)($row['id'] ?? ($row['uuid'] ?? '')),
			_rbac: false,
			_multitenancy: false
		);
	}//end moveRow()

	/**
	 * The places taken: each enrolment once, and each open booking for its
	 * places or for its enrolments, whichever is more.
	 *
	 * @param list<array<string, mixed>> $bookings   The day's company bookings.
	 * @param list<array<string, mixed>> $enrolments The day's enrolments.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public static function participants(array $bookings, array $enrolments): int {
		$byBooking = [];
		$loose     = 0;
		foreach ($enrolments as $enrolment) {
			if (in_array((string)($enrolment['lifecycle'] ?? 'pending'), self::PLACED, true) === false) {
				continue;
			}

			$booking = (string)($enrolment['bookingRef'] ?? '');
			if ($booking === '') {
				$loose++;
				continue;
			}

			$byBooking[$booking] = (($byBooking[$booking] ?? 0) + 1);
		}

		$total = $loose;
		$seen  = [];
		foreach ($bookings as $booking) {
			$id = (string)($booking['id'] ?? '');
			$seen[$id] = true;
			if ((string)($booking['lifecycle'] ?? 'received') === 'cancelled') {
				continue;
			}

			$total += max((int)($booking['participantCount'] ?? 0), ($byBooking[$id] ?? 0));
		}

		// Enrolments on a booking that is not on this day still hold their places here.
		foreach ($byBooking as $id => $count) {
			if (isset($seen[$id]) === false) {
				$total += $count;
			}
		}

		return $total;
	}//end participants()

	/**
	 * The day's last date from its sessions, or null when it has none.
	 *
	 * @param list<array<string, mixed>> $sessions The day's sessions.
	 *
	 * @return string|null Y-m-d
	 */
	private static function lastDay(array $sessions): ?string {
		$last = null;
		foreach ($sessions as $session) {
			$date = substr((string)($session['startsAt'] ?? ''), 0, 10);
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && ($last === null || $date > $last)) {
				$last = $date;
			}
		}

		return $last;
	}//end lastDay()

	/**
	 * Whether any participant's result or certificate is still open.
	 *
	 * @param list<array<string, mixed>> $enrolments The day's enrolments.
	 *
	 * @return bool
	 */
	private static function hasOpenResults(array $enrolments): bool {
		foreach ($enrolments as $enrolment) {
			if (in_array((string)($enrolment['lifecycle'] ?? 'pending'), self::OPEN_RESULT, true) === true) {
				return true;
			}
		}

		return false;
	}//end hasOpenResults()

	/**
	 * A stored decision, or null when undecided.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null
	 */
	private static function decision(mixed $value): ?string {
		if ($value === 'go' || $value === 'no-go') {
			return $value;
		}

		return null;
	}//end decision()

	/**
	 * A stored count, or null when there is none.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return int|null
	 */
	private static function intOrNull(mixed $value): ?int {
		if (is_int($value) === true || (is_string($value) === true && ctype_digit($value) === true)) {
			return (int)$value;
		}

		return null;
	}//end intOrNull()

	/**
	 * The row with this uuid, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function one(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objects = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema], 'ids' => [$id], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($objects as $object) {
			$row = (new SerialisedRow())->asArray(object: $object);
			if (($row['id'] ?? ($row['uuid'] ?? null)) === $id) {
				return $row;
			}
		}

		return null;
	}//end one()

	/**
	 * The rows of a schema whose fields equal the filters.
	 *
	 * @param string                $schema  The schema slug.
	 * @param array<string, string> $filters Field equals value.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function many(string $schema, array $filters): array {
		$objects = $this->objectService->findAll(
			config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters), 'limit' => 500],
			_rbac: false,
			_multitenancy: false
		);
		$rows = [];
		foreach ($objects as $object) {
			$row = (new SerialisedRow())->asArray(object: $object);
			// Never trust the store to have applied a filter on a field.
			foreach ($filters as $field => $value) {
				if (($row[$field] ?? null) !== $value) {
					continue 2;
				}
			}

			$rows[] = $row;
		}

		return $rows;
	}//end many()
}//end class
