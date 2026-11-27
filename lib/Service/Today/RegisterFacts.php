<?php

/**
 * Learniq Register Facts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the First today rules about registers count: a group whose register
 * of today is open after its first lesson started, the head's count of
 * unfilled registers from 9.30, and a teacher's lessons that ended without
 * a register. Every method takes rows already read, so each rule and its
 * empty case is a unit test.
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
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Today;

use DateTimeImmutable;

/**
 * The facts of the First today rules about registers.
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */
final class RegisterFacts {
	use RowValues;

	/**
	 * The moment of the day after which an unfilled register asks the head.
	 *
	 * @var string
	 */
	public const HEAD_REGISTER_TIME = '09:30';

	/**
	 * Groups whose register of today is not filled after their first lesson started.
	 *
	 * A group is `{cohort, sessions, records, reports}`: today's lessons, the
	 * number of saved marks per lesson id, and the number of absence reports
	 * of its pupils that cover today. The card names the first such group
	 * and how many of its pupils were reported absent by their parents.
	 *
	 * @param list<array<string, mixed>> $groups The caller's groups with today's lessons.
	 * @param DateTimeImmutable          $now    Now, in the school's time zone.
	 *
	 * @return array<string, mixed> count, cohortId, cohortName, reportCount.
	 *
	 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#scenario-meester-daan-before-845
	 */
	public function registerNotFilled(array $groups, DateTimeImmutable $now): array {
		$open = array_values(array_filter($groups, static fn (array $group): bool => self::registerOpen(group: $group, now: $now)));

		return [
			'count' => count($open),
			'cohortId' => self::idOf(row: (array)($open[0]['cohort'] ?? [])),
			'cohortName' => (string)($open[0]['cohort']['name'] ?? ''),
			'reportCount' => (int)($open[0]['reports'] ?? 0),
		];
	}//end registerNotFilled()

	/**
	 * Groups whose register is not filled at 9.30, against every group with a lesson today.
	 *
	 * Before 9.30 nothing counts: the head's rule waits for the moment the
	 * board names.
	 *
	 * @param list<array<string, mixed>> $groups Every group with today's lessons (see registerNotFilled()).
	 * @param DateTimeImmutable          $now    Now, in the school's time zone.
	 *
	 * @return array<string, mixed> count (groups not filled), total (groups with a lesson today), cohortName (the first not filled).
	 *
	 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#scenario-the-directeur-at-905
	 */
	public function registersNotFilledAt930(array $groups, DateTimeImmutable $now): array {
		$withLessons = array_values(array_filter($groups, static fn (array $group): bool => (array)($group['sessions'] ?? []) !== []));
		if ($now->format('H:i') < self::HEAD_REGISTER_TIME) {
			return ['count' => 0, 'total' => count($withLessons), 'cohortName' => ''];
		}

		$open = array_values(array_filter($withLessons, static fn (array $group): bool => self::registerOpen(group: $group, now: $now)));

		return [
			'count' => count($open),
			'total' => count($withLessons),
			'cohortName' => (string)($open[0]['cohort']['name'] ?? ''),
		];
	}//end registersNotFilledAt930()

	/**
	 * The caller's lessons of today that ended with no register saved.
	 *
	 * @param list<array<string, mixed>> $sessions Today's lessons of the caller, each with `markCount` (saved marks).
	 * @param DateTimeImmutable          $now      Now.
	 *
	 * @return array<string, mixed> count, sessionId, cohortId, sessionTitle, endsAt (of the earliest).
	 *
	 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public function lessonRegisterOpen(array $sessions, DateTimeImmutable $now): array {
		$open = array_values(
			array_filter(
				$sessions,
				static function (array $session) use ($now): bool {
					$ends = self::moment(value: ($session['endsAt'] ?? null));
					return $ends !== null && $ends <= $now && (int)($session['markCount'] ?? 0) === 0
						&& ($session['lifecycle'] ?? '') !== 'cancelled';
				}
			)
		);
		usort($open, static fn (array $one, array $two): int => strcmp((string)($one['endsAt'] ?? ''), (string)($two['endsAt'] ?? '')));

		return [
			'count' => count($open),
			'sessionId' => self::idOf(row: (array)($open[0] ?? [])),
			'cohortId' => (string)($open[0]['cohortId'] ?? ''),
			'sessionTitle' => (string)($open[0]['title'] ?? ''),
			'endsAt' => ($open[0]['endsAt'] ?? null),
		];
	}//end lessonRegisterOpen()

	/**
	 * Whether a group's register of today is open after its first lesson started.
	 *
	 * @param array<string, mixed> $group The group (see registerNotFilled()).
	 * @param DateTimeImmutable    $now   Now.
	 *
	 * @return bool
	 */
	private static function registerOpen(array $group, DateTimeImmutable $now): bool {
		$sessions = (array)($group['sessions'] ?? []);
		$started = false;
		foreach ($sessions as $session) {
			$starts = self::moment(value: ($session['startsAt'] ?? null));
			$started = $started || ($starts !== null && $starts <= $now);
			if ((int)(((array)($group['records'] ?? []))[self::idOf(row: (array)$session)] ?? 0) > 0) {
				return false;
			}
		}

		return $started;
	}//end registerOpen()
}//end class
