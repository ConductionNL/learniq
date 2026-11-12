<?php

/**
 * Learniq conference slot slicer
 *
 * Cuts a teacher's declared free blocks into conversation slots of a round's
 * length with its buffer between them. Shared by the preference flow
 * (ConferenceScheduleGenerator, which plans signups into the slots) and the
 * direct flow (ConferenceFreeSlotGenerator, which offers them as free times).
 * A pure function: no state, no collaborators, deterministic.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Slices availability blocks into slots.
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */
class ConferenceSlotSlicer {

	/**
	 * Candidate `{startsAt, endsAt}` slots, slotDurationMinutes long with a
	 * bufferMinutes gap between consecutive slots, in block order.
	 *
	 * @param array<int,array<string,mixed>> $blocks Free blocks: [{startsAt, endsAt}, ...].
	 * @param int $slotDurationMinutes Length of one slot in minutes.
	 * @param int $bufferMinutes Gap between consecutive slots in minutes.
	 *
	 * @return array<int,array{startsAt:string,endsAt:string}> Candidate slots, in chronological order.
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#requirement-schedule-generation-is-a-declared-greedy-solver-triggered-by-a-round-transition-not-a-php-crud-controller
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function slice(array $blocks, int $slotDurationMinutes, int $bufferMinutes): array {
		if ($slotDurationMinutes <= 0) {
			return [];
		}

		$pattern = new ConferenceSlotPattern(durationMinutes: $slotDurationMinutes, bufferMinutes: $bufferMinutes);
		$slots   = [];
		foreach ($blocks as $block) {
			$slots = array_merge($slots, $this->blockSlots(block: $block, pattern: $pattern));
		}

		return $slots;
	}//end slice()

	/**
	 * The slots of one free block, or none when the block is incomplete or unreadable.
	 *
	 * @param array<string,mixed>   $block   One free block: {startsAt, endsAt}.
	 * @param ConferenceSlotPattern $pattern The slot length and the buffer.
	 *
	 * @return array<int,array{startsAt:string,endsAt:string}> The block's slots.
	 */
	private function blockSlots(array $block, ConferenceSlotPattern $pattern): array {
		$range = $this->blockRange(block: $block);
		if ($range === null) {
			return [];
		}

		return $this->slotsWithin(range: $range, pattern: $pattern);
	}//end blockSlots()

	/**
	 * A block's start and end as UTC instants, or null when either is missing or unreadable.
	 *
	 * @param array<string,mixed> $block One free block: {startsAt, endsAt}.
	 *
	 * @return array{0:DateTimeImmutable,1:DateTimeImmutable}|null Start and end.
	 */
	private function blockRange(array $block): ?array {
		$startRaw = $block['startsAt'] ?? null;
		$endRaw   = $block['endsAt'] ?? null;
		if ($startRaw === null || $endRaw === null) {
			return null;
		}

		try {
			return [
				new DateTimeImmutable((string)$startRaw, new DateTimeZone('UTC')),
				new DateTimeImmutable((string)$endRaw, new DateTimeZone('UTC')),
			];
		} catch (\Exception) {
			return null;
		}
	}//end blockRange()

	/**
	 * The slots that fit between a block's start and end, a buffer apart.
	 *
	 * @param array{0:DateTimeImmutable,1:DateTimeImmutable} $range   The block's start and end.
	 * @param ConferenceSlotPattern                          $pattern The slot length and the buffer.
	 *
	 * @return array<int,array{startsAt:string,endsAt:string}> The slots, in order.
	 */
	private function slotsWithin(array $range, ConferenceSlotPattern $pattern): array {
		$cursor = $range[0];
		$slots  = [];
		while (true) {
			$slotEnd = $cursor->modify('+' . $pattern->durationMinutes . ' minutes');
			if ($slotEnd > $range[1]) {
				break;
			}

			$slots[] = $this->slotOf(start: $cursor, end: $slotEnd);
			$cursor  = $slotEnd->modify('+' . $pattern->bufferMinutes . ' minutes');
		}

		return $slots;
	}//end slotsWithin()

	/**
	 * One slot as written on the round.
	 *
	 * @param DateTimeImmutable $start The slot's start.
	 * @param DateTimeImmutable $end   The slot's end.
	 *
	 * @return array{startsAt:string,endsAt:string} The slot.
	 */
	private function slotOf(DateTimeImmutable $start, DateTimeImmutable $end): array {
		return [
			'startsAt' => $start->format(DATE_ATOM),
			'endsAt' => $end->format(DATE_ATOM),
		];
	}//end slotOf()

	/**
	 * Whether a candidate overlaps any interval (half open).
	 *
	 * @param array{startsAt: string, endsAt: string} $candidate The candidate.
	 * @param array<int, array{startsAt: string, endsAt: string}> $intervals The held intervals.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function overlaps(array $candidate, array $intervals): bool {
		$own = $this->bounds(interval: $candidate);
		foreach ($intervals as $interval) {
			if ($this->intersects(own: $own, other: $this->bounds(interval: $interval)) === true) {
				return true;
			}
		}

		return false;
	}//end overlaps()

	/**
	 * An interval's start and end as Unix times, or [] when either is unreadable.
	 *
	 * @param array{startsAt: string, endsAt: string} $interval The interval.
	 *
	 * @return array{0:int,1:int}|array{} Start and end, or [].
	 */
	private function bounds(array $interval): array {
		$start = strtotime($interval['startsAt']);
		$end   = strtotime($interval['endsAt']);
		if ($start === false || $end === false) {
			return [];
		}

		return [$start, $end];
	}//end bounds()

	/**
	 * Whether two readable intervals overlap (half open); an unreadable one overlaps nothing.
	 *
	 * @param array{0:int,1:int}|array{} $own   The candidate's bounds, or [] when unreadable.
	 * @param array{0:int,1:int}|array{} $other The held interval's bounds, or [] when unreadable.
	 *
	 * @return bool
	 */
	private function intersects(array $own, array $other): bool {
		if ($own === [] || $other === []) {
			return false;
		}

		return $own[0] < $other[1] && $other[0] < $own[1];
	}//end intersects()
}//end class
