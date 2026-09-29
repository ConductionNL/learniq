<?php

/**
 * Learniq Display Screen Board
 *
 * What a hall screen shows: the lessons of today (and tomorrow) for the
 * screen's groups, or its location's groups, optionally narrowed to its rooms,
 * read through the current timetable source (planninq when installed, D10 and
 * D25; learniq's own sessions otherwise). Every lesson is projected onto a
 * pinned shape of seven keys, so no learner, user id, reason or affected
 * person can reach the public page. The answer is cached per screen for a
 * minute.
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
 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-a-display-screen-never-shows-personal-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\ICacheFactory;
use Throwable;

/**
 * The lessons one screen shows.
 *
 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
 */
class DisplayScreenBoard {

	public const KEYS = ['startsAt', 'endsAt', 'group', 'subject', 'room', 'teacherCode', 'change'];
	private const REGISTER = 'learniq';
	private const TIMEZONE = 'Europe/Amsterdam';
	private const CACHE_SECONDS = 60;

	/**
	 * Constructor.
	 *
	 * @param ObjectService           $objectService OpenRegister objects, read as the system.
	 * @param TimetableSourceResolver $sources       Planninq or learniq's own sessions.
	 * @param ICacheFactory           $cacheFactory  The one-minute cache.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TimetableSourceResolver $sources,
		private readonly ICacheFactory $cacheFactory,
	) {
	}//end __construct()

	/**
	 * The screen's lessons, from the cache when it is under a minute old.
	 *
	 * @param array<string, mixed> $screen The screen.
	 *
	 * @return array{name: string, updatedAt: string, lessons: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
	 */
	public function forScreen(array $screen): array {
		$cache = $this->cacheFactory->createDistributed('learniq-display');
		$key = (string)($screen['id'] ?? '');
		$cached = $cache->get($key);
		if (is_array($cached) === true) {
			return $cached;
		}

		$board = $this->objectService->runAsSystem(fn (): array => $this->build(screen: $screen));
		$cache->set($key, $board, self::CACHE_SECONDS);

		return $board;
	}//end forScreen()

	/**
	 * Build the board.
	 *
	 * @param array<string, mixed> $screen The screen.
	 *
	 * @return array{name: string, updatedAt: string, lessons: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-a-display-screen-never-shows-personal-data
	 */
	public function build(array $screen): array {
		$tz = new DateTimeZone(self::TIMEZONE);
		$today = new DateTimeImmutable('today', $tz);
		$days = 1;
		if (($screen['shows'] ?? 'today') === 'today-and-tomorrow') {
			$days = 2;
		}

		$from = $today->format(DATE_ATOM);
		$until = $today->modify('+'.$days.' days')->format(DATE_ATOM);

		$cohorts = $this->cohorts(screen: $screen);
		$rooms = $this->rooms(screen: $screen);
		$rows = $this->sources->current()->sessionsForCohorts(cohortIds: array_keys($cohorts), from: $from, to: $until);

		$lessons = [];
		foreach ($rows as $row) {
			if ($this->inWindow(row: $row, from: $from, until: $until) === false || $this->inRooms(row: $row, rooms: $rooms, screen: $screen) === false) {
				continue;
			}

			$lesson = $this->project(row: $row, cohorts: $cohorts, rooms: $rooms, showTeacher: (($screen['showTeacherCodes'] ?? true) !== false));
			if (($screen['shows'] ?? 'today') === 'changes-only' && $lesson['change'] === null) {
				continue;
			}

			$lessons[] = $lesson;
		}

		usort($lessons, static fn (array $left, array $right): int => [$left['startsAt'], $left['group']] <=> [$right['startsAt'], $right['group']]);

		return [
			'name' => (string)($screen['name'] ?? ''),
			'updatedAt' => (new DateTimeImmutable('now', $tz))->format(DATE_ATOM),
			'lessons' => $lessons,
		];
	}//end build()

	/**
	 * Project one lesson onto the pinned public shape.
	 *
	 * @param array<string, mixed>                $row         A lesson in learniq's session shape.
	 * @param array<string, string>               $cohorts     Group names by cohort id.
	 * @param array<string, array<string, mixed>> $rooms       Rooms by id.
	 * @param bool                                $showTeacher Whether teacher codes are shown.
	 *
	 * @return array<string, mixed> Exactly the keys in KEYS.
	 */
	private function project(array $row, array $cohorts, array $rooms, bool $showTeacher): array {
		$roomId = (string)($row['roomId'] ?? '');
		$room = (string)($row['location'] ?? '');
		if ($roomId !== '' && isset($rooms[$roomId]) === true) {
			$room = (string)($rooms[$roomId]['name'] ?? $room);
		}

		$teacher = null;
		if ($showTeacher === true && ($row['teacherReference'] ?? '') !== '') {
			$teacher = (string)$row['teacherReference'];
		}

		$group = (string)($cohorts[(string)($row['cohortId'] ?? '')] ?? ($row['groupReference'] ?? ''));

		return [
			'startsAt' => (string)($row['startsAt'] ?? ''),
			'endsAt' => (string)($row['endsAt'] ?? ''),
			'group' => $group,
			'subject' => (string)($row['subject'] ?? ($row['title'] ?? '')),
			'room' => $room,
			'teacherCode' => $teacher,
			'change' => $this->change(row: $row),
		];
	}//end project()

	/**
	 * The change a screen marks: cancelled, another teacher or another room.
	 *
	 * @param array<string, mixed> $row The lesson.
	 *
	 * @return string|null
	 */
	private function change(array $row): ?string {
		if (($row['lifecycle'] ?? '') === 'cancelled') {
			return 'cancelled';
		}

		if (is_string($row['substituteTeacherId'] ?? null) === true && $row['substituteTeacherId'] !== '') {
			return 'other-teacher';
		}

		if (($row['changeReasonKind'] ?? null) === 'room-unavailable') {
			return 'other-room';
		}

		return null;
	}//end change()

	/**
	 * Whether a lesson starts inside the window.
	 *
	 * @param array<string, mixed> $row   The lesson.
	 * @param string               $from  Window start.
	 * @param string               $until Window end (exclusive).
	 *
	 * @return bool
	 */
	private function inWindow(array $row, string $from, string $until): bool {
		$start = strtotime((string)($row['startsAt'] ?? ''));

		return $start !== false && $start >= strtotime($from) && $start < strtotime($until);
	}//end inWindow()

	/**
	 * Whether a lesson is in the screen's rooms, when it names any.
	 *
	 * @param array<string, mixed>                $row    The lesson.
	 * @param array<string, array<string, mixed>> $rooms  The screen's rooms by id.
	 * @param array<string, mixed>                $screen The screen.
	 *
	 * @return bool
	 */
	private function inRooms(array $row, array $rooms, array $screen): bool {
		$roomIds = $screen['roomIds'] ?? [];
		if (is_array($roomIds) === false || $roomIds === []) {
			return true;
		}

		if (isset($rooms[(string)($row['roomId'] ?? '')]) === true) {
			return true;
		}

		// A planninq lesson names its room by the school's code.
		$reference = (string)($row['roomReference'] ?? '');

		return $reference !== '' && in_array($reference, array_column($rooms, 'code'), true) === true;
	}//end inRooms()

	/**
	 * The screen's groups: its own list, or every group of its location.
	 *
	 * @param array<string, mixed> $screen The screen.
	 *
	 * @return array<string, string> Group name by cohort id.
	 */
	private function cohorts(array $screen): array {
		$config = $this->cohortQuery(screen: $screen);
		if ($config === null) {
			return [];
		}

		$names = [];
		foreach ($this->read(config: $config) as $cohort) {
			$names[(string)($cohort['id'] ?? '')] = (string)($cohort['name'] ?? '');
		}

		unset($names['']);

		return $names;
	}//end cohorts()

	/**
	 * The query for the screen's groups: its own list, else its location's.
	 *
	 * @param array<string, mixed> $screen The screen.
	 *
	 * @return array<string, mixed>|null Null when the screen names neither.
	 */
	private function cohortQuery(array $screen): ?array {
		$config = ['filters' => ['register' => self::REGISTER, 'schema' => 'cohort']];
		$ids = $screen['cohortIds'] ?? [];
		if (is_array($ids) === true && $ids !== []) {
			$config['ids'] = array_values($ids);
			return $config;
		}

		$location = $screen['vestigingId'] ?? null;
		if (is_string($location) === false || $location === '') {
			return null;
		}

		$config['filters']['locationId'] = $location;

		return $config;
	}//end cohortQuery()

	/**
	 * The screen's rooms, or every room when it names none (for their names).
	 *
	 * @param array<string, mixed> $screen The screen.
	 *
	 * @return array<string, array<string, mixed>> Rooms by id.
	 */
	private function rooms(array $screen): array {
		$config = ['filters' => ['register' => self::REGISTER, 'schema' => 'room']];
		$ids = $screen['roomIds'] ?? [];
		if (is_array($ids) === true && $ids !== []) {
			$config['ids'] = array_values($ids);
		}

		$rooms = [];
		foreach ($this->read(config: $config) as $room) {
			$rooms[(string)($room['id'] ?? '')] = $room;
		}

		unset($rooms['']);

		return $rooms;
	}//end rooms()

	/**
	 * Read rows as arrays.
	 *
	 * @param array<string, mixed> $config A findAll config.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read(array $config): array {
		try {
			$rows = $this->objectService->findAll($config, _rbac: false, _multitenancy: false);
		} catch (Throwable $exception) {
			unset($exception);
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
			} else if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$out[] = (array)$row->jsonSerialize();
			}
		}

		return $out;
	}//end read()
}//end class
