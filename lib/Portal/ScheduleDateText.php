<?php

/**
 * Learniq ScheduleDateText
 *
 * The Dutch day, month and clock wording of the public test schedule:
 * "Toetsweek 1, 9 - 13 november" for a period and "Di 10 nov, 08.30 - 10.10,
 * Wiskunde A" for a sitting.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use DateTimeImmutable;
use Throwable;

/**
 * Formats the days, months and clock times of the public test schedule.
 *
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
 */
class ScheduleDateText {

	/**
	 * Dutch month names, as the rest of the index writes them.
	 */
	private const MONTHS = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

	/**
	 * Dutch weekday abbreviations, Sunday first (PHP `w`).
	 */
	private const WEEKDAYS = ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'];

	/**
	 * "Toetsweek 1, 9 - 13 november", or "30 november - 4 december" across a month.
	 *
	 * @param array<string, mixed> $period The exam period.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
	 */
	public function periodLabel(array $period): string {
		$name  = trim((string)($period['name'] ?? ''));
		$first = substr((string)($period['startsOn'] ?? ''), 0, 10);
		$last  = substr((string)($period['endsOn'] ?? ''), 0, 10);
		if ($first === '') {
			return $name;
		}

		$range = $this->dayAndMonth(day: $first) . ' - ' . $this->dayAndMonth(day: $last);
		if ($last === '' || $last === $first) {
			$range = $this->dayAndMonth(day: $first);
		} else if (substr($first, 0, 7) === substr($last, 0, 7)) {
			$range = ((int)substr($first, 8, 2)) . ' - ' . $this->dayAndMonth(day: $last);
		}

		if ($name === '') {
			return $range;
		}

		return $name . ', ' . $range;
	}//end periodLabel()

	/**
	 * "Di 10 nov, 08.30 - 10.10, Wiskunde A".
	 *
	 * @param string $startsAt The sitting's start, ISO-8601 as stored.
	 * @param string $endsAt   The sitting's end, ISO-8601 as stored.
	 * @param string $subject  The subject name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
	 */
	public function line(string $startsAt, string $endsAt, string $subject): string {
		$day   = substr($startsAt, 0, 10);
		$parts = [$this->weekday(day: $day) . ' ' . ((int)substr($day, 8, 2)) . ' ' . mb_substr((self::MONTHS[((int)substr($day, 5, 2)) - 1] ?? ''), 0, 3)];

		$start = $this->clock(moment: $startsAt);
		$end   = $this->clock(moment: $endsAt);
		if ($start !== '' && $end !== '') {
			$parts[] = $start . ' - ' . $end;
		} else if ($start !== '') {
			$parts[] = $start;
		}

		if ($subject !== '') {
			$parts[] = $subject;
		}

		return implode(', ', $parts);
	}//end line()

	/**
	 * "08.30" from a moment as stored, in the school's own time; '' without a time.
	 *
	 * @param string $moment ISO-8601.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
	 */
	public function clock(string $moment): string {
		if (strlen($moment) < 16 || $moment[10] !== 'T') {
			return '';
		}

		return str_replace(':', '.', substr($moment, 11, 5));
	}//end clock()

	/**
	 * "Di" for a day.
	 *
	 * @param string $day `YYYY-MM-DD`.
	 *
	 * @return string
	 */
	private function weekday(string $day): string {
		try {
			return self::WEEKDAYS[(int)(new DateTimeImmutable($day))->format('w')];
		} catch (Throwable) {
			return '';
		}
	}//end weekday()

	/**
	 * "13 november".
	 *
	 * @param string $day `YYYY-MM-DD`.
	 *
	 * @return string
	 */
	private function dayAndMonth(string $day): string {
		return ((int)substr($day, 8, 2)) . ' ' . (self::MONTHS[((int)substr($day, 5, 2)) - 1] ?? '');
	}//end dayAndMonth()
}//end class
