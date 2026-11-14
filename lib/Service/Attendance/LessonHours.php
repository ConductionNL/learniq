<?php

/**
 * Learniq lesson hours of a school day
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Learniq\Service\Attendance
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/absence-reports-seen-and-ended/tasks.md#T3
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Attendance;

/**
 * Which lesson hour a session is, and whether an absence report's lesson
 * hours cover it (absence-reports-seen-and-ended T3).
 *
 * A session carries no lesson-hour number. The hour is its place in its
 * group's day: the group's sessions of that calendar day, ordered by
 * `startsAt`, are hours 1, 2, 3 and so on. A session that cannot be placed
 * (no start, not among the day's sessions) is in no hour. Side-effect free.
 *
 * @spec openspec/changes/absence-reports-seen-and-ended/tasks.md#T3
 */
final class LessonHours {

	/**
	 * The highest lesson hour the register accepts.
	 *
	 * @var int
	 */
	public const LAST_HOUR = 12;

	/**
	 * The hours a report names, or null when it names none (the whole day).
	 *
	 * Only `fromLessonHour` means that hour to the end of the day; only
	 * `toLessonHour` means the start of the day to that hour.
	 *
	 * @param array<string, mixed> $request The ExcuseRequest.
	 *
	 * @return array{0:int,1:int}|null The first and last hour.
	 *
	 * @spec openspec/changes/absence-reports-seen-and-ended/tasks.md#T3
	 */
	public function rangeOf(array $request): ?array {
		$from = $this->hour(value: ($request['fromLessonHour'] ?? null));
		$to = $this->hour(value: ($request['toLessonHour'] ?? null));
		if ($from === null && $to === null) {
			return null;
		}

		return [($from ?? 1), ($to ?? self::LAST_HOUR)];
	}//end rangeOf()

	/**
	 * The lesson hour of a session among its group's sessions.
	 *
	 * @param string                         $sessionId The session.
	 * @param array<int, mixed>               $sessions  The group's sessions (any days) as arrays; anything else is skipped.
	 *
	 * @return int|null The hour, or null when the session cannot be placed.
	 *
	 * @spec openspec/changes/absence-reports-seen-and-ended/tasks.md#T3
	 */
	public function hourOf(string $sessionId, array $sessions): ?int {
		$session = null;
		$sessions = array_values(array_filter($sessions, 'is_array'));
		foreach ($sessions as $candidate) {
			if ($this->idOf(session: $candidate) === $sessionId) {
				$session = $candidate;
				break;
			}
		}

		$start = $this->start(session: ($session ?? []));
		if ($start === null) {
			return null;
		}

		$day = $start->format('Y-m-d');
		$starts = [];
		foreach ($sessions as $candidate) {
			$other = $this->start(session: $candidate);
			if ($other !== null && $other->format('Y-m-d') === $day) {
				$starts[$this->idOf(session: $candidate)] = $other->getTimestamp();
			}
		}

		asort($starts);
		$position = array_search($sessionId, array_keys($starts), true);
		if ($position === false) {
			return null;
		}

		return ($position + 1);
	}//end hourOf()

	/**
	 * Whether an hour lies in a report's range.
	 *
	 * @param int|null            $hour  The session's hour.
	 * @param array{0:int,1:int}  $range The report's first and last hour.
	 *
	 * @return bool False for a session that could not be placed.
	 *
	 * @spec openspec/changes/absence-reports-seen-and-ended/tasks.md#T3
	 */
	public function covers(?int $hour, array $range): bool {
		return $hour !== null && $hour >= $range[0] && $hour <= $range[1];
	}//end covers()

	/**
	 * A lesson hour value, or null when it is none.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return int|null
	 */
	private function hour(mixed $value): ?int {
		if (is_int($value) === false && (is_string($value) === false || ctype_digit($value) === false)) {
			return null;
		}

		$hour = (int)$value;
		if ($hour < 1 || $hour > self::LAST_HOUR) {
			return null;
		}

		return $hour;
	}//end hour()

	/**
	 * A session's id.
	 *
	 * @param array<string, mixed> $session The session.
	 *
	 * @return string
	 */
	private function idOf(array $session): string {
		return (string)($session['id'] ?? ($session['uuid'] ?? ($session['@self']['id'] ?? '')));
	}//end idOf()

	/**
	 * A session's start, or null when it has none.
	 *
	 * @param array<string, mixed> $session The session.
	 *
	 * @return \DateTimeImmutable|null
	 */
	private function start(array $session): ?\DateTimeImmutable {
		$value = $session['startsAt'] ?? null;
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) !== 1) {
			return null;
		}

		try {
			return new \DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}
	}//end start()
}//end class
