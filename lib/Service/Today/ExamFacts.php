<?php

/**
 * Learniq Exam Facts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the exam committee's First today rules count
 * (exam-committee-today-and-keuzedelen T3): an objection past half its term,
 * and sitting results that wait to be established longer than five working
 * days. Every method takes rows already read, so each rule and its empty
 * case is a unit test.
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
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#T3
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Today;

use DateTimeImmutable;

/**
 * The facts of the exam committee's First today rules.
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#T3
 */
final class ExamFacts {
	use RowValues;

	/**
	 * How many working days a result may wait to be established before it asks the committee.
	 *
	 * @var int
	 */
	public const ESTABLISH_WAIT_WORKING_DAYS = 5;

	/**
	 * Objection states the committee has not decided yet.
	 *
	 * @var list<string>
	 */
	private const UNDECIDED = ['submitted', 'examiner-responded'];

	/**
	 * Objections the committee has not decided that are past half their term.
	 *
	 * Half is midway between `submittedAt` and `decideBy`.
	 *
	 * @param list<array<string, mixed>> $objections Exam objections.
	 * @param DateTimeImmutable          $now        Now.
	 *
	 * @return array<string, mixed> count, objectionId and decideBy (of the one due first), responded (whether the examiner answered it).
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#T3
	 */
	public function objectionPastHalf(array $objections, DateTimeImmutable $now): array {
		$due = [];
		foreach ($objections as $objection) {
			if (in_array(($objection['lifecycle'] ?? ''), self::UNDECIDED, true) === false) {
				continue;
			}

			if (self::pastHalf(objection: $objection, now: $now) === true) {
				$due[] = $objection;
			}
		}

		usort($due, static fn (array $one, array $two): int => strcmp((string)$one['decideBy'], (string)$two['decideBy']));

		return [
			'count' => count($due),
			'objectionId' => self::idOf(row: (array)($due[0] ?? [])),
			'decideBy' => ($due[0]['decideBy'] ?? null),
			'responded' => (($due[0]['lifecycle'] ?? '') === 'examiner-responded'),
		];
	}//end objectionPastHalf()

	/**
	 * Published sitting results not established five working days after they were graded.
	 *
	 * @param list<array<string, mixed>> $results Grade entries.
	 * @param DateTimeImmutable          $now     Now.
	 *
	 * @return array<string, mixed> count (results), sittings (the number of sittings they come from).
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#T3
	 */
	public function resultsWaiting(array $results, DateTimeImmutable $now): array {
		$cutoff = self::workingDaysBefore(day: $now, days: self::ESTABLISH_WAIT_WORKING_DAYS);
		$sittings = [];
		$count = 0;
		foreach ($results as $result) {
			$sitting = (string)($result['examSittingId'] ?? '');
			$graded = (self::moment(value: ($result['gradedAt'] ?? null)) ?? $now);
			if ($sitting !== '' && self::unestablished(result: $result) === true && $graded < $cutoff) {
				$count++;
				$sittings[$sitting] = true;
			}
		}

		return ['count' => $count, 'sittings' => count($sittings)];
	}//end resultsWaiting()

	/**
	 * Whether an objection is past midway between its `submittedAt` and its `decideBy`.
	 *
	 * @param array<string, mixed> $objection The objection.
	 * @param DateTimeImmutable    $now       Now.
	 *
	 * @return bool
	 */
	private static function pastHalf(array $objection, DateTimeImmutable $now): bool {
		$from = self::moment(value: ($objection['submittedAt'] ?? null));
		$until = self::moment(value: ($objection['decideBy'] ?? null));
		if ($from === null || $until === null) {
			return false;
		}

		return ($now->getTimestamp() * 2) >= ($from->getTimestamp() + $until->getTimestamp());
	}//end pastHalf()

	/**
	 * Whether a result is published and not established yet.
	 *
	 * @param array<string, mixed> $result The grade entry.
	 *
	 * @return bool
	 */
	private static function unestablished(array $result): bool {
		return ($result['lifecycle'] ?? '') === 'published' && trim((string)($result['establishedAt'] ?? '')) === '';
	}//end unestablished()

	/**
	 * Midnight a number of working days before a day.
	 *
	 * @param DateTimeImmutable $day  The day.
	 * @param int               $days The working days.
	 *
	 * @return DateTimeImmutable
	 */
	private static function workingDaysBefore(DateTimeImmutable $day, int $days): DateTimeImmutable {
		$cutoff = $day->setTime(0, 0);
		while ($days > 0) {
			$cutoff = $cutoff->modify('-1 day');
			$days -= (int)($cutoff->format('N') <= 5);
		}

		return $cutoff;
	}//end workingDaysBefore()
}//end class
