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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-never-shows-personal-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Kernel\Contracts\DisplayScreenBoardInterface;
use OCA\Learniq\Kernel\Contracts\TimetableSourceResolverInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\ICacheFactory;
use Throwable;

/**
 * The lessons one screen shows.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
 */
class DisplayScreenBoard implements DisplayScreenBoardInterface {

	public const KEYS = ['startsAt', 'endsAt', 'group', 'subject', 'room', 'teacherCode', 'change'];
	private const REGISTER = 'learniq';
	private const TIMEZONE = 'Europe/Amsterdam';
	private const CACHE_SECONDS = 60;

	/**
	 * Constructor.
	 *
	 * @param ObjectService           $objectService OpenRegister objects, read as the system.
	 * @param TimetableSourceResolverInterface $sources       Planninq or learniq's own sessions.
	 * @param ICacheFactory           $cacheFactory  The one-minute cache.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TimetableSourceResolverInterface $sources,
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
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
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
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-never-shows-personal-data
	 */
	public function build(array $screen): array {
		$tz = new DateTimeZone(self::TIMEZONE);
		$window = $this->window(screen: $screen, tz: $tz);
		$view = [
			'window' => $window,
			'cohorts' => $this->cohorts(screen: $screen),
			'rooms' => $this->rooms(screen: $screen),
			'roomIds' => $this->listedIds(value: ($screen['roomIds'] ?? [])),
			'showTeacher' => (($screen['showTeacherCodes'] ?? true) !== false),
			'changesOnly' => (($screen['shows'] ?? 'today') === 'changes-only'),
		];
		$rows = $this->sources->current()->sessionsForCohorts(cohortIds: array_keys($view['cohorts']), from: $window[0], to: $window[1]);
		$lessons = $this->lessonsOf(rows: $rows, view: $view);

		usort($lessons, static fn (array $left, array $right): int => [$left['startsAt'], $left['group']] <=> [$right['startsAt'], $right['group']]);

		return [
			'name' => (string)($screen['name'] ?? ''),
			'updatedAt' => (new DateTimeImmutable('now', $tz))->format(DATE_ATOM),
			'lessons' => $lessons,
		];
	}//end build()

	/**
	 * Today, or today and tomorrow, from midnight in the school's time zone.
	 *
	 * @param array<string, mixed> $screen The screen.
	 * @param DateTimeZone         $tz     The school's time zone.
	 *
	 * @return array{0: string, 1: string} Start and exclusive end, ISO 8601.
	 */
	private function window(array $screen, DateTimeZone $tz): array {
		$today = new DateTimeImmutable('today', $tz);
		$days = 1;
		if (($screen['shows'] ?? 'today') === 'today-and-tomorrow') {
			$days = 2;
		}

		return [$today->format(DATE_ATOM), $today->modify('+'.$days.' days')->format(DATE_ATOM)];
	}//end window()

	/**
	 * The public shape of every lesson the screen shows.
	 *
	 * @param array<int, array<string, mixed>> $rows The lessons in learniq's session shape.
	 * @param array<string, mixed>             $view `window`, `cohorts`, `rooms`, `roomIds`, `showTeacher` and `changesOnly`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function lessonsOf(array $rows, array $view): array {
		$lessonShape = new DisplayScreenLesson();
		$lessons = [];
		foreach ($rows as $row) {
			if ($this->isOnScreen(row: $row, view: $view) === false) {
				continue;
			}

			$lesson = $lessonShape->shape(row: $row, view: $view);
			if ($view['changesOnly'] === true && $lesson['change'] === null) {
				continue;
			}

			$lessons[] = $lesson;
		}

		return $lessons;
	}//end lessonsOf()

	/**
	 * Whether a lesson starts in the screen's window and is in its rooms.
	 *
	 * @param array<string, mixed> $row  The lesson.
	 * @param array<string, mixed> $view The screen's `window`, `roomIds` and `rooms`.
	 *
	 * @return bool
	 */
	private function isOnScreen(array $row, array $view): bool {
		return $this->inWindow(row: $row, window: $view['window']) === true && $this->inRooms(row: $row, view: $view) === true;
	}//end isOnScreen()

	/**
	 * Whether a lesson starts inside the window.
	 *
	 * @param array<string, mixed>        $row    The lesson.
	 * @param array{0: string, 1: string} $window Window start and end (exclusive).
	 *
	 * @return bool
	 */
	private function inWindow(array $row, array $window): bool {
		$start = strtotime((string)($row['startsAt'] ?? ''));

		return $start !== false && $start >= strtotime($window[0]) && $start < strtotime($window[1]);
	}//end inWindow()

	/**
	 * Whether a lesson is in the screen's rooms, when it names any.
	 *
	 * @param array<string, mixed> $row  The lesson.
	 * @param array<string, mixed> $view The screen's `roomIds` and its `rooms` by id.
	 *
	 * @return bool
	 */
	private function inRooms(array $row, array $view): bool {
		if ($view['roomIds'] === [] || isset($view['rooms'][(string)($row['roomId'] ?? '')]) === true) {
			return true;
		}

		// A planninq lesson names its room by the school's code.
		$reference = (string)($row['roomReference'] ?? '');

		return $reference !== '' && in_array($reference, array_column($view['rooms'], 'code'), true) === true;
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
		$ids = $this->listedIds(value: ($screen['cohortIds'] ?? []));
		if ($ids !== []) {
			$config['ids'] = $ids;
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
		$ids = $this->listedIds(value: ($screen['roomIds'] ?? []));
		if ($ids !== []) {
			$config['ids'] = $ids;
		}

		$rooms = [];
		foreach ($this->read(config: $config) as $room) {
			$rooms[(string)($room['id'] ?? '')] = $room;
		}

		unset($rooms['']);

		return $rooms;
	}//end rooms()

	/**
	 * A screen's list of ids; none when it is not a list.
	 *
	 * @param mixed $value The screen's `cohortIds` or `roomIds`.
	 *
	 * @return array<int, mixed>
	 */
	private function listedIds(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values($value);
	}//end listedIds()

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
			$data = $this->rowData(row: $row);
			if ($data !== null) {
				$out[] = $data;
			}
		}

		return $out;
	}//end read()

	/**
	 * A row as an array: as it is, or serialised; null when it is neither.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function rowData(mixed $row): ?array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return null;
		}

		return $row;
	}//end rowData()
}//end class
