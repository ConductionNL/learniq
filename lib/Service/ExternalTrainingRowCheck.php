<?php

/**
 * Learniq External Training Row Check
 *
 * The field rules one row of an uploaded attendance list must meet before
 * its learner is looked up: title, provider and a completion date that is
 * not in the future, an optional expiry after it, and a kind the
 * ExternalTrainingRecord schema allows
 * (compliance-external-training-spreadsheet-upload design D5).
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
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-an-invalid-date-is-refused-per-row
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Validates the fields of one uploaded row and builds the record fields.
 *
 * @psalm-api
 *
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-an-invalid-date-is-refused-per-row
 */
class ExternalTrainingRowCheck {

	/**
	 * ExternalTrainingRecord.kind values; an empty kind is a classroom training.
	 *
	 * @var string[]
	 */
	public const KINDS = ['classroom', 'external-elearning', 'conference', 'on-the-job', 'other'];

	/**
	 * Check the fields of one row.
	 *
	 * @param array<string,mixed> $row The row: learner, title, provider, kind,
	 *                                 completedAt, validUntil, regulationSlug, evidenceNote.
	 * @param DateTimeInterface $now Evaluation instant.
	 *
	 * @return array{reason:?string,params:array<string,string>,learner:?string,record:array<string,string>}
	 *     The reason a row is invalid (null when it is not), and the record fields without the learner.
	 *
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-an-invalid-date-is-refused-per-row
	 */
	public function check(array $row, DateTimeInterface $now): array {
		$learner = $this->text(value: $row['learner'] ?? null);
		$title = $this->text(value: $row['title'] ?? null);
		$provider = $this->text(value: $row['provider'] ?? null);
		$kind = ($this->text(value: $row['kind'] ?? null) ?? 'classroom');
		$completed = $this->date(value: $row['completedAt'] ?? null);
		$validText = $this->text(value: $row['validUntil'] ?? null);
		$validUntil = $this->date(value: $validText);

		$reason = match (true) {
			$learner === null => 'The learner is missing.',
			$title === null => 'The title is missing.',
			$provider === null => 'The provider is missing.',
			in_array($kind, self::KINDS, true) === false => 'The kind must be one of: {kinds}.',
			$completed === null => 'The completed on date is missing or not a date.',
			$completed > $now => 'The completed on date is in the future.',
			$validText !== null && $validUntil === null => 'The valid until date is not a date.',
			$validUntil !== null && $validUntil <= $completed => 'The valid until date must be after the completed on date.',
			default => null,
		};

		$result = ['reason' => $reason, 'params' => [], 'learner' => $learner, 'record' => []];
		if ($reason !== null) {
			if (str_contains($reason, '{kinds}') === true) {
				$result['params'] = ['kinds' => implode(', ', self::KINDS)];
			}

			return $result;
		}

		$record = [
			'title' => (string)$title,
			'provider' => (string)$provider,
			'kind' => $kind,
			'completedAt' => $completed->format(DateTimeInterface::ATOM),
		];
		if ($validUntil !== null) {
			$record['validUntil'] = $validUntil->format(DateTimeInterface::ATOM);
		}

		foreach (['regulationSlug', 'evidenceNote'] as $optional) {
			$value = $this->text(value: $row[$optional] ?? null);
			if ($value !== null) {
				$record[$optional] = $value;
			}
		}

		$result['record'] = $record;
		return $result;
	}//end check()

	/**
	 * A date cell as a UTC midnight, or null.
	 *
	 * Reads 2026-09-15, 15-09-2026, 15/09/2026, 5-9-2026 and an ISO date-time
	 * (its date part). An impossible date such as 31-02-2026 is null.
	 *
	 * @param mixed $value The cell.
	 *
	 * @return DateTimeImmutable|null The date.
	 */
	private function date(mixed $value): ?DateTimeImmutable {
		$text = $this->text(value: $value);
		if ($text === null) {
			return null;
		}

		$parts = null;
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(T.*)?$/', $text, $match) === 1) {
			$parts = [(int)$match[1], (int)$match[2], (int)$match[3]];
		}

		if (preg_match('#^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$#', $text, $match) === 1) {
			$parts = [(int)$match[3], (int)$match[2], (int)$match[1]];
		}

		if ($parts === null || checkdate($parts[1], $parts[2], $parts[0]) === false) {
			return null;
		}

		return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $parts[0], $parts[1], $parts[2]), new DateTimeZone('UTC'));
	}//end date()

	/**
	 * A cell as trimmed text, or null when empty.
	 *
	 * @param mixed $value The cell.
	 *
	 * @return string|null The text.
	 */
	private function text(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end text()
}//end class
