<?php

/**
 * Learniq Session Change Input
 *
 * Checks and normalises the body of a session change batch: the kind of
 * change, the ticked lessons, the reason, and the substitute or room it needs.
 *
 * @category Timetabling
 * @package  OCA\Learniq\Timetabling
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
 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-every-lesson-in-a-batch-passes-the-same-checks
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling;

use InvalidArgumentException;

/**
 * The input of one change on several lessons.
 *
 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-every-lesson-in-a-batch-passes-the-same-checks
 */
class SessionChangeInput {

	public const KINDS = ['cancel', 'substitute', 'room'];
	public const REASON_KINDS = ['teacher-absence', 'room-unavailable', 'timetable-change', 'other'];
	public const MAX_LESSONS = 60;

	/**
	 * Check and normalise the input.
	 *
	 * @param array<string, mixed> $input The request body.
	 *
	 * @return array{kind: string, sessionIds: array<int, string>, changeReasonKind: string,
	 *     changeReason: ?string, substituteTeacherId: ?string, roomId: ?string}
	 *
	 * @throws InvalidArgumentException When something required is missing.
	 *
	 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-every-lesson-in-a-batch-passes-the-same-checks
	 */
	public function validated(array $input): array {
		$kind = $this->oneOf(value: ($input['kind'] ?? ''), allowed: self::KINDS, message: 'Choose cancel, substitute or room.');
		$reasonKind = $this->oneOf(value: ($input['changeReasonKind'] ?? ''), allowed: self::REASON_KINDS, message: 'A change needs a reason.');

		return [
			'kind' => $kind,
			'sessionIds' => $this->sessionIds(value: ($input['sessionIds'] ?? [])),
			'changeReasonKind' => $reasonKind,
			'changeReason' => $this->optionalString(value: ($input['changeReason'] ?? null)),
			'substituteTeacherId' => $this->neededFor(
				kind: $kind,
				needs: 'substitute',
				value: ($input['substituteTeacherId'] ?? null),
				message: 'A substitute change needs a substitute teacher.'
			),
			'roomId' => $this->neededFor(kind: $kind, needs: 'room', value: ($input['roomId'] ?? null), message: 'A room change needs a room.'),
		];
	}//end validated()

	/**
	 * A value from a fixed list.
	 *
	 * @param mixed              $value   The value.
	 * @param array<int, string> $allowed The allowed values.
	 * @param string             $message The refusal.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the value is not allowed.
	 */
	private function oneOf(mixed $value, array $allowed, string $message): string {
		if (is_string($value) === false || in_array($value, $allowed, true) === false) {
			throw new InvalidArgumentException($message);
		}

		return $value;
	}//end oneOf()

	/**
	 * The ticked lessons: unique, not empty, at most MAX_LESSONS.
	 *
	 * @param mixed $value The list.
	 *
	 * @return array<int, string>
	 *
	 * @throws InvalidArgumentException When the list is empty or too long.
	 */
	private function sessionIds(mixed $value): array {
		if (is_array($value) === false) {
			$value = [];
		}

		$ids = array_values(array_unique(array_filter(array_map('strval', $value), static fn (string $id): bool => $id !== '')));
		if ($ids === [] || count($ids) > self::MAX_LESSONS) {
			throw new InvalidArgumentException('Tick between 1 and '.self::MAX_LESSONS.' lessons.');
		}

		return $ids;
	}//end sessionIds()

	/**
	 * A value one kind of change needs and the others ignore.
	 *
	 * @param string $kind    The kind of change.
	 * @param string $needs   The kind that needs the value.
	 * @param mixed  $value   The value.
	 * @param string $message The refusal when it is missing.
	 *
	 * @return string|null Null for the other kinds.
	 *
	 * @throws InvalidArgumentException When the kind needs it and it is missing.
	 */
	private function neededFor(string $kind, string $needs, mixed $value, string $message): ?string {
		if ($kind !== $needs) {
			return null;
		}

		$value = $this->optionalString(value: $value);
		if ($value === null) {
			throw new InvalidArgumentException($message);
		}

		return $value;
	}//end neededFor()

	/**
	 * A trimmed string, or null when empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function optionalString(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end optionalString()
}//end class
