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
 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Expands a teacher's standby slots into the blocks of a window.
 *
 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
 */
class StandbyCalendar {

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
	 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
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
		foreach ($rows as $row) {
			$slot = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$slot = (array)$row->jsonSerialize();
			}

			if (is_array($slot) === false || (string)($slot['teacherId'] ?? '') !== $uid) {
				continue;
			}

			for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
				$date = $day->format('Y-m-d');
				if ($this->onDay(slot: $slot, date: $date, weekday: self::WEEKDAYS[((int)$day->format('N')) - 1]) === true) {
					$blocks[] = [
						'slotId' => (string)($slot['id'] ?? ($slot['uuid'] ?? '')),
						'date' => $date,
						'startsAt' => (string)($slot['startsAt'] ?? ''),
						'endsAt' => (string)($slot['endsAt'] ?? ''),
						'vestigingId' => $slot['vestigingId'] ?? null,
					];
				}
			}
		}//end foreach

		usort($blocks, static fn (array $a, array $b): int => strcmp($a['date'] . $a['startsAt'], $b['date'] . $b['startsAt']));
		return $blocks;
	}//end blocksFor()

	/**
	 * Whether a standby slot covers a lesson: on the lesson's day, valid then,
	 * overlapping its time, and at the same location when both name one.
	 *
	 * @param array<string,mixed>  $slot   The slot.
	 * @param array<string,string> $lesson The lesson's `date`, `weekday`, `from`, `to` (`HH:MM`) and `location` id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
	 */
	public function covers(array $slot, array $lesson): bool {
		$overlaps = (string)($slot['startsAt'] ?? '') < $lesson['to'] && $lesson['from'] < (string)($slot['endsAt'] ?? '');
		$slotLocation = (string)($slot['vestigingId'] ?? '');
		$sameLocation = $slotLocation === '' || $lesson['location'] === '' || $slotLocation === $lesson['location'];

		return $overlaps === true && $sameLocation === true && $this->onDay(slot: $slot, date: $lesson['date'], weekday: $lesson['weekday']) === true;
	}//end covers()

	/**
	 * Whether a slot applies on a date.
	 *
	 * @param array<string,mixed> $slot    The slot.
	 * @param string              $date    `Y-m-d`.
	 * @param string              $weekday The date's weekday.
	 *
	 * @return bool
	 */
	private function onDay(array $slot, string $date, string $weekday): bool {
		if ((string)($slot['validFrom'] ?? '') > $date || $date > (string)($slot['validUntil'] ?? '')) {
			return false;
		}

		$oneOff = (string)($slot['date'] ?? '');
		if ($oneOff !== '') {
			return $oneOff === $date;
		}

		return (string)($slot['weekday'] ?? '') === $weekday;
	}//end onDay()
}//end class
