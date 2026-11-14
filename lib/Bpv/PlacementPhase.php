<?php

/**
 * Learniq BPV placement phase
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Works out where a BPV placement stands from the facts it already holds:
 * its company, its lifecycle (the POK), its period, its assessment visits
 * and its waiting hours. The placement board groups on the phase and its
 * card shows the late line in words.
 *
 * @category Bpv
 * @package  OCA\Learniq\Bpv
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
 */

declare(strict_types=1);

namespace OCA\Learniq\Bpv;

use DateTimeImmutable;

/**
 * The phase, its due date and its late line, from a placement and its visits.
 *
 * The order of the checks is the order of the board: a finished or ended
 * placement is done, one without a company is searching, one whose POK is not
 * active is in agreement, an ended period is rounding off until the final
 * assessment is done and no hours wait, an assessment due within 14 days (or
 * overdue) puts it in assessment, and everything else runs.
 *
 * @category Bpv
 * @package  OCA\Learniq\Bpv
 *
 * @author  Conduction Development Team <dev@conductio.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link    https://conduction.nl
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
 */
final class PlacementPhase {

	/**
	 * The phases, in board order.
	 *
	 * @var array<int, string>
	 */
	public const PHASES = ['searching', 'agreement', 'running', 'assessment', 'rounding-off', 'done'];

	/**
	 * The visit kinds that are an assessment.
	 *
	 * @var array<int, string>
	 */
	public const ASSESSMENT_KINDS = ['tussentijds-gesprek', 'eindgesprek'];

	/**
	 * The final assessment.
	 */
	public const FINAL_KIND = 'eindgesprek';

	/**
	 * The days ahead an assessment visit starts the assessment phase.
	 */
	public const ASSESSMENT_WINDOW_DAYS = 14;

	/**
	 * The visit states still to happen.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_VISITS = ['unplanned', 'proposed', 'planned'];

	/**
	 * Dutch month names, for "te laat sinds 1 oktober".
	 *
	 * @var array<int, string>
	 */
	private const MONTHS = [
		1 => 'januari',
		2 => 'februari',
		3 => 'maart',
		4 => 'april',
		5 => 'mei',
		6 => 'juni',
		7 => 'juli',
		8 => 'augustus',
		9 => 'september',
		10 => 'oktober',
		11 => 'november',
		12 => 'december',
	];

	/**
	 * The phase, the date it is due by, and the late line in words ('' when not late).
	 *
	 * @param array<string, mixed>             $placement The placement.
	 * @param array<int, array<string, mixed>> $visits    Its visits.
	 * @param DateTimeImmutable                $today     Today.
	 *
	 * @return array{phase: string, phaseDueAt: ?string, lateLabel: string}
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
	 */
	public function derive(array $placement, array $visits, DateTimeImmutable $today): array {
		[$phase, $due] = $this->phaseAndDue(placement: $placement, visits: $visits, today: $today);

		return ['phase' => $phase, 'phaseDueAt' => $due, 'lateLabel' => self::lateLabel(due: $due, today: $today)];

	}//end derive()

	/**
	 * A key that changes whenever a fact the phase is derived from changes.
	 *
	 * A manual move on the board lasts until this key changes.
	 *
	 * @param array<string, mixed>             $placement The placement.
	 * @param array<int, array<string, mixed>> $visits    Its visits.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
	 */
	public function factsKey(array $placement, array $visits): string {
		$facts = [
			self::company(placement: $placement),
			(string) ($placement['lifecycle'] ?? ''),
			(string) ($placement['periodFrom'] ?? ''),
			(string) ($placement['periodTo'] ?? ''),
			(float) ($placement['hoursWaitingTotal'] ?? 0),
		];
		foreach ($visits as $visit) {
			$facts[] = [
				(string) ($visit['visitKind'] ?? ''),
				(string) ($visit['lifecycle'] ?? ''),
				(string) ($visit['plannedAt'] ?? ''),
			];
		}

		return hash('sha256', (string) json_encode($facts));

	}//end factsKey()

	/**
	 * The phase and its due date.
	 *
	 * @param array<string, mixed>             $placement The placement.
	 * @param array<int, array<string, mixed>> $visits    Its visits.
	 * @param DateTimeImmutable                $today     Today.
	 *
	 * @return array{0: string, 1: ?string}
	 */
	private function phaseAndDue(array $placement, array $visits, DateTimeImmutable $today): array {
		$lifecycle = (string) ($placement['lifecycle'] ?? '');
		$from      = self::date(value: ($placement['periodFrom'] ?? null));
		$until     = self::date(value: ($placement['periodTo'] ?? null));

		if (in_array($lifecycle, ['completed', 'terminated'], true) === true) {
			return ['done', null];
		}

		if (self::company(placement: $placement) === '') {
			return ['searching', $from?->format('Y-m-d')];
		}

		if ($lifecycle !== 'active') {
			return ['agreement', $from?->format('Y-m-d')];
		}

		if ($until !== null && $until < $today) {
			return $this->afterThePeriod(placement: $placement, visits: $visits, until: $until);
		}

		$assessment = self::dueAssessment(visits: $visits, today: $today);
		if ($assessment !== null) {
			return ['assessment', $assessment->format('Y-m-d')];
		}

		return ['running', null];

	}//end phaseAndDue()

	/**
	 * Done when the final assessment is done and no hours wait, else rounding off.
	 *
	 * @param array<string, mixed>             $placement The placement.
	 * @param array<int, array<string, mixed>> $visits    Its visits.
	 * @param DateTimeImmutable                $until     The end of the period.
	 *
	 * @return array{0: string, 1: ?string}
	 */
	private function afterThePeriod(array $placement, array $visits, DateTimeImmutable $until): array {
		$finalDone = false;
		foreach ($visits as $visit) {
			if (($visit['visitKind'] ?? '') === self::FINAL_KIND && ($visit['lifecycle'] ?? '') === 'done') {
				$finalDone = true;
			}
		}

		if ($finalDone === true && (float) ($placement['hoursWaitingTotal'] ?? 0) <= 0.0) {
			return ['done', null];
		}

		return ['rounding-off', $until->format('Y-m-d')];

	}//end afterThePeriod()

	/**
	 * The earliest open assessment visit planned within the window or already past.
	 *
	 * @param array<int, array<string, mixed>> $visits The visits.
	 * @param DateTimeImmutable                $today  Today.
	 *
	 * @return DateTimeImmutable|null Its date.
	 */
	private static function dueAssessment(array $visits, DateTimeImmutable $today): ?DateTimeImmutable {
		$horizon  = $today->modify('+'.self::ASSESSMENT_WINDOW_DAYS.' days');
		$earliest = null;
		foreach ($visits as $visit) {
			if (in_array(($visit['visitKind'] ?? ''), self::ASSESSMENT_KINDS, true) === false
				|| in_array(($visit['lifecycle'] ?? ''), self::OPEN_VISITS, true) === false
			) {
				continue;
			}

			$at = self::date(value: ($visit['plannedAt'] ?? null));
			if ($at !== null && $at <= $horizon && ($earliest === null || $at < $earliest)) {
				$earliest = $at;
			}
		}

		return $earliest;

	}//end dueAssessment()

	/**
	 * The late line: "2 weken te laat" from a week on, "te laat sinds 1 oktober" before that.
	 *
	 * @param string|null       $due   The due date (Y-m-d).
	 * @param DateTimeImmutable $today Today.
	 *
	 * @return string The line, '' when not late.
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
	 */
	public static function lateLabel(?string $due, DateTimeImmutable $today): string {
		$dueAt = self::date(value: $due);
		if ($dueAt === null || $dueAt >= $today) {
			return '';
		}

		$days = (int) $dueAt->diff($today)->days;
		if ($days >= 14) {
			return intdiv($days, 7).' weken te laat';
		}

		if ($days >= 7) {
			return '1 week te laat';
		}

		return 'te laat sinds '.(int) $dueAt->format('j').' '.self::MONTHS[(int) $dueAt->format('n')];

	}//end lateLabel()

	/**
	 * The company a placement names, '' for none.
	 *
	 * @param array<string, mixed> $placement The placement.
	 *
	 * @return string The company name or KvK number.
	 */
	private static function company(array $placement): string {
		$name = trim((string) ($placement['trainingCompanyName'] ?? ''));
		if ($name !== '') {
			return $name;
		}

		return trim((string) ($placement['trainingCompanyKvkNumber'] ?? ''));

	}//end company()

	/**
	 * A date (Y-m-d, optionally with a time) at midnight, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null The day.
	 */
	private static function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
			return null;
		}

		$day = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
		if ($day === false) {
			return null;
		}

		return $day;

	}//end date()
}//end class
