<?php

/**
 * Learniq demo dates
 *
 * The example sets are drawn around one Monday: the boards' Monday 5 October
 * 2026. Ruben's decision (08 Oct): a load moves every date so that Monday
 * falls on the Monday of the week the load runs, by whole weeks, so every
 * weekday and every time keeps its pattern ("Je rooster vandaag" on a
 * Thursday shows Thursday's lessons).
 *
 * This class only computes: the offset for a day, and a value with every date
 * in it moved. It moves ISO dates (`2026-10-05`), ISO date-times with their
 * wall-clock time kept in Europe/Amsterdam (`2026-10-05T08:30:00+02:00`),
 * ISO weeks (`2026-W41`), Dutch day-month phrases ("maandag 5 oktober 2026",
 * "3, 4 en 10 november", "13 en 15 okt") and day-month-year numbers
 * ("29-10-2026"). A weekday name stays true because the move is whole weeks.
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
 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-load-moves-every-date-to-the-week-it-runs-in
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Moves the dates of an example set by whole weeks.
 *
 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-load-moves-every-date-to-the-week-it-runs-in
 */
class DemoDates {

	/**
	 * The Monday every board is drawn around.
	 */
	public const BOARD_MONDAY = '2026-10-05';

	/**
	 * The zone the sets' wall-clock times are in.
	 */
	private const ZONE = 'Europe/Amsterdam';

	/**
	 * Dutch month names, full and short, to their number.
	 *
	 * @var array<string, int>
	 */
	private const MONTHS = [
		'januari' => 1, 'februari' => 2, 'maart' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6,
		'juli' => 7, 'augustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'december' => 12,
		'jan' => 1, 'feb' => 2, 'mrt' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8,
		'sep' => 9, 'sept' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
	];

	/**
	 * The full month names, by number.
	 *
	 * @var array<int, string>
	 */
	private const FULL = [
		1 => 'januari', 2 => 'februari', 3 => 'maart', 4 => 'april', 5 => 'mei', 6 => 'juni',
		7 => 'juli', 8 => 'augustus', 9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
	];

	/**
	 * The short month names, by number.
	 *
	 * @var array<int, string>
	 */
	private const SHORT = [
		1 => 'jan', 2 => 'feb', 3 => 'mrt', 4 => 'apr', 5 => 'mei', 6 => 'jun',
		7 => 'jul', 8 => 'aug', 9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec',
	];

	/**
	 * The days between the boards' Monday and the Monday of `$today`'s week:
	 * always a whole number of weeks.
	 *
	 * @param DateTimeImmutable $today The day the load runs.
	 *
	 * @return int Days, a multiple of 7; negative before the boards' week.
	 *
	 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-load-moves-every-date-to-the-week-it-runs-in
	 */
	public function offsetFor(DateTimeImmutable $today): int {
		$zone   = new DateTimeZone(self::ZONE);
		$local  = $today->setTimezone($zone)->setTime(0, 0);
		$monday = $local->modify('-' . ((int)$local->format('N') - 1) . ' days');
		$board  = new DateTimeImmutable(self::BOARD_MONDAY, $zone);

		return (int)round(($monday->getTimestamp() - $board->getTimestamp()) / 86400 / 7) * 7;
	}//end offsetFor()

	/**
	 * A value with every date in it moved by `$days`.
	 *
	 * @param mixed $value Any decoded JSON value.
	 * @param int   $days  The days to move, a multiple of 7.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-load-moves-every-date-to-the-week-it-runs-in
	 */
	public function shift(mixed $value, int $days): mixed {
		if ($days === 0) {
			return $value;
		}

		if (is_array($value) === true) {
			foreach ($value as $key => $item) {
				$value[$key] = $this->shift(value: $item, days: $days);
			}

			return $value;
		}

		if (is_string($value) === true) {
			return $this->shiftString(text: $value, days: $days);
		}

		return $value;
	}//end shift()

	/**
	 * One string with its dates moved.
	 *
	 * @param string $text The string.
	 * @param int    $days The days to move.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-load-moves-every-date-to-the-week-it-runs-in
	 */
	public function shiftString(string $text, int $days): string {
		if ($days === 0 || preg_match('/\d/', $text) !== 1) {
			return $text;
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
			return $this->moveDate(date: $text, days: $days);
		}

		if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(:\d{2}(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $text, $parts) === 1) {
			return $this->moveDateTime(text: $text, parts: $parts, days: $days);
		}

		if (preg_match('/^(\d{4})-W(\d{2})$/', $text, $parts) === 1) {
			$week = (new DateTimeImmutable())->setISODate((int)$parts[1], (int)$parts[2])->modify($this->days(days: $days));
			return $week->format('o-\WW');
		}

		return $this->shiftProse(text: $text, days: $days);
	}//end shiftString()

	/**
	 * A plain ISO date moved.
	 *
	 * @param string $date The date.
	 * @param int    $days The days.
	 *
	 * @return string
	 */
	private function moveDate(string $date, int $days): string {
		try {
			return (new DateTimeImmutable($date . 'T12:00:00'))->modify($this->days(days: $days))->format('Y-m-d');
		} catch (Throwable) {
			return $date;
		}
	}//end moveDate()

	/**
	 * An ISO date-time moved with its wall-clock time kept in Amsterdam, so
	 * 08.30 stays 08.30 across a change of summer time.
	 *
	 * @param string             $text  The date-time.
	 * @param array<int, string> $parts The regex parts: date, hh:mm, :ss, zone.
	 * @param int                $days  The days.
	 *
	 * @return string
	 */
	private function moveDateTime(string $text, array $parts, int $days): string {
		try {
			$zone   = ($parts[4] ?? '');
			$moment = new DateTimeImmutable($text);
			if ($zone === '' || $zone === 'Z') {
				// A floating wall-clock time, or UTC: move it as written.
				$moved = $moment->modify($this->days(days: $days));
				return $moved->format('Y-m-d\TH:i') . $this->seconds(parts: $parts, moment: $moved) . $zone;
			}

			$local = $moment->setTimezone(new DateTimeZone(self::ZONE))->modify($this->days(days: $days));
			return $local->format('Y-m-d\TH:i') . $this->seconds(parts: $parts, moment: $local) . $local->format('P');
		} catch (Throwable) {
			return $text;
		}
	}//end moveDateTime()

	/**
	 * The seconds part as the input wrote it, or '' when it had none.
	 *
	 * @param array<int, string> $parts  The regex parts.
	 * @param DateTimeImmutable  $moment The moved moment.
	 *
	 * @return string
	 */
	private function seconds(array $parts, DateTimeImmutable $moment): string {
		$written = ($parts[3] ?? '');
		if ($written === '') {
			return '';
		}

		// Keep a fraction as written; the seconds themselves do not move.
		return ':' . $moment->format('s') . (string)substr($written, 3);
	}//end seconds()

	/**
	 * Dutch date phrases and day-month-year numbers inside running text.
	 *
	 * @param string $text The text.
	 * @param int    $days The days.
	 *
	 * @return string
	 */
	private function shiftProse(string $text, int $days): string {
		$text = (string)preg_replace_callback(
			'/\b(\d{1,2})-(\d{1,2})-(\d{4})\b/',
			function (array $found) use ($days): string {
				$moved = $this->moveDate(date: sprintf('%04d-%02d-%02d', (int)$found[3], (int)$found[2], (int)$found[1]), days: $days);
				[$year, $month, $day] = array_map('intval', explode('-', $moved));
				return sprintf('%02d-%02d-%04d', $day, $month, $year);
			},
			$text
		);

		$names = implode('|', array_keys(self::MONTHS));
		$list  = '(\d{1,2})((?:(?:, | en | - | tot en met )\d{1,2})*)';

		return (string)preg_replace_callback(
			'/(?<![\d-])' . $list . ' (' . $names . ')\b(?: (\d{4}))?/iu',
			fn (array $found): string => $this->movePhrase(match: $found, days: $days),
			$text
		);
	}//end shiftProse()

	/**
	 * One phrase "3, 4 en 10 november [2026]" moved.
	 *
	 * @param array<int, string> $match The regex match: whole, first day, the rest, month, year.
	 * @param int                $days  The days.
	 *
	 * @return string
	 */
	private function movePhrase(array $match, int $days): string {
		$monthWord = strtolower($match[3]);
		$month     = self::MONTHS[$monthWord];
		$year      = $this->yearFor(month: $month);
		if (($match[4] ?? '') !== '') {
			$year = (int)$match[4];
		}

		$pieces = $this->movedPieces(days: $match[1] . $match[2], month: $month, year: $year, shift: $days);
		if ($pieces === null) {
			return $match[0];
		}

		return $this->writePieces(
			pieces: $pieces,
			short: self::FULL[$month] !== $monthWord,
			like: $match[3],
			withYear: ($match[4] ?? '') !== ''
		);
	}//end movePhrase()

	/**
	 * The days and separators of a phrase, each day moved; null when a day
	 * does not exist in that month.
	 *
	 * @param string $days  The days and separators ("3, 4 en 10").
	 * @param int    $month The month.
	 * @param int    $year  The year.
	 * @param int    $shift The days to move.
	 *
	 * @return array<int, string|DateTimeImmutable>|null A separator string or a moved day.
	 */
	private function movedPieces(string $days, int $month, int $year, int $shift): ?array {
		preg_match_all('/(\d{1,2})|(, | en | - | tot en met )/', $days, $tokens, PREG_SET_ORDER);
		$pieces = [];
		foreach ($tokens as $token) {
			if (($token[1] ?? '') === '') {
				$pieces[] = $token[2];
				continue;
			}

			if (checkdate($month, (int)$token[1], $year) === false) {
				return null;
			}

			$pieces[] = (new DateTimeImmutable(sprintf('%04d-%02d-%02dT12:00:00', $year, $month, (int)$token[1])))->modify($this->days(days: $shift));
		}

		return $pieces;
	}//end movedPieces()

	/**
	 * Write moved days back as a phrase, naming the month (and year) after
	 * the last day of each month.
	 *
	 * @param array<int, string|DateTimeImmutable> $pieces   Separators and moved days.
	 * @param bool                                 $short    Whether the phrase used a short month name.
	 * @param string                               $like     The month word it used, for its case.
	 * @param bool                                 $withYear Whether the phrase named a year.
	 *
	 * @return string
	 */
	private function writePieces(array $pieces, bool $short, string $like, bool $withYear): string {
		$days = array_values(array_filter($pieces, static fn ($piece): bool => $piece instanceof DateTimeImmutable));
		$out   = '';
		$index = 0;
		foreach ($pieces as $piece) {
			if (is_string($piece) === true) {
				$out .= $piece;
				continue;
			}

			$next = ($days[$index + 1] ?? null);
			$index++;
			$out .= $piece->format('j');
			if ($next !== null && $next->format('n') === $piece->format('n')) {
				continue;
			}

			$names = self::FULL;
			if ($short === true) {
				$names = self::SHORT;
			}

			$out .= ' ' . $this->keepCase(word: $names[(int)$piece->format('n')], like: $like);
			if ($withYear === true && ($next === null || $next->format('Y') !== $piece->format('Y'))) {
				$out .= ' ' . $piece->format('Y');
			}
		}//end foreach

		return $out;
	}//end writePieces()

	/**
	 * The year of a day-month phrase without a year: the one that puts it
	 * closest to the boards' Monday (the school year 2026-2027).
	 *
	 * @param int $month The month.
	 *
	 * @return int
	 */
	private function yearFor(int $month): int {
		$boardYear  = (int)substr(self::BOARD_MONDAY, 0, 4);
		$boardMonth = (int)substr(self::BOARD_MONDAY, 5, 2);
		if ($month - $boardMonth > 6) {
			return $boardYear - 1;
		}

		if ($boardMonth - $month > 6) {
			return $boardYear + 1;
		}

		return $boardYear;
	}//end yearFor()

	/**
	 * A month name in the case of the word it replaces ("Oktober" stays capitalised).
	 *
	 * @param string $word The new month name, lower case.
	 * @param string $like The word it replaces.
	 *
	 * @return string
	 */
	private function keepCase(string $word, string $like): string {
		if ($like !== '' && ctype_upper($like[0]) === true) {
			return ucfirst($word);
		}

		return $word;
	}//end keepCase()

	/**
	 * A modify() string for a number of days.
	 *
	 * @param int $days The days.
	 *
	 * @return string
	 */
	private function days(int $days): string {
		if ($days >= 0) {
			return '+' . $days . ' days';
		}

		return $days . ' days';
	}//end days()
}//end class
