<?php

/**
 * Learniq HourWeekLabel
 *
 * The working days of an ISO week in words, for a week of BPV hours:
 * "28 september tot en met 2 oktober". A readable copy, stored in the
 * school's own language like a group name (see EmployerBookingFacts).
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/changes/trainer-returns-hours-with-a-question/specs/bpv/spec.md#requirement-the-trainer-reads-whose-week-it-is-and-what-was-done
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;

/**
 * Turns `2026-W40` into "28 september tot en met 2 oktober".
 *
 * @spec openspec/changes/trainer-returns-hours-with-a-question/specs/bpv/spec.md#requirement-the-trainer-reads-whose-week-it-is-and-what-was-done
 */
class HourWeekLabel {

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
	 * Monday to Friday of the week, or null for a value that is no ISO week.
	 *
	 * @param mixed $isoWeek The week, as `2026-W40`.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/trainer-returns-hours-with-a-question/specs/bpv/spec.md#requirement-the-trainer-reads-whose-week-it-is-and-what-was-done
	 */
	public function label(mixed $isoWeek): ?string {
		if (is_string($isoWeek) === false || preg_match('/^(\d{4})-W(\d{2})$/', $isoWeek, $parts) !== 1) {
			return null;
		}

		$week = (int)$parts[2];
		if ($week < 1 || $week > 53) {
			return null;
		}

		$monday = (new DateTimeImmutable())->setISODate((int)$parts[1], $week, 1);
		$friday = $monday->modify('+4 days');
		$first = (int)$monday->format('j');
		if ($monday->format('n') !== $friday->format('n')) {
			$first .= ' ' . self::MONTHS[((int)$monday->format('n')) - 1];
		}

		return $first . ' tot en met ' . $friday->format('j') . ' ' . self::MONTHS[((int)$friday->format('n')) - 1];
	}//end label()
}//end class
