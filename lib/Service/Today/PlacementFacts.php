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
		$searching = [];
		foreach ($placements as $placement) {
			$own = ($placement['schoolCoachId'] ?? null) === $context->uid() && ($placement['phase'] ?? '') === 'searching';
			if ($own === true && self::runsOn(placement: $placement, today: $context->today()) === true) {
				$searching[] = $placement;
			}
		}

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
		$mine = array_map(
			static fn (array $placement): string => self::idOf(row: $placement),
			array_filter(($bpv['placements'] ?? []), static fn (array $placement): bool => ($placement['schoolCoachId'] ?? null) === $context->uid())
		);
		$since = $context->now->modify('-' . self::HOURS_WAIT_DAYS . ' days');
		$waiting = [];
		foreach (($bpv['weeks'] ?? []) as $week) {
			$submitted = (self::moment(value: ($week['submittedAt'] ?? null)) ?? $context->now);
			$ownWeek = in_array((string)($week['bpvPlacementId'] ?? ''), $mine, true) && ($week['lifecycle'] ?? '') === 'submitted';
			if ($ownWeek === true && $submitted <= $since) {
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

	/**
	 * The coach's submitted hour weeks per training company, the longest waiting company first.
	 *
	 * @param array<string, list<array<string, mixed>>> $bpv     `placements` (the coach's) and `weeks` (submitted hour weeks).
	 * @param TodayContext                              $context The coach and now.
	 *
	 * @return list<array<string, mixed>> Per company: company, students, weeks, hours, oldestSubmittedAt, oldestWeek.
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-the-bpv-begeleiders-today-gathers-her-students
	 */
	public function hoursPerCompany(array $bpv, TodayContext $context): array {
		$companyOf = [];
		foreach (($bpv['placements'] ?? []) as $placement) {
			if (($placement['schoolCoachId'] ?? null) === $context->uid()) {
				$companyOf[self::idOf(row: $placement)] = trim((string)($placement['trainingCompanyName'] ?? ''));
			}
		}

		$companies = [];
		foreach (($bpv['weeks'] ?? []) as $week) {
			$placementId = (string)($week['bpvPlacementId'] ?? '');
			if (($week['lifecycle'] ?? '') !== 'submitted' || array_key_exists($placementId, $companyOf) === false) {
				continue;
			}

			$name = $companyOf[$placementId];
			$row = ($companies[$name] ?? ['company' => $name, 'placements' => [], 'weeks' => 0, 'hours' => 0.0, 'oldestSubmittedAt' => '', 'oldestWeek' => '']);
			$row['placements'][$placementId] = true;
			$row['weeks']++;
			$row['hours'] += (float)($week['hoursSubmitted'] ?? 0);
			$submitted = (string)($week['submittedAt'] ?? '');
			if ($row['oldestSubmittedAt'] === '' || ($submitted !== '' && strcmp($submitted, $row['oldestSubmittedAt']) < 0)) {
				$row['oldestSubmittedAt'] = $submitted;
				$row['oldestWeek'] = (string)($week['isoWeek'] ?? '');
			}

			$companies[$name] = $row;
		}

		$rows = array_map(
			static fn (array $row): array => [
				'company' => $row['company'],
				'students' => count($row['placements']),
				'weeks' => $row['weeks'],
				'hours' => $row['hours'],
				'oldestSubmittedAt' => $row['oldestSubmittedAt'],
				'oldestWeek' => $row['oldestWeek'],
			],
			array_values($companies)
		);
		usort($rows, static fn (array $one, array $two): int => strcmp($one['oldestSubmittedAt'], $two['oldestSubmittedAt']));

		return $rows;
	}//end hoursPerCompany()

	/**
	 * Whether a placement's BPV period has started and not ended on a day.
	 *
	 * @param array<string, mixed> $placement The placement.
	 * @param string               $today     The day, `Y-m-d`.
	 *
	 * @return bool
	 */
	private static function runsOn(array $placement, string $today): bool {
		$from = substr((string)($placement['periodFrom'] ?? ''), 0, 10);
		$until = substr((string)($placement['periodTo'] ?? ''), 0, 10);

		return $from !== '' && $from <= $today && ($until === '' || $until >= $today);
	}//end runsOn()
}//end class
