<?php

/**
 * Learniq CourseDayLines
 *
 * The Dutch lines a company booking shows about its course days: "donderdag 8
 * oktober", "dinsdag 20 en woensdag 21 oktober", "3, 4 en 10 november",
 * "08.30 tot 16.30 uur", and the moment details are due. Pure, so the example
 * sets and a test can check them (employer-portal-audience).
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Formats course days and times as the institute writes them.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
 */
class CourseDayLines {

	/**
	 * The time zone the institute's days are in.
	 */
	public const ZONE = 'Europe/Amsterdam';

	private const WEEKDAYS = ['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'];

	private const MONTHS = [
		'januari',
		'februari',
		'maart',
		'april',
		'mei',
		'juni',
		'juli',
		'augustus',
		'september',
		'oktober',
		'november',
		'december',
	];

	/**
	 * The distinct course days of the sessions, in order.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 *
	 * @return array<int, DateTimeImmutable>
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function days(array $sessions): array {
		$days = [];
		foreach ($sessions as $session) {
			$start = $this->date(value: ($session['startsAt'] ?? null));
			if ($start !== null) {
				$days[$start->format('Y-m-d')] = $start->setTime(0, 0);
			}
		}

		ksort($days);

		return array_values($days);
	}//end days()

	/**
	 * "donderdag 8 oktober", "dinsdag 20 en woensdag 21 oktober" or "3, 4 en 10 november".
	 *
	 * @param array<int, DateTimeImmutable> $days The course days.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function dayLabel(array $days): ?string {
		if ($days === []) {
			return null;
		}

		if (count($days) > 2) {
			$byMonth = [];
			foreach ($days as $day) {
				$byMonth[self::MONTHS[((int)$day->format('n') - 1)]][] = $day->format('j');
			}

			$parts = [];
			foreach ($byMonth as $month => $numbers) {
				$parts[] = $this->listOf(items: $numbers) . ' ' . $month;
			}

			return $this->listOf(items: $parts);
		}

		$parts = [];
		foreach ($days as $index => $day) {
			$next = ($days[$index + 1] ?? null);
			$parts[] = $this->dayAndDate(day: $day);
			if ($next !== null && $next->format('Y-m') === $day->format('Y-m')) {
				$parts[$index] = $this->weekday(day: $day) . ' ' . $day->format('j');
			}
		}

		return implode(' en ', $parts);
	}//end dayLabel()

	/**
	 * "08.30 tot 16.30 uur": the first day's start to its end.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 * @param DateTimeImmutable|null           $firstDay The first course day.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function timeLabel(array $sessions, ?DateTimeImmutable $firstDay): ?string {
		if ($firstDay === null) {
			return null;
		}

		$starts = [];
		$ends = [];
		foreach ($sessions as $session) {
			$start = $this->date(value: ($session['startsAt'] ?? null));
			$end = $this->date(value: ($session['endsAt'] ?? null));
			if ($start === null || $end === null || $start->format('Y-m-d') !== $firstDay->format('Y-m-d')) {
				continue;
			}

			$starts[] = $start->format('H.i');
			$ends[] = $end->format('H.i');
		}

		if ($starts === []) {
			return null;
		}

		return min($starts) . ' tot ' . max($ends) . ' uur';
	}//end timeLabel()

	/**
	 * 12.00 on the working day before the first course day: until then the
	 * employer may still supply names and details.
	 *
	 * @param DateTimeImmutable|null $firstDay The first course day.
	 *
	 * @return string|null An ISO date-time, or null without a day.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function detailsDueAt(?DateTimeImmutable $firstDay): ?string {
		if ($firstDay === null) {
			return null;
		}

		$day = $firstDay->modify('-1 day');
		while ((int)$day->format('N') > 5) {
			$day = $day->modify('-1 day');
		}

		return $day->setTime(12, 0)->format(DATE_ATOM);
	}//end detailsDueAt()

	/**
	 * The day a number of working days after another.
	 *
	 * @param DateTimeImmutable $day   The day.
	 * @param int               $count How many working days.
	 *
	 * @return DateTimeImmutable
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function workingDaysAfter(DateTimeImmutable $day, int $count): DateTimeImmutable {
		while ($count > 0) {
			$day = $day->modify('+1 day');
			if ((int)$day->format('N') <= 5) {
				$count--;
			}
		}

		return $day;
	}//end workingDaysAfter()

	/**
	 * "donderdag".
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function weekday(DateTimeImmutable $day): string {
		return self::WEEKDAYS[((int)$day->format('N') - 1)];
	}//end weekday()

	/**
	 * "dinsdag 6 oktober".
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function dayAndDate(DateTimeImmutable $day): string {
		return $this->weekday(day: $day) . ' ' . $day->format('j') . ' ' . self::MONTHS[((int)$day->format('n') - 1)];
	}//end dayAndDate()

	/**
	 * "8 oktober".
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-certificates/specs/portal-contribution/spec.md#requirement-a-certificate-names-its-holder-its-course-and-its-renewal
	 */
	public function dayMonth(DateTimeImmutable $day): string {
		return $day->format('j') . ' ' . self::MONTHS[((int)$day->format('n') - 1)];
	}//end dayMonth()

	/**
	 * "30 november 2026".
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function longDate(DateTimeImmutable $day): string {
		return $day->format('j') . ' ' . self::MONTHS[((int)$day->format('n') - 1)] . ' ' . $day->format('Y');
	}//end longDate()

	/**
	 * A date or date-time in the institute's zone, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value, new DateTimeZone(self::ZONE)))->setTimezone(new DateTimeZone(self::ZONE));
		} catch (\Exception) {
			return null;
		}
	}//end date()

	/**
	 * "a, b en c".
	 *
	 * @param array<int, string> $items The items.
	 *
	 * @return string
	 */
	private function listOf(array $items): string {
		$last = (string)array_pop($items);
		if ($items === []) {
			return $last;
		}

		return implode(', ', $items) . ' en ' . $last;
	}//end listOf()
}//end class
