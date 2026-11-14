<?php

/**
 * Learniq SubstitutionDeadline: until when an employer may send a colleague in a participant's place.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/employer-substitutes-a-colleague/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The substitution deadline of a course date, and the refusal in words once it passed.
 *
 * The cohort's `substituteUntil` wins; without it the deadline is the working
 * day before the first course day at 12.00, Amsterdam time. A booking without
 * a known course day has no deadline yet.
 *
 * @spec openspec/changes/employer-substitutes-a-colleague/specs/portal-contribution/spec.md
 */
final class SubstitutionDeadline {

	/**
	 * The hour of the default deadline.
	 *
	 * @var int
	 */
	private const HOUR = 12;

	/**
	 * Dutch day names, ISO-8601 weekday number => name.
	 *
	 * @var array<int, string>
	 */
	private const DAYS = [1 => 'maandag', 2 => 'dinsdag', 3 => 'woensdag', 4 => 'donderdag', 5 => 'vrijdag', 6 => 'zaterdag', 7 => 'zondag'];

	/**
	 * The deadline of a course date.
	 *
	 * @param array<string, mixed> $cohort   The course date (cohort).
	 * @param string|null          $firstDay The first course day, `Y-m-d`, or null when none is planned.
	 *
	 * @return DateTimeImmutable|null The deadline, or null when there is none yet.
	 *
	 * @spec openspec/changes/employer-substitutes-a-colleague/specs/portal-contribution/spec.md
	 */
	public static function moment(array $cohort, ?string $firstDay): ?DateTimeImmutable {
		$zone = new DateTimeZone(CourseDayLines::ZONE);
		$explicit = trim((string)($cohort['substituteUntil'] ?? ''));
		if ($explicit !== '') {
			try {
				return (new DateTimeImmutable($explicit))->setTimezone($zone);
			} catch (Throwable) {
				// An unreadable value falls back to the default rule.
			}
		}

		$day = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$firstDay, $zone);
		if ($day === false) {
			return null;
		}

		$before = $day->modify('-1 day');
		while ((int)$before->format('N') > 5) {
			$before = $before->modify('-1 day');
		}

		return $before->setTime(self::HOUR, 0);
	}//end moment()

	/**
	 * The refusal once the deadline passed, or null while a substitute may still be sent.
	 *
	 * @param array<string, mixed> $cohort   The course date (cohort).
	 * @param string|null          $firstDay The first course day, `Y-m-d`.
	 * @param DateTimeImmutable    $now      The moment of the request.
	 *
	 * @return string|null The refusal in words, e.g. "Vervangen kan tot woensdag 12:00 uur. Bel de planning."
	 *
	 * @spec openspec/changes/employer-substitutes-a-colleague/specs/portal-contribution/spec.md
	 */
	public static function refusal(array $cohort, ?string $firstDay, DateTimeImmutable $now): ?string {
		$deadline = self::moment(cohort: $cohort, firstDay: $firstDay);
		if ($deadline === null || $now <= $deadline) {
			return null;
		}

		return sprintf(
			'Vervangen kan tot %s %s uur. Bel de planning.',
			self::DAYS[(int)$deadline->format('N')],
			$deadline->format('H:i')
		);
	}//end refusal()
}//end class
