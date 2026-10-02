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

		$slots = [];

		foreach ($blocks as $block) {
			$startRaw = $block['startsAt'] ?? null;
			$endRaw = $block['endsAt'] ?? null;

			if ($startRaw === null || $endRaw === null) {
				continue;
			}

			try {
				$cursor = new DateTimeImmutable((string)$startRaw, new DateTimeZone('UTC'));
				$blockEnds = new DateTimeImmutable((string)$endRaw, new DateTimeZone('UTC'));
			} catch (\Exception) {
				continue;
			}

			while (true) {
				$slotEnd = $cursor->modify('+' . $slotDurationMinutes . ' minutes');
				if ($slotEnd > $blockEnds) {
					break;
				}

				$slots[] = [
					'startsAt' => $cursor->format(DATE_ATOM),
					'endsAt' => $slotEnd->format(DATE_ATOM),
				];

				$cursor = $slotEnd->modify('+' . $bufferMinutes . ' minutes');
			}
		}//end foreach

		return $slots;
	}//end slice()

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
		$start = strtotime($candidate['startsAt']);
		$end = strtotime($candidate['endsAt']);
		foreach ($intervals as $interval) {
			$otherStart = strtotime($interval['startsAt']);
			$otherEnd = strtotime($interval['endsAt']);
			if ($start === false || $end === false || $otherStart === false || $otherEnd === false) {
				continue;
			}

			if ($start < $otherEnd && $otherStart < $end) {
				return true;
			}
		}

		return false;
	}//end overlaps()
}//end class
