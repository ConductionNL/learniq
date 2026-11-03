<?php

/**
 * Learniq Room Use Tally
 *
 * The running totals of one room use report
 * ({@see RoomUtilisationService}): minutes in use per room, the fill of each
 * lesson, minutes in use per weekday and clock hour, and the lessons without a
 * room. A value object built per report; not a service.
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
use Throwable;

/**
 * Accumulates room use for one report.
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 */
class RoomUseTally {

	/**
	 * Minutes in use per room id.
	 *
	 * @var array<string,int>
	 */
	private array $minutes = [];

	/**
	 * Sum and count of group size over capacity per room id.
	 *
	 * @var array<string,array{sum:float,count:int}>
	 */
	private array $fill = [];

	/**
	 * Minutes in use per weekday (0 is Monday) and clock hour.
	 *
	 * @var array<int,array<int,int>>
	 */
	private array $grid = [];

	/**
	 * Lessons in the window without a room.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $unassigned = [];

	/**
	 * Constructor.
	 *
	 * @param array<string,array<string,mixed>>                                  $rooms      The filtered rooms, keyed by id.
	 * @param array<string,array{weekday:int,opens:int,closes:int,minutes:int}> $days       The teaching days, keyed by `Y-m-d`.
	 * @param array<string,int>                                                  $groupSizes Group size per cohort id.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly array $rooms,
		private readonly array $days,
		private readonly array $groupSizes,
	) {
	}//end __construct()

	/**
	 * Count one lesson.
	 *
	 * @param array<string,mixed> $session The lesson.
	 * @param string              $roomId  Its learniq room, or ''.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function add(array $session, string $roomId): void {
		$span = $this->countableSpan(session: $session);
		if ($span === null) {
			return;
		}

		if ($roomId === '') {
			$this->unassigned[] = $this->unassignedRow(session: $session);
			return;
		}

		if (isset($this->rooms[$roomId]) === false) {
			return;
		}

		$this->book(roomId: $roomId, span: $span);
		$this->countFill(roomId: $roomId, groupSize: ($this->groupSizes[(string)($session['cohortId'] ?? '')] ?? null));
	}//end add()

	/**
	 * The report figures: teaching days, open hours per room, the rooms, the grid and the lessons without a room.
	 *
	 * @param int $unassignedLimit How many lessons without a room to list.
	 *
	 * @return array<string,mixed> `teachingDays`, `openHoursPerRoom`, `rooms`, `grid`, `unassigned`.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function report(int $unassignedLimit): array {
		$openMinutes = array_sum(array_column($this->days, 'minutes'));

		return [
			'teachingDays' => count($this->days),
			'openHoursPerRoom' => round($openMinutes / 60, 1),
			'rooms' => $this->roomRows(openMinutes: $openMinutes),
			'grid' => $this->grid(),
			'unassigned' => $this->unassigned(limit: $unassignedLimit),
		];
	}//end report()

	/**
	 * One row per room.
	 *
	 * @param int $openMinutes Minutes open in the window, the same for every room.
	 *
	 * @return array<int,array<string,mixed>> Sorted from most to least used.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function roomRows(int $openMinutes): array {
		$rows = [];
		foreach ($this->rooms as $id => $room) {
			$used = ($this->minutes[$id] ?? 0);
			$rows[] = [
				'roomId' => $id,
				'name' => (string)($room['name'] ?? ''),
				'code' => $room['code'] ?? null,
				'kind' => $room['kind'] ?? null,
				'buildingCode' => $room['buildingCode'] ?? null,
				'capacity' => $room['capacity'] ?? null,
				'hoursInUse' => round($used / 60, 1),
				'hoursOpen' => round($openMinutes / 60, 1),
				'occupancy' => $this->occupancy(used: $used, openMinutes: $openMinutes),
				'fill' => $this->averageFill(roomId: (string)$id),
			];
		}//end foreach

		usort($rows, static fn (array $a, array $b): int => (($b['occupancy'] ?? 0) <=> ($a['occupancy'] ?? 0)));
		return $rows;
	}//end roomRows()

	/**
	 * The share of the filtered rooms in use per weekday and clock hour.
	 *
	 * @return array<int,array{weekday:int,hours:array<int,array{hour:int,share:float}>}>
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	public function grid(): array {
		$roomCount = count($this->rooms);
		$out = [];
		foreach ($this->weekdayWindows() as $weekday => $window) {
			$hours = [];
			for ($hour = intdiv($window['opens'], 60); ($hour * 60) < $window['closes']; $hour++) {
				$capacity = 60 * $roomCount * $window['count'];
				$share = 0.0;
				if ($capacity > 0) {
					$share = round(min(1, ($this->grid[$weekday][$hour] ?? 0) / $capacity), 3);
				}

				$hours[] = ['hour' => $hour, 'share' => $share];
			}

			$out[] = ['weekday' => $weekday, 'hours' => $hours];
		}

		return $out;
	}//end grid()

	/**
	 * The lessons without a room: their number and the first few.
	 *
	 * @param int $limit How many to list.
	 *
	 * @return array{count:int,sessions:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-lessons-without-a-room-are-counted-not-hidden
	 */
	public function unassigned(int $limit): array {
		return ['count' => count($this->unassigned), 'sessions' => array_slice($this->unassigned, 0, $limit)];
	}//end unassigned()

	/**
	 * The lessons without a room row of a lesson.
	 *
	 * @param array<string,mixed> $session The lesson.
	 *
	 * @return array{id:string,title:string,startsAt:string,source:string}
	 */
	private function unassignedRow(array $session): array {
		return [
			'id' => (string)($session['id'] ?? ''),
			'title' => (string)($session['title'] ?? ''),
			'startsAt' => (string)($session['startsAt'] ?? ''),
			'source' => (string)($session['source'] ?? 'learniq'),
		];
	}//end unassignedRow()

	/**
	 * Add a lesson's minutes to its room and to the weekday and clock hours it covers.
	 *
	 * @param string                 $roomId The room.
	 * @param array{0:int,1:int,2:int} $span   Weekday, start and end in minutes of the day.
	 *
	 * @return void
	 */
	private function book(string $roomId, array $span): void {
		[$weekday, $start, $end] = $span;
		$this->minutes[$roomId] = ($this->minutes[$roomId] ?? 0) + ($end - $start);
		for ($hour = intdiv($start, 60); ($hour * 60) < $end; $hour++) {
			$overlap = min($end, ($hour + 1) * 60) - max($start, $hour * 60);
			$this->grid[$weekday][$hour] = ($this->grid[$weekday][$hour] ?? 0) + $overlap;
		}
	}//end book()

	/**
	 * The share of the open minutes a room was in use, or null when the window has none.
	 *
	 * @param int $used        Minutes in use.
	 * @param int $openMinutes Minutes open.
	 *
	 * @return float|null
	 */
	private function occupancy(int $used, int $openMinutes): ?float {
		if ($openMinutes > 0) {
			return round($used / $openMinutes, 3);
		}

		return null;
	}//end occupancy()

	/**
	 * The average fill of a room's lessons, or null when none had a known group size.
	 *
	 * @param string $roomId The room.
	 *
	 * @return float|null
	 */
	private function averageFill(string $roomId): ?float {
		if (isset($this->fill[$roomId]) === true && $this->fill[$roomId]['count'] > 0) {
			return round($this->fill[$roomId]['sum'] / $this->fill[$roomId]['count'], 3);
		}

		return null;
	}//end averageFill()

	/**
	 * The open span of a lesson that was not cancelled; null otherwise.
	 *
	 * @param array<string,mixed> $session The lesson.
	 *
	 * @return array{0:int,1:int,2:int}|null
	 */
	private function countableSpan(array $session): ?array {
		if (($session['lifecycle'] ?? '') === 'cancelled') {
			return null;
		}

		return $this->openSpan(session: $session);
	}//end countableSpan()

	/**
	 * The weekday, start and end (minutes of the day) of a lesson, clipped to
	 * the opening hours of its day; null when it falls outside them.
	 *
	 * @param array<string,mixed> $session The lesson.
	 *
	 * @return array{0:int,1:int,2:int}|null
	 */
	private function openSpan(array $session): ?array {
		$start = $this->instant(text: (string)($session['startsAt'] ?? ''));
		$end = $this->instant(text: (string)($session['endsAt'] ?? ''));
		if ($start === null || $end === null) {
			return null;
		}

		return $this->clip(start: $start, end: $end);
	}//end openSpan()

	/**
	 * A lesson time, or null when it is empty or not a time.
	 *
	 * @param string $text The time as written.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function instant(string $text): ?DateTimeImmutable {
		if ($text === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($text);
		} catch (Throwable $exception) {
			return null;
		}
	}//end instant()

	/**
	 * A lesson clipped to the opening hours of its teaching day; null when nothing is left.
	 *
	 * @param DateTimeImmutable $start Lesson start.
	 * @param DateTimeImmutable $end   Lesson end.
	 *
	 * @return array{0:int,1:int,2:int}|null
	 */
	private function clip(DateTimeImmutable $start, DateTimeImmutable $end): ?array {
		$day = $this->dayOf(start: $start, end: $end);
		if ($day === null) {
			return null;
		}

		$from = max($day['opens'], ((int)$start->format('G') * 60) + (int)$start->format('i'));
		$to = min($day['closes'], ((int)$end->format('G') * 60) + (int)$end->format('i'));
		if ($to <= $from) {
			return null;
		}

		return [$day['weekday'], $from, $to];
	}//end clip()

	/**
	 * The teaching day a lesson falls on, or null when it is no teaching day,
	 * ends before it starts, or runs into the next day.
	 *
	 * @param DateTimeImmutable $start Lesson start.
	 * @param DateTimeImmutable $end   Lesson end.
	 *
	 * @return array{weekday:int,opens:int,closes:int,minutes:int}|null
	 */
	private function dayOf(DateTimeImmutable $start, DateTimeImmutable $end): ?array {
		$day = ($this->days[$start->format('Y-m-d')] ?? null);
		if ($end <= $start || $end->format('Y-m-d') !== $start->format('Y-m-d')) {
			return null;
		}

		return $day;
	}//end dayOf()

	/**
	 * Add one lesson's fill to a room.
	 *
	 * @param string   $roomId    The room.
	 * @param int|null $groupSize The group size, or null when unknown.
	 *
	 * @return void
	 */
	private function countFill(string $roomId, ?int $groupSize): void {
		$capacity = (int)($this->rooms[$roomId]['capacity'] ?? 0);
		if ($capacity <= 0 || $groupSize === null) {
			return;
		}

		$this->fill[$roomId] ??= ['sum' => 0.0, 'count' => 0];
		$this->fill[$roomId]['sum'] += ($groupSize / $capacity);
		$this->fill[$roomId]['count']++;
	}//end countFill()

	/**
	 * Per weekday that has teaching days: its earliest opening, latest closing
	 * and how many teaching days it has in the window.
	 *
	 * @return array<int,array{opens:int,closes:int,count:int}>
	 */
	private function weekdayWindows(): array {
		$out = [];
		foreach ($this->days as $day) {
			$weekday = $day['weekday'];
			$out[$weekday] ??= ['opens' => $day['opens'], 'closes' => $day['closes'], 'count' => 0];
			$out[$weekday]['opens'] = min($out[$weekday]['opens'], $day['opens']);
			$out[$weekday]['closes'] = max($out[$weekday]['closes'], $day['closes']);
			$out[$weekday]['count']++;
		}

		ksort($out);
		return $out;
	}//end weekdayWindows()
}//end class
