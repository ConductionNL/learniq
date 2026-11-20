<?php

/**
 * Learniq HourReturnSubject
 *
 * The line a student reads in her messages when her workplace trainer sends
 * a week of hours back: "Vraag over je uren van dinsdag 29 september" for a
 * week with day lines, "Vraag over je uren van week 40" for a week without.
 * Written once, by the server, at the moment of the return, in the language
 * of the portal's other server-written notices.
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
 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-returned-week-names-the-day-and-the-question
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;

/**
 * Words the subject of a returned week's notice.
 *
 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-returned-week-names-the-day-and-the-question
 */
final class HourReturnSubject {

	private const PREFIX = 'Vraag over je uren van ';

	/**
	 * The weekday names, Monday first (ISO-8601 day 1 to 7).
	 */
	private const WEEKDAYS = [1 => 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'];

	/**
	 * The month names, January first.
	 */
	private const MONTHS = [
		1 => 'januari',
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
	 * The subject for a return about one day, or about the week when no
	 * readable day is named.
	 *
	 * @param string $date    The returned day, as YYYY-MM-DD; empty for a week without day lines.
	 * @param string $isoWeek The week, as 2026-W41.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-returned-week-names-the-day-and-the-question
	 */
	public static function of(string $date, string $isoWeek): string {
		$day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($day !== false && $day->format('Y-m-d') === $date) {
			return self::PREFIX . self::WEEKDAYS[(int)$day->format('N')] . ' ' . $day->format('j') . ' ' . self::MONTHS[(int)$day->format('n')];
		}

		return self::PREFIX . self::weekOf(isoWeek: $isoWeek);
	}//end of()

	/**
	 * "week 41" for 2026-W41, or "deze week" when the week cannot be read.
	 *
	 * @param string $isoWeek The week.
	 *
	 * @return string
	 */
	private static function weekOf(string $isoWeek): string {
		if (preg_match('/^\d{4}-W(\d{2})$/', $isoWeek, $match) !== 1) {
			return 'deze week';
		}

		return 'week ' . (int)$match[1];
	}//end weekOf()
}//end class
