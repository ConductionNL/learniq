<?php

/**
 * Learniq Placement Facts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the BPV-begeleider's First today rules count
 * (bpv-coach-today-and-placement-board T5): a student of hers without a
 * company while the BPV period runs, a company visit with no date proposed
 * or agreed, and hours that wait for a company longer than seven days. Every
 * method takes rows already read, so each rule and its empty case is a unit
 * test.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Today
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/tasks.md#T5
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Today;

/**
 * The facts of the BPV-begeleider's First today rules.
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/tasks.md#T5
 */
final class PlacementFacts {
	use RowValues;

	/**
	 * How long submitted hours may wait for the company before they ask the coach, in days.
	 *
	 * @var int
	 */
	public const HOURS_WAIT_DAYS = 7;

	/**
	 * Students of the coach still searching for a company while their BPV period runs.
	 *
	 * @param list<array<string, mixed>> $placements The coach's placements.
	 * @param TodayContext               $context    The coach and now.
	 *
	 * @return array<string, mixed> count, learnerRef and lateLabel (of the first).
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/tasks.md#T5
	 */
	public function placementWithoutCompany(array $placements, TodayContext $context): array {
		$today = $context->today();
		$searching = array_values(
			array_filter(
				$placements,
				static fn (array $placement): bool => ($placement['schoolCoachId'] ?? null) === $context->uid()
					&& ($placement['phase'] ?? '') === 'searching'
					&& substr((string)($placement['periodFrom'] ?? ''), 0, 10) <= $today
					&& ($placement['periodFrom'] ?? '') !== ''
					&& (($placement['periodTo'] ?? '') === '' || substr((string)$placement['periodTo'], 0, 10) >= $today)
			)
		);

		return [
			'count' => count($searching),
			'learnerRef' => (string)($searching[0]['learnerRef'] ?? ''),
			'lateLabel' => (string)($searching[0]['lateLabel'] ?? ''),
		];
	}//end placementWithoutCompany()

	/**
	 * The coach's company visits with no date proposed or agreed.
	 *
	 * @param list<array<string, mixed>> $visits The visits.
	 * @param string                     $uid    The coach.
	 *
	 * @return array<string, mixed> count, visitId and learnerRef (of the first).
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/tasks.md#T5
	 */
	public function visitWithoutDate(array $visits, string $uid): array {
		$open = array_values(
			array_filter(
				$visits,
				static fn (array $visit): bool => ($visit['schoolCoachId'] ?? null) === $uid && ($visit['lifecycle'] ?? '') === 'unplanned'
			)
		);

		return [
			'count' => count($open),
			'visitId' => self::idOf(row: (array)($open[0] ?? [])),
			'learnerRef' => (string)($open[0]['learnerRef'] ?? ''),
		];
	}//end visitWithoutDate()

	/**
	 * Hour weeks of the coach's students that wait for the company longer than seven days.
	 *
	 * @param array<string, list<array<string, mixed>>> $bpv     `placements` (the coach's) and `weeks` (submitted hour weeks).
	 * @param TodayContext                              $context The coach and now.
	 *
	 * @return array<string, mixed> count (weeks), hours (waiting over them), isoWeek (the longest waiting).
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/tasks.md#T5
	 */
	public function hoursWaiting(array $bpv, TodayContext $context): array {
		$mine = [];
		foreach (($bpv['placements'] ?? []) as $placement) {
			if (($placement['schoolCoachId'] ?? null) === $context->uid()) {
				$mine[] = self::idOf(row: $placement);
			}
		}

		$since = $context->now->modify('-' . self::HOURS_WAIT_DAYS . ' days');
		$waiting = [];
		foreach (($bpv['weeks'] ?? []) as $week) {
			$submitted = self::moment(value: ($week['submittedAt'] ?? null));
			$ownWeek = in_array((string)($week['bpvPlacementId'] ?? ''), $mine, true);
			if ($ownWeek === true && ($week['lifecycle'] ?? '') === 'submitted' && $submitted !== null && $submitted <= $since) {
				$waiting[] = $week;
			}
		}

		usort($waiting, static fn (array $one, array $two): int => strcmp((string)($one['submittedAt'] ?? ''), (string)($two['submittedAt'] ?? '')));

		return [
			'count' => count($waiting),
			'hours' => array_sum(array_map(static fn (array $week): float => (float)($week['hoursSubmitted'] ?? 0), $waiting)),
			'isoWeek' => (string)($waiting[0]['isoWeek'] ?? ''),
		];
	}//end hoursWaiting()
}//end class
