<?php

/**
 * Learniq Standby Calendar
 *
 * A teacher's standby slots as dated blocks in a window, for their personal
 * timetable (timetabling-standby-slots): a weekly slot on every matching
 * weekday inside its validity, a one-off slot on its date.
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
 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\StandbyCalendarInterface;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Expands a teacher's standby slots into the blocks of a window.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
 */
class StandbyCalendar implements StandbyCalendarInterface {

	private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the slots (caller's RBAC; every signed-in user reads standby).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The teacher's standby blocks in the window.
	 *
	 * @param string $uid  The teacher.
	 * @param string $from Window start, ISO 8601.
	 * @param string $to   Window end (exclusive), ISO 8601.
	 *
	 * @return array<int,array{slotId:string,date:string,startsAt:string,endsAt:string,vestigingId:string|null}>
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
	 */
	public function blocksFor(string $uid, string $from, string $to): array {
		try {
			$start = (new DateTimeImmutable($from))->setTime(0, 0);
			$end = new DateTimeImmutable($to);
			$rows = $this->objectService->findAll(['filters' => ['register' => 'learniq', 'schema' => 'standby-slot', 'teacherId' => $uid]]);
		} catch (Throwable $exception) {
			return [];
		}

		$blocks = [];
		foreach ($this->teacherSlots(rows: $rows, uid: $uid) as $slot) {
			array_push($blocks, ...$this->slotBlocks(slot: $slot, days: new DatePeriod($start, new DateInterval('P1D'), $end)));
		}

		usort($blocks, static fn (array $a, array $b): int => strcmp($a['date'] . $a['startsAt'], $b['date'] . $b['startsAt']));
		return $blocks;
	}//end blocksFor()

	/**
	 * The found slots as arrays, only the teacher's own.
	 *
	 * @param array<int,mixed> $rows What findAll() answered.
	 * @param string           $uid  The teacher.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function teacherSlots(array $rows, string $uid): array {
		$slots = [];
		foreach ($rows as $row) {
			$slot = $this->asSlot(row: $row);
			if (is_array($slot) === true && (string)($slot['teacherId'] ?? '') === $uid) {
				$slots[] = $slot;
			}
		}

		return $slots;
	}//end teacherSlots()

	/**
	 * An entity row as its array, anything else as it is.
	 *
	 * @param mixed $row A found row.
	 *
	 * @return mixed
	 */
	private function asSlot(mixed $row): mixed {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return $row;
	}//end asSlot()

	/**
	 * One slot's blocks on the days of the window it applies on.
	 *
	 * @param array<string,mixed> $slot The slot.
	 * @param DatePeriod          $days The window's days, from its first midnight.
	 *
	 * @return array<int,array{slotId:string,date:string,startsAt:string,endsAt:string,vestigingId:string|null}>
	 */
	private function slotBlocks(array $slot, DatePeriod $days): array {
		$blocks = [];
		foreach ($days as $day) {
			$date = $day->format('Y-m-d');
			if ($this->onDay(slot: $slot, day: ['date' => $date, 'weekday' => self::WEEKDAYS[((int)$day->format('N')) - 1]]) === true) {
				$blocks[] = $this->block(slot: $slot, date: $date);
			}
		}

		return $blocks;
	}//end slotBlocks()

	/**
	 * A slot's block on one date.
	 *
	 * @param array<string,mixed> $slot The slot.
	 * @param string              $date `Y-m-d`.
	 *
	 * @return array{slotId:string,date:string,startsAt:string,endsAt:string,vestigingId:string|null}
	 */
	private function block(array $slot, string $date): array {
		return [
			'slotId' => (string)($slot['id'] ?? ($slot['uuid'] ?? '')),
			'date' => $date,
			'startsAt' => (string)($slot['startsAt'] ?? ''),
			'endsAt' => (string)($slot['endsAt'] ?? ''),
			'vestigingId' => $slot['vestigingId'] ?? null,
		];
	}//end block()

	/**
	 * Whether a standby slot covers a lesson: on the lesson's day, valid then,
	 * overlapping its time, and at the same location when both name one.
	 *
	 * @param array<string,mixed>  $slot   The slot.
	 * @param array<string,string> $lesson The lesson's `date`, `weekday`, `from`, `to` (`HH:MM`) and `location` id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
	 */
	public function covers(array $slot, array $lesson): bool {
		$overlaps = (string)($slot['startsAt'] ?? '') < $lesson['to'] && $lesson['from'] < (string)($slot['endsAt'] ?? '');
		$sameLocation = $this->sameLocation(slot: $slot, lesson: $lesson);

		return $overlaps === true && $sameLocation === true && $this->onDay(slot: $slot, day: $lesson) === true;
	}//end covers()

	/**
	 * Whether a slot and a lesson are at the same location, or either names none.
	 *
	 * @param array<string,mixed>  $slot   The slot.
	 * @param array<string,string> $lesson The lesson, with its `location` id.
	 *
	 * @return bool
	 */
	private function sameLocation(array $slot, array $lesson): bool {
		$slotLocation = (string)($slot['vestigingId'] ?? '');

		return $slotLocation === '' || $lesson['location'] === '' || $slotLocation === $lesson['location'];
	}//end sameLocation()

	/**
	 * Whether a slot applies on a date.
	 *
	 * @param array<string,mixed>  $slot The slot.
	 * @param array<string,string> $day  The `date` (`Y-m-d`) and its `weekday`.
	 *
	 * @return bool
	 */
	private function onDay(array $slot, array $day): bool {
		$date = $day['date'];
		if ((string)($slot['validFrom'] ?? '') > $date || $date > (string)($slot['validUntil'] ?? '')) {
			return false;
		}

		$oneOff = (string)($slot['date'] ?? '');
		if ($oneOff !== '') {
			return $oneOff === $date;
		}

		return (string)($slot['weekday'] ?? '') === $day['weekday'];
	}//end onDay()
}//end class
