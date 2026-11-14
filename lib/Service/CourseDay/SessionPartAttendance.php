<?php

/**
 * Learniq SessionPartAttendance
 *
 * Attendance per part of a course day's session (morning, afternoon): which
 * parts have begun, how many participants each part registered as there, who
 * came in late and when, and which begun part still waits for its register.
 * Pure: the caller gathers the session, the records and the participants.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CourseDay
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseDay;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Summarises a session's attendance per part.
 *
 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
 */
final class SessionPartAttendance {

	/**
	 * The parts, in the order of the day.
	 *
	 * @var list<string>
	 */
	public const PARTS = ['morning', 'afternoon'];

	/**
	 * Statuses that count a participant as there.
	 *
	 * @var list<string>
	 */
	private const THERE = ['present', 'late', 'left-early'];

	/**
	 * The summary of a session's parts.
	 *
	 * A part is registered once any record names it. `due` is the latest part
	 * that has begun and is not registered yet, or null.
	 *
	 * @param array<string, mixed>       $session      The session row (with `parts`).
	 * @param list<array<string, mixed>> $records      The session's attendance records.
	 * @param int                        $participants How many participants the day expects.
	 * @param DateTimeInterface          $now          Now.
	 *
	 * @return array{
	 *     parts: list<array{
	 *         key: string, begun: bool, registered: bool, there: int, of: int,
	 *         late: list<array{learnerId: string, arrivedAt: string|null}>
	 *     }>,
	 *     due: string|null
	 * }
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
	 */
	public static function summarise(array $session, array $records, int $participants, DateTimeInterface $now): array {
		$parts = [];
		$due   = null;
		foreach (self::parts(session: $session) as $key => $startsAt) {
			$begun      = ($startsAt !== null && $startsAt <= $now);
			$mine       = array_values(array_filter($records, static fn (array $record): bool => ($record['part'] ?? null) === $key));
			$tally      = self::tally(records: $mine);
			$registered = ($mine !== []);
			if ($begun === true && $registered === false) {
				$due = $key;
			}

			$parts[] = [
				'key' => $key,
				'begun' => $begun,
				'registered' => $registered,
				'there' => $tally['there'],
				'of' => $participants,
				'late' => $tally['late'],
			];
		}

		return ['parts' => $parts, 'due' => $due];
	}//end summarise()

	/**
	 * Who of one part's records was there, and who came late and when.
	 *
	 * @param list<array<string, mixed>> $records The part's attendance records.
	 *
	 * @return array{there: int, late: list<array{learnerId: string, arrivedAt: string|null}>}
	 */
	private static function tally(array $records): array {
		$there = [];
		$late  = [];
		foreach ($records as $record) {
			$learner = (string)($record['learnerId'] ?? ($record['learnerRef'] ?? ''));
			if (in_array((string)($record['status'] ?? ''), self::THERE, true) === true) {
				$there[$learner] = true;
			}

			if (($record['status'] ?? null) === 'late') {
				$late[] = ['learnerId' => $learner, 'arrivedAt' => self::time(value: ($record['arrivedAt'] ?? null))];
			}
		}

		return ['there' => count($there), 'late' => $late];
	}//end tally()

	/**
	 * The session's parts in day order, each with its start (or null when it has none).
	 *
	 * @param array<string, mixed> $session The session row.
	 *
	 * @return array<string, DateTimeImmutable|null>
	 */
	private static function parts(array $session): array {
		$found = [];
		foreach (self::knownParts(session: $session) as $part) {
			$found[(string)$part['key']] = self::moment(value: ($part['startsAt'] ?? null));
		}

		$ordered = [];
		foreach (self::PARTS as $key) {
			if (array_key_exists($key, $found) === true) {
				$ordered[$key] = $found[$key];
			}
		}

		return $ordered;
	}//end parts()

	/**
	 * The session's parts that are arrays with a known key, in stored order.
	 *
	 * @param array<string, mixed> $session The session.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function knownParts(array $session): array {
		$parts = ($session['parts'] ?? null);
		if (is_array($parts) === false) {
			return [];
		}

		return array_values(
			array_filter(
				$parts,
				static fn (mixed $part): bool => is_array($part) === true && in_array(($part['key'] ?? null), self::PARTS, true) === true
			)
		);
	}//end knownParts()

	/**
	 * A stored timestamp, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $exception) {
			return null;
		}
	}//end moment()

	/**
	 * A stored HH:MM time, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null
	 */
	private static function time(mixed $value): ?string {
		if (is_string($value) === true && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1) {
			return $value;
		}

		return null;
	}//end time()
}//end class
