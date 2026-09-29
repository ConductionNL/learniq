<?php

/**
 * Learniq Room Utilisation Service
 *
 * How well rooms are used over a period (timetabling-room-utilisation): per
 * room the hours in use (lessons that were not cancelled, clipped to opening
 * hours), the hours the building is open (opening hours per weekday on
 * teaching days, holidays left out), the occupancy rate and the average fill
 * (group size against capacity), plus a weekday by hour grid of the share of
 * rooms in use and the lessons that have no room at all.
 *
 * Lessons come from the current timetable source: planninq's school timetable
 * when planninq is installed (decision D10), learniq's own Session otherwise.
 * A planninq lesson names its room by `roomReference`, which is matched to a
 * learniq Room by `Room.code`. Rooms stay learniq objects. Nothing is stored.
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
 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;
use Throwable;

/**
 * Computes room use over a period.
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 */
class RoomUtilisationService {

	private const REGISTER = 'learniq';

	/**
	 * How many lessons without a room the report lists by name.
	 */
	private const UNASSIGNED_LISTED = 50;

	/**
	 * Constructor.
	 *
	 * @param ObjectService           $objectService Rooms, cohorts and report periods (caller's RBAC).
	 * @param TimetableSourceResolver $sources       Where lessons are read from.
	 * @param OpeningHoursSettings    $openingHours  Opening hours per weekday.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TimetableSourceResolver $sources,
		private readonly OpeningHoursSettings $openingHours,
	) {
	}//end __construct()

	/**
	 * Room use from `$from` (inclusive) to `$to` (exclusive), both `Y-m-d`.
	 *
	 * @param string      $from     First day.
	 * @param string      $to       Day after the last day.
	 * @param string|null $kind     Only rooms of this kind, or null.
	 * @param string|null $building Only rooms in this building, or null.
	 *
	 * @return array<string,mixed> `rooms`, `grid`, `unassigned`, `openHoursPerRoom`, `teachingDays`, `source`.
	 *
	 * @throws RuntimeException When the timetable source does not answer.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function forPeriod(string $from, string $to, ?string $kind = null, ?string $building = null): array {
		$settings = $this->openingHours->get();
		$days = $this->teachingDays(from: $from, to: $to, settings: $settings);
		$rooms = $this->rooms(kind: $kind, building: $building);
		$groupSizes = $this->groupSizes();

		$source = $this->sources->current();
		$sessions = $source->sessionsForCohorts(
			cohortIds: array_keys($groupSizes),
			from: $from . 'T00:00:00+00:00',
			to: $to . 'T00:00:00+00:00'
		);

		$tally = new RoomUseTally(rooms: $rooms, days: $days);
		$roomByCode = $this->roomsByCode();
		foreach ($sessions as $session) {
			$tally->add(session: $session, roomId: $this->roomOf(session: $session, roomByCode: $roomByCode), groupSizes: $groupSizes);
		}

		$openMinutes = array_sum(array_column($days, 'minutes'));

		return [
			'from' => $from,
			'to' => $to,
			'teachingDays' => count($days),
			'openHoursPerRoom' => round($openMinutes / 60, 1),
			'rooms' => $tally->roomRows(openMinutes: $openMinutes),
			'grid' => $tally->grid(),
			'unassigned' => $tally->unassigned(limit: self::UNASSIGNED_LISTED),
			'source' => $source->name(),
		];
	}//end forPeriod()

	/**
	 * The teaching days of the window with their opening hours in minutes of the day.
	 *
	 * @param string              $from     First day, `Y-m-d`.
	 * @param string              $to       Day after the last day, `Y-m-d`.
	 * @param array<string,mixed> $settings The opening hours.
	 *
	 * @return array<string,array{weekday:int,opens:int,closes:int,minutes:int}> Keyed by `Y-m-d`.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function teachingDays(string $from, string $to, array $settings): array {
		$start = $this->parseDay(day: $from);
		$end = $this->parseDay(day: $to);
		if ($start === null || $end === null || $end <= $start) {
			return [];
		}

		$closed = $this->closedDays(settings: $settings);
		$days = [];
		for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
			$iso = $day->format('Y-m-d');
			$weekday = ((int)$day->format('N')) - 1;
			$hours = ($settings['weekdays'][OpeningHoursSettings::WEEKDAYS[$weekday]] ?? null);
			if (is_array($hours) === false || isset($closed[$iso]) === true) {
				continue;
			}

			$opens = $this->minutesOf(time: (string)$hours['opens']);
			$closes = $this->minutesOf(time: (string)$hours['closes']);
			$days[$iso] = ['weekday' => $weekday, 'opens' => $opens, 'closes' => $closes, 'minutes' => max(0, $closes - $opens)];
		}

		return $days;
	}//end teachingDays()

	/**
	 * A `Y-m-d` day at midnight, or null when it is not a real date.
	 *
	 * @param string $day The day.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function parseDay(string $day): ?DateTimeImmutable {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
			return null;
		}

		try {
			$parsed = new DateTimeImmutable($day . 'T00:00:00');
		} catch (Throwable $exception) {
			return null;
		}

		if ($parsed->format('Y-m-d') !== $day) {
			return null;
		}

		return $parsed;
	}//end parseDay()

	/**
	 * The holidays, and the study days when rooms close then, of every report period.
	 *
	 * @param array<string,mixed> $settings The opening hours.
	 *
	 * @return array<string,true> Closed days keyed by `Y-m-d`.
	 */
	private function closedDays(array $settings): array {
		$closed = [];
		foreach ($this->read(schema: 'report-period', filters: []) as $period) {
			foreach ((array)($period['holidays'] ?? []) as $holiday) {
				$closed += $this->dateRange(from: (string)($holiday['startDate'] ?? ''), to: (string)($holiday['endDate'] ?? ''));
			}

			if (($settings['closedOnStudyDays'] ?? false) !== true) {
				continue;
			}

			foreach ((array)($period['studyDays'] ?? []) as $studyDay) {
				$closed += $this->dateRange(from: (string)($studyDay['date'] ?? ''), to: (string)($studyDay['date'] ?? ''));
			}
		}

		return $closed;
	}//end closedDays()

	/**
	 * Every day from `$from` to `$to`, both inclusive.
	 *
	 * @param string $from First day, `Y-m-d`.
	 * @param string $to   Last day, `Y-m-d`.
	 *
	 * @return array<string,true>
	 */
	private function dateRange(string $from, string $to): array {
		$start = $this->parseDay(day: $from);
		$end = $this->parseDay(day: $to);
		if ($start === null || $end === null) {
			return [];
		}

		$out = [];
		$guard = 0;
		for ($day = $start; $day <= $end && $guard < 400; $day = $day->modify('+1 day')) {
			$out[$day->format('Y-m-d')] = true;
			$guard++;
		}

		return $out;
	}//end dateRange()

	/**
	 * `HH:MM` as minutes of the day.
	 *
	 * @param string $time The clock time.
	 *
	 * @return int
	 */
	private function minutesOf(string $time): int {
		[$hours, $minutes] = array_pad(explode(':', $time), 2, '0');
		return ((int)$hours * 60) + (int)$minutes;
	}//end minutesOf()

	/**
	 * The rooms, filtered on kind and building.
	 *
	 * @param string|null $kind     Room kind, or null.
	 * @param string|null $building Building code, or null.
	 *
	 * @return array<string,array<string,mixed>> Rooms keyed by id.
	 */
	private function rooms(?string $kind, ?string $building): array {
		$filters = [];
		if ($kind !== null && $kind !== '') {
			$filters['kind'] = $kind;
		}

		if ($building !== null && $building !== '') {
			$filters['buildingCode'] = $building;
		}

		$rooms = [];
		foreach ($this->read(schema: 'room', filters: $filters) as $room) {
			$id = (string)($room['id'] ?? ($room['uuid'] ?? ''));
			// Defensive: never count a room the filter should have left out.
			$outside = array_diff_assoc(array_intersect_key($room, $filters), $filters);
			if ($id !== '' && $outside === []) {
				$rooms[$id] = $room;
			}
		}

		return $rooms;
	}//end rooms()

	/**
	 * Every room keyed by its code, for planninq lessons that name a room by code.
	 *
	 * @return array<string,string> Room id by code.
	 */
	private function roomsByCode(): array {
		$out = [];
		foreach ($this->read(schema: 'room', filters: []) as $room) {
			$code = (string)($room['code'] ?? '');
			if ($code !== '') {
				$out[$code] = (string)($room['id'] ?? ($room['uuid'] ?? ''));
			}
		}

		return $out;
	}//end roomsByCode()

	/**
	 * The group size of every cohort, keyed by cohort id.
	 *
	 * @return array<string,int>
	 */
	private function groupSizes(): array {
		$sizes = [];
		foreach ($this->read(schema: 'cohort', filters: []) as $cohort) {
			$id = (string)($cohort['id'] ?? ($cohort['uuid'] ?? ''));
			if ($id !== '') {
				$sizes[$id] = count((array)($cohort['learnerIds'] ?? []));
			}
		}

		return $sizes;
	}//end groupSizes()

	/**
	 * The learniq room of a lesson: its `roomId`, or its planninq room code.
	 *
	 * @param array<string,mixed> $session    The lesson.
	 * @param array<string,string> $roomByCode Room id by code.
	 *
	 * @return string The room id, or '' when the lesson has no room.
	 */
	private function roomOf(array $session, array $roomByCode): string {
		$roomId = (string)($session['roomId'] ?? '');
		if ($roomId !== '') {
			return $roomId;
		}

		return ($roomByCode[(string)($session['roomReference'] ?? '')] ?? '');
	}//end roomOf()

	/**
	 * Read learniq objects as plain arrays.
	 *
	 * @param string              $schema  Schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function read(string $schema, array $filters): array {
		$rows = $this->objectService->findAll(
			['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters)]
		);

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
	}//end read()
}//end class
