<?php

/**
 * Learniq Assessment Access Policy
 *
 * The one place that decides whether a learner may start an attempt on an
 * Assessment right now: the availableFrom/availableUntil window and the
 * optional access code.
 *
 * The window is evaluated LIVE against the two dates, not read from the
 * materialised `isAvailable` calculation. That calculation is stored when the
 * Assessment is saved, so an assessment saved before its window opened keeps
 * `isAvailable: false` after it opens, and one saved inside its window keeps
 * `isAvailable: true` after it closes. `isAvailable` is only consulted when
 * neither date is set on the row.
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
 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;

/**
 * Stateless window and access-code checks for an Assessment row.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
 */
class AssessmentAccessPolicy {

	public const REASON_NOT_OPEN = 'window-not-open';
	public const REASON_CLOSED = 'window-closed';
	public const REASON_CODE_REQUIRED = 'access-code-required';
	public const REASON_CODE_INVALID = 'access-code-invalid';

	/**
	 * Why the Assessment's availability window refuses an attempt at $now.
	 *
	 * @param array<string, mixed> $assessment The Assessment row.
	 * @param DateTimeInterface $now Evaluation instant.
	 *
	 * @return array{reason: string, message: string}|null Null when the window is open or absent.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
	 */
	public function windowBlock(array $assessment, DateTimeInterface $now): ?array {
		$from = $this->parseDate(value: ($assessment['availableFrom'] ?? null));
		$until = $this->parseDate(value: ($assessment['availableUntil'] ?? null));

		if ($from === null && $until === null) {
			if (($assessment['isAvailable'] ?? null) === false) {
				return [
					'reason' => self::REASON_CLOSED,
					'message' => 'This assessment is outside its available window.',
				];
			}

			return null;
		}

		if ($from !== null && $now < $from) {
			return [
				'reason' => self::REASON_NOT_OPEN,
				'message' => sprintf('This assessment is not open yet. It opens on %s.', $from->format(DateTimeInterface::ATOM)),
			];
		}

		if ($until !== null && $now > $until) {
			return [
				'reason' => self::REASON_CLOSED,
				'message' => sprintf('This assessment closed on %s.', $until->format(DateTimeInterface::ATOM)),
			];
		}

		return null;
	}//end windowBlock()

	/**
	 * Whether the Assessment is behind an access code. Needs the raw row: the
	 * code is write-only and is stripped from every rendered read.
	 *
	 * @param array<string, mixed> $assessment The raw Assessment row.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
	 */
	public function requiresAccessCode(array $assessment): bool {
		return $this->normaliseCode(value: ($assessment['accessCode'] ?? null)) !== '';
	}//end requiresAccessCode()

	/**
	 * Why the given access code refuses an attempt, or null when it is accepted
	 * (or the Assessment has no code). Surrounding whitespace is ignored; the
	 * comparison is constant-time.
	 *
	 * @param array<string, mixed> $assessment The raw Assessment row.
	 * @param mixed $given The code the learner typed.
	 *
	 * @return array{reason: string, message: string}|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
	 */
	public function accessCodeBlock(array $assessment, mixed $given): ?array {
		$expected = $this->normaliseCode(value: ($assessment['accessCode'] ?? null));
		if ($expected === '') {
			return null;
		}

		$typed = $this->normaliseCode(value: $given);
		if ($typed === '') {
			return [
				'reason' => self::REASON_CODE_REQUIRED,
				'message' => 'This assessment needs an access code. Ask the person supervising the test.',
			];
		}

		if (hash_equals($expected, $typed) === false) {
			return [
				'reason' => self::REASON_CODE_INVALID,
				'message' => 'The access code is not correct.',
			];
		}

		return null;
	}//end accessCodeBlock()

	/**
	 * Trim a code value to a string, '' for anything that is not a string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string
	 */
	private function normaliseCode(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end normaliseCode()

	/**
	 * Parse an ISO-8601 date-time, null for an empty or unparseable value.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parseDate(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception $exception) {
			return null;
		}
	}//end parseDate()
}//end class
