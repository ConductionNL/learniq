<?php

/**
 * Learniq Elective Clashes
 *
 * The lesson-time clashes of a set of electives, worded for the subject
 * choice: two electives that meet at the same time, or an elective that meets
 * when one of the pupil's own lessons does. The weekly slots come from
 * ElectiveSlotService; this only compares and words them.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Timetable
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
 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Timetable;

use DateTimeImmutable;
use OCP\IL10N;

/**
 * Compares weekly slots and words the clashes.
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
 */
class ElectiveClashes {

	private const TIME = '/^([01]\d|2[0-3]):([0-5]\d)$/';

	/**
	 * Constructor.
	 *
	 * @param IL10N|null $l10n The messages in the user's language.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?IL10N $l10n = null,
	) {
	}//end __construct()

	/**
	 * One message per clash: every pair of electives, then every elective
	 * against the pupil's own lessons (one message per lesson title).
	 *
	 * @param array<string, string>                            $names   Elective name by course id.
	 * @param array<string, array<int, array<string, mixed>>> $courses Weekly slots by course id.
	 * @param array<int, array<string, mixed>>                 $core    The pupil's own weekly lessons.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
	 */
	public function messages(array $names, array $courses, array $core): array {
		$ids = array_keys($names);
		$out = [];
		foreach ($ids as $index => $first) {
			foreach (array_slice($ids, $index + 1) as $second) {
				$slot = self::firstOverlap(first: ($courses[$first] ?? []), second: ($courses[$second] ?? []));
				if ($slot !== null) {
					$out[] = $this->message(first: $names[$first], second: $names[$second], slot: $slot);
				}
			}
		}

		foreach ($ids as $id) {
			$seen = [];
			foreach ($core as $lesson) {
				$title = (string)($lesson['label'] ?? '');
				$slot = self::firstOverlap(first: ($courses[$id] ?? []), second: [$lesson]);
				if ($slot !== null && isset($seen[$title]) === false) {
					$seen[$title] = true;
					$out[] = $this->message(first: $names[$id], second: $title, slot: $slot);
				}
			}
		}

		return $out;
	}//end messages()

	/**
	 * Whether two weekly slots meet on the same weekday at an overlapping time.
	 *
	 * @param array<string, mixed> $first  A slot: `weekday`, `start`, `end` (`HH:MM`).
	 * @param array<string, mixed> $second Another slot.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
	 */
	public static function overlap(array $first, array $second): bool {
		if ((int)($first['weekday'] ?? 0) !== (int)($second['weekday'] ?? -1)) {
			return false;
		}

		$times = [
			self::minutes(value: ($first['start'] ?? null)),
			self::minutes(value: ($first['end'] ?? null)),
			self::minutes(value: ($second['start'] ?? null)),
			self::minutes(value: ($second['end'] ?? null)),
		];
		if (in_array(null, $times, true) === true) {
			return false;
		}

		return $times[0] < $times[3] && $times[2] < $times[1];
	}//end overlap()

	/**
	 * Minutes since midnight of an `HH:MM` time, or null.
	 *
	 * @param mixed $value The time.
	 *
	 * @return integer|null
	 */
	private static function minutes(mixed $value): ?int {
		if (is_string($value) === false || preg_match(self::TIME, $value, $match) !== 1) {
			return null;
		}

		return ((int)$match[1] * 60) + (int)$match[2];
	}//end minutes()

	/**
	 * The first slot of `first` that meets one of `second`, or null.
	 *
	 * @param array<int, array<string, mixed>> $first  Slots.
	 * @param array<int, array<string, mixed>> $second Slots.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function firstOverlap(array $first, array $second): ?array {
		foreach ($first as $slot) {
			foreach ($second as $other) {
				if (self::overlap(first: $slot, second: $other) === true) {
					return $slot;
				}
			}
		}

		return null;
	}//end firstOverlap()

	/**
	 * "Biology and Chemistry meet at the same time: Monday 09:00-10:00."
	 *
	 * @param string               $first  One name.
	 * @param string               $second The other.
	 * @param array<string, mixed> $slot   Where they meet.
	 *
	 * @return string
	 */
	private function message(string $first, string $second, array $slot): string {
		$day = (new DateTimeImmutable('2024-01-01'))->modify('+' . (max(1, (int)($slot['weekday'] ?? 1)) - 1) . ' days')->format('l');
		$text = '%1$s and %2$s meet at the same time: %3$s.';
		if ($this->l10n === null) {
			return sprintf($text, $first, $second, sprintf('%s %s–%s', $day, (string)($slot['start'] ?? ''), (string)($slot['end'] ?? '')));
		}

		$when = sprintf('%s %s–%s', $this->l10n->t($day), (string)($slot['start'] ?? ''), (string)($slot['end'] ?? ''));

		return $this->l10n->t($text, [$first, $second, $when]);
	}//end message()
}//end class
