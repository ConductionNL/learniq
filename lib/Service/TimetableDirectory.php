<?php

/**
 * Learniq Timetable Directory
 *
 * The reads behind the Timetables page (timetabling-visibility-rules): the
 * school's visibility policy, every cohort, room and a caller's enrolments,
 * and the lessons of one group, teacher or room. Cohorts, rooms and
 * enrolments are read without the caller's RBAC: a learner cannot list
 * cohorts, and the visibility policy, checked by
 * {@see TimetableVisibilityService} before anything is returned, decides what
 * the caller sees. The policy is read with the caller's RBAC (every signed-in
 * user reads it).
 *
 * @category Service
 * @package  OCA\Learniq\Service
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

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\TimetableDirectoryInterface;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;

/**
 * Reads the policy, cohorts, rooms and other timetables' lessons.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
 */
class TimetableDirectory implements TimetableDirectoryInterface {

	private const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService           $objectService OpenRegister object access.
	 * @param TimetableSourceResolver $sources       Where lessons are read from.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TimetableSourceResolver $sources,
	) {
	}//end __construct()

	/**
	 * The tenant's policy rows, read with the caller's RBAC.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see
	 */
	public function policyRows(): array {
		$config = ['filters' => ['register' => self::REGISTER, 'schema' => 'timetable-visibility-policy']];
		return $this->toArrays(rows: $this->objectService->findAll($config));
	}//end policyRows()

	/**
	 * Every cohort.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	public function cohorts(): array {
		return $this->system(schema: 'cohort', filters: []);
	}//end cohorts()

	/**
	 * Every room.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	public function rooms(): array {
		return $this->system(schema: 'room', filters: []);
	}//end rooms()

	/**
	 * A learner's enrolments.
	 *
	 * @param string $uid The learner.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	public function enrolmentsOf(string $uid): array {
		return $this->system(schema: 'enrolment', filters: ['learnerId' => $uid]);
	}//end enrolmentsOf()

	/**
	 * The lessons of one cohort, teacher or room in a window.
	 *
	 * @param string $kind `cohort`, `teacher` or `room`.
	 * @param string $id   The cohort, teacher or room id.
	 * @param string $from Window start, ISO 8601.
	 * @param string $to   Window end, ISO 8601.
	 *
	 * @return array{sessions:array<int,array<string,mixed>>,source:string} Raw lessons in learniq's session shape.
	 *
	 * @throws RuntimeException When the timetable source does not answer.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	public function lessonsOf(string $kind, string $id, string $from, string $to): array {
		if ($kind === 'cohort') {
			return $this->cohortLessons(id: $id, window: [$from, $to]);
		}

		if ($kind === 'teacher') {
			return $this->teacherLessons(id: $id, window: [$from, $to]);
		}

		return $this->roomLessons(id: $id, window: [$from, $to]);
	}//end lessonsOf()

	/**
	 * The lessons of one cohort in a window.
	 *
	 * @param string                   $id     The cohort id.
	 * @param array{0:string,1:string} $window Window start and end, ISO 8601.
	 *
	 * @return array{sessions:array<int,array<string,mixed>>,source:string}
	 *
	 * @throws RuntimeException When the timetable source does not answer.
	 */
	private function cohortLessons(string $id, array $window): array {
		$source = $this->sources->current();
		return ['sessions' => $source->sessionsForCohorts(cohortIds: [$id], from: $window[0], to: $window[1]), 'source' => $source->name()];
	}//end cohortLessons()

	/**
	 * The lessons of the cohorts a teacher teaches and the lessons the teacher covers, each once.
	 *
	 * @param string                   $id     The teacher's user id.
	 * @param array{0:string,1:string} $window Window start and end, ISO 8601.
	 *
	 * @return array{sessions:array<int,array<string,mixed>>,source:string}
	 *
	 * @throws RuntimeException When the timetable source does not answer.
	 */
	private function teacherLessons(string $id, array $window): array {
		$source = $this->sources->current();
		$taught = [];
		foreach ($this->cohorts() as $cohort) {
			if (in_array($id, (array)($cohort['teacherIds'] ?? []), true) === true) {
				$taught[] = $this->idOf(row: $cohort);
			}
		}

		$sessions = array_merge(
			$source->sessionsForCohorts(cohortIds: $taught, from: $window[0], to: $window[1]),
			$source->sessionsForTeacher(userId: $id, from: $window[0], to: $window[1])
		);
		return ['sessions' => $this->uniqueById(sessions: $sessions), 'source' => $source->name()];
	}//end teacherLessons()

	/**
	 * The lessons of every cohort that take place in a room, by its id or its code.
	 *
	 * @param string                   $id     The room id.
	 * @param array{0:string,1:string} $window Window start and end, ISO 8601.
	 *
	 * @return array{sessions:array<int,array<string,mixed>>,source:string}
	 *
	 * @throws RuntimeException When the timetable source does not answer.
	 */
	private function roomLessons(string $id, array $window): array {
		$source = $this->sources->current();
		$cohorts = $this->cohorts();
		$room = ['id' => $id, 'code' => (string)($this->roomCodes()[$id] ?? '')];
		$cohortIds = array_map(fn (array $c): string => $this->idOf(row: $c), $cohorts);
		$sessions = array_filter(
			$source->sessionsForCohorts(cohortIds: $cohortIds, from: $window[0], to: $window[1]),
			fn (array $session): bool => $this->inRoom(session: $session, room: $room)
		);

		return ['sessions' => array_values($sessions), 'source' => $source->name()];
	}//end roomLessons()

	/**
	 * Whether a lesson takes place in the room: by its room id, or by the room's code when it has one.
	 *
	 * @param array<string,mixed>         $session The lesson.
	 * @param array{id:string,code:string} $room    The room id and code.
	 *
	 * @return bool
	 */
	private function inRoom(array $session, array $room): bool {
		return (string)($session['roomId'] ?? '') === $room['id']
			|| ($room['code'] !== '' && (string)($session['roomReference'] ?? '') === $room['code']);
	}//end inRoom()

	/**
	 * Room code by room id.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	public function roomCodes(): array {
		$out = [];
		foreach ($this->rooms() as $room) {
			$out[$this->idOf(row: $room)] = (string)($room['code'] ?? '');
		}

		return $out;
	}//end roomCodes()

	/**
	 * Lessons without duplicates, keeping the first of each id.
	 *
	 * @param array<int,array<string,mixed>> $sessions Lessons.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function uniqueById(array $sessions): array {
		$out = [];
		foreach ($sessions as $index => $session) {
			$key = (string)($session['id'] ?? ('#' . $index));
			$out[$key] ??= $session;
		}

		return array_values($out);
	}//end uniqueById()

	/**
	 * The id of a row.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	public function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ''));
	}//end idOf()

	/**
	 * The rooms of the lessons of these cohorts, from eight weeks back to eight ahead.
	 *
	 * @param array<int,string> $cohortIds The cohorts.
	 *
	 * @return array<int,string> Room ids.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	public function roomsOfCohorts(array $cohortIds): array {
		if ($cohortIds === []) {
			return [];
		}

		try {
			$sessions = $this->sources->current()->sessionsForCohorts(
				cohortIds: $cohortIds,
				from: gmdate(DATE_ATOM, (time() - (56 * 86400))),
				to: gmdate(DATE_ATOM, (time() + (56 * 86400)))
			);
		} catch (RuntimeException $exception) {
			return [];
		}

		$byCode = array_flip(array_filter($this->roomCodes()));
		$rooms = [];
		foreach ($sessions as $session) {
			$roomId = $this->roomIdOf(session: $session, byCode: $byCode);
			if ($roomId !== '') {
				$rooms[$roomId] = true;
			}
		}

		return array_keys($rooms);
	}//end roomsOfCohorts()

	/**
	 * The room of a lesson: its room id, or the room whose code it names.
	 *
	 * @param array<string,mixed>      $session The lesson.
	 * @param array<string,int|string> $byCode  Room id by code.
	 *
	 * @return string The room id, or ''.
	 */
	private function roomIdOf(array $session, array $byCode): string {
		$roomId = (string)($session['roomId'] ?? '');
		if ($roomId === '') {
			return (string)($byCode[(string)($session['roomReference'] ?? '')] ?? '');
		}

		return $roomId;
	}//end roomIdOf()

	/**
	 * Every cohort or room with its name, or every teacher of a cohort (keyed by user id).
	 *
	 * @param string $kind `cohort`, `teacher` or `room`.
	 *
	 * @return array<string,string> Name by id; a teacher's name is their user id.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	public function labels(string $kind): array {
		$out = [];
		$rows = $this->cohorts();
		if ($kind === 'room') {
			$rows = $this->rooms();
		}

		foreach ($rows as $row) {
			if ($kind !== 'teacher') {
				$out[$this->idOf(row: $row)] = (string)($row['name'] ?? '');
				continue;
			}

			foreach ((array)($row['teacherIds'] ?? []) as $teacher) {
				$out[(string)$teacher] = (string)$teacher;
			}
		}

		unset($out['']);
		return $out;
	}//end labels()

	/**
	 * Read learniq objects without the caller's RBAC (see the class comment).
	 *
	 * @param string              $schema  Schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function system(string $schema, array $filters): array {
		return $this->toArrays(
			rows: $this->objectService->findAll(
				['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters)],
				_rbac: false
			)
		);
	}//end system()

	/**
	 * ObjectService rows as plain arrays.
	 *
	 * @param array<int,mixed> $rows The rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function toArrays(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = (array)$row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end toArrays()
}//end class
