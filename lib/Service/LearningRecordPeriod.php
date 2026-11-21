<?php

/**
 * Learniq learning-record export period.
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
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;

/**
 * The requested periodFrom/periodTo of a learning-record export, and the
 * permissive rule deciding whether a row falls inside it.
 *
 * "No usable date" never narrows: a missing date field, an absent or
 * unparseable row date and an absent or unparseable bound all keep a row in
 * scope rather than guessing at it.
 *
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-learner-initiated-export-produces-a-signed-dual-shaped-bundle
 */
final class LearningRecordPeriod {

	/**
	 * Constructor.
	 *
	 * @param string|null $from Requested period start (inclusive), or null.
	 * @param string|null $to   Requested period end (inclusive), or null.
	 */
	public function __construct(
		public readonly ?string $from,
		public readonly ?string $to,
	) {
	}//end __construct()

	/**
	 * Whether a row falls within the period.
	 *
	 * A row with no comparable date field for its schema (`$dateField` is
	 * null) is always in scope, as is every row when no bound is requested.
	 *
	 * @param array<string,mixed> $row       The source row.
	 * @param string|null         $dateField Field name holding the row's comparable date, or null.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-learner-initiated-export-produces-a-signed-dual-shaped-bundle
	 */
	public function covers(array $row, ?string $dateField): bool {
		if ($dateField === null || ($this->from === null && $this->to === null)) {
			return true;
		}

		// An absent or unparseable row date keeps the row in scope rather than
		// guessing at it.
		$date = $this->parseDate(value: ($row[$dateField] ?? null));
		if ($date === null) {
			return true;
		}

		return $this->containsDate(date: $date);
	}//end covers()

	/**
	 * Whether a date lies between the bounds, each bound inclusive.
	 *
	 * An unparseable bound is never a reason to exclude a row.
	 *
	 * @param DateTimeImmutable $date The row date.
	 *
	 * @return bool
	 */
	private function containsDate(DateTimeImmutable $date): bool {
		$from = $this->parseDate(value: $this->from);
		if ($from !== null && $date < $from) {
			return false;
		}

		$to = $this->parseDate(value: $this->to);

		return ($to === null || $date <= $to);
	}//end containsDate()

	/**
	 * Parse a value into a date, or null when it is absent or unusable.
	 *
	 * @param mixed $value Candidate date value (expected to be an ISO-8601 string).
	 *
	 * @return DateTimeImmutable|null Parsed date, or null when not a parseable non-empty string.
	 */
	private function parseDate(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}
	}//end parseDate()
}//end class
