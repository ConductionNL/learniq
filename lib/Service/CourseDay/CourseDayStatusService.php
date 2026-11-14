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

		$facts = $this->facts(cohort: $cohort, cohortId: $cohortId);
		$move  = self::standingMove(move: ($cohort['dayStatusMove'] ?? null), fingerprint: self::fingerprint(facts: $facts));
		$next  = [
			'dayStatus' => ($move['status'] ?? CourseDayStatus::derive(facts: $facts)),
			'dayStatusMove' => $move,
			'dayDate' => $facts['dayDate'],
		];

		$changes = $this->restampChanges(cohort: $cohort, next: $next);
		if ($changes !== null) {
			$this->save(cohort: $cohort, cohortId: $cohortId, changes: $changes);
		}

		return $next['dayStatus'];
	}//end restamp()

	/**
	 * The changes a re-derivation stores, or null when nothing moved.
	 *
	 * A standing manual move is kept; one whose facts changed is cleared.
	 *
	 * @param array<string, mixed>                                                     $cohort The course day.
	 * @param array{dayStatus: string, dayStatusMove: array|null, dayDate: string|null} $next   The status, the standing move and the day's date.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	private function restampChanges(array $cohort, array $next): ?array {
		if (self::isSettled(cohort: $cohort, next: $next) === true) {
			return null;
		}

		$from    = ($cohort['dayStatus'] ?? null);
		$status  = $next['dayStatus'];
		$changes = ['dayStatus' => $status];
		if (($cohort['dayStatusMove'] ?? null) !== null) {
			$changes['dayStatusMove'] = $next['dayStatusMove'];
		}

		// The day's date, from its sessions, for the course days calendar.
		if (($cohort['dayDate'] ?? null) !== $next['dayDate']) {
			$changes['dayDate'] = $next['dayDate'];
		}

		if ($from !== $status) {
			$changes['dayStatusLog'] = $this->withLog(cohort: $cohort, entry: ['kind' => 'derived', 'from' => $from, 'to' => $status, 'by' => null]);
		}

		return $changes;
	}//end restampChanges()

	/**
	 * Whether the stored status already holds and no stale move needs clearing.
	 *
	 * @param array<string, mixed>                                                     $cohort The course day.
	 * @param array{dayStatus: string, dayStatusMove: array|null, dayDate: string|null} $next   The status, the standing move and the day's date.
	 *
	 * @return bool
	 */
	private static function isSettled(array $cohort, array $next): bool {
		$hadMove = ($cohort['dayStatusMove'] ?? null) !== null;
		$sameDay = ($cohort['dayDate'] ?? null) === $next['dayDate'];

		return $sameDay === true && ($cohort['dayStatus'] ?? null) === $next['dayStatus'] && ($hadMove === false || $next['dayStatusMove'] !== null);
	}//end isSettled()

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
		$log    = $this->withLog(cohort: $cohort, entry: ['kind' => 'manual', 'from' => $from, 'to' => $status, 'by' => $userId]);
		$moved  = [
			'status' => $status,
			'from' => $from,
			'movedBy' => $userId,
			'movedAt' => end($log)['at'],
			'facts' => self::fingerprint(facts: $this->facts(cohort: $cohort, cohortId: $cohortId)),
		];
		$this->save(cohort: $cohort, cohortId: $cohortId, changes: ['dayStatus' => $status, 'dayStatusMove' => $moved, 'dayStatusLog' => $log]);

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
		self::assertDecision(decision: $decision);
		$cohort = $this->requireCourseDay(cohortId: $cohortId);
		if (self::decision(value: ($cohort['goDecision'] ?? null)) !== $decision) {
			$entry = ['kind' => 'decision', 'from' => ($cohort['goDecision'] ?? null), 'to' => $decision, 'by' => $userId];
			$this->save(
				cohort: $cohort,
				cohortId: $cohortId,
				changes: ['goDecision' => $decision, 'dayStatusLog' => $this->withLog(cohort: $cohort, entry: $entry)]
			);
		}

		return (string)$this->restamp(cohortId: $cohortId);
	}//end decide()

	/**
	 * Refuse a decision that is neither go, no-go nor null.
	 *
	 * @param string|null $decision The decision.
	 *
	 * @return void
	 *
	 * @throws CourseDayException 400 on any other value.
	 */
	private static function assertDecision(?string $decision): void {
		if ($decision !== null && $decision !== 'go' && $decision !== 'no-go') {
			throw new CourseDayException(message: 'A decision is go or no-go.', status: 400);
		}
	}//end assertDecision()

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
			if (self::isCandidate(other: $other, cohortId: $cohortId) === false) {
				continue;
			}

			$day = $this->alternative(other: $other, today: $today);
			if ($day !== null) {
				$days[] = $day;
			}
		}

		usort($days, static fn (array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));

		return $days;
	}//end alternatives()

	/**
	 * Whether another cohort may take this day's participants at all: not this
	 * day itself and not cancelled.
	 *
	 * @param array<string, mixed> $other    The other cohort.
	 * @param string               $cohortId This day's uuid.
	 *
	 * @return bool
	 */
	private static function isCandidate(array $other, string $cohortId): bool {
		$id = self::idOf(row: $other);

		return $id !== '' && $id !== $cohortId && self::decision(value: ($other['goDecision'] ?? null)) !== 'no-go';
	}//end isCandidate()

	/**
	 * Another cohort as an alternative, or null when it is past, undated or has no free place.
	 *
	 * @param array<string, mixed> $other The other cohort.
	 * @param string               $today Today (Y-m-d).
	 *
	 * @return array{id: string, name: string, date: string, free: int|null}|null
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	private function alternative(array $other, string $today): ?array {
		$id   = self::idOf(row: $other);
		$date = self::lastDay(sessions: $this->many(schema: 'session', filters: ['cohortId' => $id]));
		if ($date === null || $date < $today) {
			return null;
		}

		$free = $this->freePlaces(cohortId: $id, capacity: self::intOrNull(value: ($other['capacity'] ?? null)));
		if ($free !== null && $free <= 0) {
			return null;
		}

		return ['id' => $id, 'name' => (string)($other['name'] ?? ''), 'date' => $date, 'free' => $free];
	}//end alternative()

	/**
	 * The free places of a cohort, or null without a maximum.
	 *
	 * @param string   $cohortId The cohort uuid.
	 * @param int|null $capacity Its maximum.
	 *
	 * @return int|null
	 */
	private function freePlaces(string $cohortId, ?int $capacity): ?int {
		if ($capacity === null || $capacity <= 0) {
			return null;
		}

		return ($capacity - $this->placesTaken(cohortId: $cohortId));
	}//end freePlaces()

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

		$this->assertRoomOn(cohortId: $cohortId, targetId: $targetId);
		$route      = ['from' => $cohortId, 'to' => $targetId];
		$bookings   = $this->moveRows(schema: 'course-booking', route: $route);
		$enrolments = $this->moveRows(schema: 'enrolment', route: $route);

		$entry = ['kind' => 'participants-moved', 'from' => $cohortId, 'to' => $targetId, 'by' => $userId];
		$this->save(cohort: $cohort, cohortId: $cohortId, changes: ['dayStatusLog' => $this->withLog(cohort: $cohort, entry: $entry)]);
		$this->restamp(cohortId: $cohortId);
		$this->restamp(cohortId: $targetId);

		return ['bookings' => $bookings, 'enrolments' => $enrolments, 'targetId' => $targetId];
	}//end moveParticipants()

	/**
	 * Refuse a target that is no coming day of the same course or has too few places.
	 *
	 * @param string $cohortId The day the participants leave.
	 * @param string $targetId The day they go to.
	 *
	 * @return void
	 *
	 * @throws CourseDayException 409 when the target cannot take them.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	private function assertRoomOn(string $cohortId, string $targetId): void {
		$target = self::dayById(days: $this->alternatives(cohortId: $cohortId), id: $targetId);
		if ($target === null) {
			throw new CourseDayException(message: 'Choose another coming day of the same course with places.', status: 409);
		}

		if ($target['free'] !== null && $this->placesTaken(cohortId: $cohortId) > $target['free']) {
			throw new CourseDayException(message: 'The chosen day has too few places for every participant.', status: 409);
		}
	}//end assertRoomOn()

	/**
	 * The last day with this id in a list of days, or null.
	 *
	 * @param list<array{id: string, free: int|null}> $days The days.
	 * @param string                                   $id   The id.
	 *
	 * @return array{id: string, free: int|null}|null
	 */
	private static function dayById(array $days, string $id): ?array {
		$found = null;
		foreach ($days as $day) {
			if ($day['id'] === $id) {
				$found = $day;
			}
		}

		return $found;
	}//end dayById()

	/**
	 * Move every row of a schema that holds a place from one day to another.
	 *
	 * @param string                         $schema `course-booking` or `enrolment`.
	 * @param array{from: string, to: string} $route  The day they leave and the day they go to.
	 *
	 * @return int How many rows moved.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	private function moveRows(string $schema, array $route): int {
		$moved = 0;
		foreach ($this->many(schema: $schema, filters: ['cohortId' => $route['from']]) as $row) {
			if (self::holdsPlace(schema: $schema, row: $row) === true) {
				$this->moveRow(schema: $schema, row: $row, targetId: $route['to']);
				$moved++;
			}
		}

		return $moved;
	}//end moveRows()

	/**
	 * Whether a booking (not cancelled) or an enrolment (placed) holds a place.
	 *
	 * @param string               $schema `course-booking` or `enrolment`.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return bool
	 */
	private static function holdsPlace(string $schema, array $row): bool {
		if ($schema === 'course-booking') {
			return self::isCancelled(booking: $row) === false;
		}

		return self::isPlaced(enrolment: $row);
	}//end holdsPlace()

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
		$past = CourseDayStatus::isPast(facts: $facts);
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
	 * The cohort's status log with one entry added, stamped now.
	 *
	 * @param array<string, mixed> $cohort The course day.
	 * @param array<string, mixed> $entry  The entry, without its time.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function withLog(array $cohort, array $entry): array {
		$entry['at'] = $this->now();

		return self::logged(log: ($cohort['dayStatusLog'] ?? null), entry: $entry);
	}//end withLog()

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
			uuid: self::idOf(row: $row),
			_rbac: false,
			_multitenancy: false
		);
	}//end moveRow()

	/**
	 * A row's id, falling back to its uuid, or ''.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ''));
	}//end idOf()

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
		[$total, $byBooking] = self::placedEnrolments(enrolments: $enrolments);
		$unbooked = $byBooking;
		foreach ($bookings as $booking) {
			$id = (string)($booking['id'] ?? '');
			unset($unbooked[$id]);
			if (self::isCancelled(booking: $booking) === false) {
				$total += max((int)($booking['participantCount'] ?? 0), ($byBooking[$id] ?? 0));
			}
		}

		// Enrolments on a booking that is not on this day still hold their places here.
		return ($total + array_sum($unbooked));
	}//end participants()

	/**
	 * The placed enrolments: how many name no booking, and how many name each booking.
	 *
	 * @param list<array<string, mixed>> $enrolments The day's enrolments.
	 *
	 * @return array{0: int, 1: array<string, int>}
	 */
	private static function placedEnrolments(array $enrolments): array {
		$byBooking = [];
		$loose     = 0;
		foreach (array_filter($enrolments, [self::class, 'isPlaced']) as $enrolment) {
			$booking = (string)($enrolment['bookingRef'] ?? '');
			if ($booking === '') {
				$loose++;
				continue;
			}

			$byBooking[$booking] = (($byBooking[$booking] ?? 0) + 1);
		}

		return [$loose, $byBooking];
	}//end placedEnrolments()

	/**
	 * Whether an enrolment holds a place (pending, active or completed).
	 *
	 * @param array<string, mixed> $enrolment The enrolment.
	 *
	 * @return bool
	 */
	private static function isPlaced(array $enrolment): bool {
		return in_array((string)($enrolment['lifecycle'] ?? 'pending'), self::PLACED, true);
	}//end isPlaced()

	/**
	 * Whether a booking is cancelled.
	 *
	 * @param array<string, mixed> $booking The booking.
	 *
	 * @return bool
	 */
	private static function isCancelled(array $booking): bool {
		return (string)($booking['lifecycle'] ?? 'received') === 'cancelled';
	}//end isCancelled()

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
			$date = self::dateOf(session: $session);
			// Any valid date sorts after the empty string, so the first one is taken.
			if ($date !== null && $date > (string)$last) {
				$last = $date;
			}
		}

		return $last;
	}//end lastDay()

	/**
	 * A session's date (Y-m-d), or null when it has no valid start.
	 *
	 * @param array<string, mixed> $session The session.
	 *
	 * @return string|null
	 */
	private static function dateOf(array $session): ?string {
		$date = substr((string)($session['startsAt'] ?? ''), 0, 10);

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return null;
		}

		return $date;
	}//end dateOf()

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
			if (self::matches(row: $row, filters: $filters) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end many()

	/**
	 * Whether a row carries every filtered value.
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param array<string, mixed> $filters Field => value.
	 *
	 * @return bool
	 */
	private static function matches(array $row, array $filters): bool {
		foreach ($filters as $field => $value) {
			if (($row[$field] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end matches()
}//end class
