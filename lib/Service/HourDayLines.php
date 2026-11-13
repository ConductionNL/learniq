<?php

/**
 * Learniq HourDayLines
 *
 * The day lines of a week of BPV hours: each day's date, start and end time,
 * break, what was done, the work processes it belongs to and an optional
 * photo. The hours of a day are worked out here, never taken from the caller:
 * the time between start and end, less the break. The week's submitted hours
 * are the sum of its days.
 *
 * A line is refused when its date is not a valid day inside the week, when its
 * end is not after its start, when its break is as long as the time worked, or
 * when the week already holds a line for that date.
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
 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-holds-its-days
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;

/**
 * Normalises the day lines of a week and sums their hours.
 *
 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-holds-its-days
 */
final class HourDayLines {

	/**
	 * A date inside the week was not given, or not as YYYY-MM-DD.
	 */
	public const DATE_OUTSIDE_WEEK = 'hour-day-outside-week';

	/**
	 * A start or end time is missing or not HH:MM, or the end is not after the start.
	 */
	public const TIMES_INVALID = 'hour-day-times-invalid';

	/**
	 * The break is negative or as long as the time between start and end.
	 */
	public const BREAK_TOO_LONG = 'hour-day-break-too-long';

	/**
	 * Two lines carry the same date.
	 */
	public const DUPLICATE_DAY = 'hour-day-duplicate';

	/**
	 * A day line that is not an object.
	 */
	public const LINE_INVALID = 'hour-day-invalid';

	/**
	 * The text fields a day line keeps as they are sent.
	 *
	 * @var array<int, string>
	 */
	private const TEXT_FIELDS = ['description', 'photoRef', 'returnedQuestion'];

	/**
	 * The day lines with their hours worked out, sorted by date, and their sum.
	 *
	 * @param array<int|string, mixed> $days    The day lines as sent.
	 * @param string                   $isoWeek The week, as 2026-W41.
	 *
	 * @return array{days: array<int, array<string, mixed>>, total: float, refusal: string|null}
	 *
	 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-holds-its-days
	 */
	public function normalise(array $days, string $isoWeek): array {
		$week = self::weekDates(isoWeek: $isoWeek);
		$lines = [];
		foreach ($days as $day) {
			if (is_array($day) === false) {
				return self::refused(reason: self::LINE_INVALID);
			}

			$line = $this->line(day: $day, week: $week);
			if (is_string($line) === true) {
				return self::refused(reason: $line);
			}

			if (isset($lines[$line['date']]) === true) {
				return self::refused(reason: self::DUPLICATE_DAY);
			}

			$lines[$line['date']] = $line;
		}

		ksort($lines);

		return [
			'days' => array_values($lines),
			'total' => self::total(days: $lines),
			'refusal' => null,
		];
	}//end normalise()

	/**
	 * The day lines with a question set on the line of one date.
	 *
	 * @param array<int|string, mixed> $days     The day lines.
	 * @param string                   $date     The date the question is about.
	 * @param string                   $question The question.
	 *
	 * @return array<int, array<string, mixed>>|null The lines, or null when no line holds that date.
	 *
	 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-returned-week-names-the-day-and-the-question
	 */
	public static function withQuestion(array $days, string $date, string $question): ?array {
		$found = false;
		$lines = [];
		foreach ($days as $day) {
			if (is_array($day) === false) {
				continue;
			}

			if (($day['date'] ?? null) === $date) {
				$day['returnedQuestion'] = $question;
				$found = true;
			}

			$lines[] = $day;
		}

		if ($found === false) {
			return null;
		}

		return $lines;
	}//end withQuestion()

	/**
	 * One normalised line, or the reason it is refused.
	 *
	 * @param array<string, mixed>  $day  The line as sent.
	 * @param array<int, string>    $week The seven dates of the week; empty when the week is unreadable.
	 *
	 * @return array<string, mixed>|string
	 */
	private function line(array $day, array $week): array|string {
		$date = self::text(value: ($day['date'] ?? null));
		if (in_array($date, $week, true) === false) {
			return self::DATE_OUTSIDE_WEEK;
		}

		$start = self::minutes(time: self::text(value: ($day['startTime'] ?? null)));
		$end = self::minutes(time: self::text(value: ($day['endTime'] ?? null)));
		if ($start === null || $end === null || $end <= $start) {
			return self::TIMES_INVALID;
		}

		// An absent or empty break is no break.
		$break = ($day['breakMinutes'] ?? 0);

		if (is_int($break) === false || $break < 0 || $break >= ($end - $start)) {
			return self::BREAK_TOO_LONG;
		}

		$line = [
			'date' => $date,
			'startTime' => self::text(value: $day['startTime']),
			'endTime' => self::text(value: $day['endTime']),
			'breakMinutes' => $break,
			'hours' => round(($end - $start - $break) / 60, 2),
			'werkprocesCodes' => self::codes(value: ($day['werkprocesCodes'] ?? [])),
		];
		foreach (self::TEXT_FIELDS as $field) {
			$value = self::text(value: ($day[$field] ?? null));
			if ($value !== '') {
				$line[$field] = $value;
			}
		}

		return $line;
	}//end line()

	/**
	 * The sum of the lines' hours.
	 *
	 * @param array<int|string, array<string, mixed>> $days The lines.
	 *
	 * @return float
	 */
	private static function total(array $days): float {
		$total = 0.0;
		foreach ($days as $day) {
			$total += (float)$day['hours'];
		}

		return round($total, 2);
	}//end total()

	/**
	 * The seven dates of an ISO week, or none when the week is unreadable.
	 *
	 * @param string $isoWeek The week, as 2026-W41.
	 *
	 * @return array<int, string>
	 */
	private static function weekDates(string $isoWeek): array {
		if (preg_match('/^(\d{4})-W(\d{2})$/', $isoWeek, $match) !== 1 || (int)$match[2] < 1 || (int)$match[2] > 53) {
			return [];
		}

		$monday = (new DateTimeImmutable())->setISODate((int)$match[1], (int)$match[2], 1);
		if ($monday->format('o-\WW') !== $isoWeek) {
			return [];
		}

		$dates = [];
		for ($day = 0; $day < 7; $day++) {
			$dates[] = $monday->modify('+' . $day . ' days')->format('Y-m-d');
		}

		return $dates;
	}//end weekDates()

	/**
	 * Minutes since midnight of an HH:MM time, or null when it is not one.
	 *
	 * @param string $time The time.
	 *
	 * @return int|null
	 */
	private static function minutes(string $time): ?int {
		if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $match) !== 1) {
			return null;
		}

		return ((int)$match[1] * 60) + (int)$match[2];
	}//end minutes()

	/**
	 * The work process codes of a line: non-empty texts, each once.
	 *
	 * @param mixed $value The codes as sent.
	 *
	 * @return array<int, string>
	 */
	private static function codes(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$codes = array_filter(
			array_map(static fn (mixed $code): string => self::text(value: $code), $value),
			static fn (string $code): bool => $code !== ''
		);

		return array_values(array_unique($codes));
	}//end codes()

	/**
	 * A refusal in the shape normalise() answers.
	 *
	 * @param string $reason The reason.
	 *
	 * @return array{days: array<int, array<string, mixed>>, total: float, refusal: string}
	 */
	private static function refused(string $reason): array {
		return ['days' => [], 'total' => 0.0, 'refusal' => $reason];
	}//end refused()

	/**
	 * A trimmed text, or empty for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
