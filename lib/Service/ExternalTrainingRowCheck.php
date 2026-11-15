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
		$fields = $this->fields(row: $row);
		$reason = ($this->fieldReason(fields: $fields) ?? $this->dateReason(fields: $fields, now: $now));

		$result = ['reason' => $reason, 'params' => [], 'learner' => $fields['learner'], 'record' => []];
		if ($reason !== null) {
			if (str_contains($reason, '{kinds}') === true) {
				$result['params'] = ['kinds' => implode(', ', self::KINDS)];
			}

			return $result;
		}

		$result['record'] = $this->record(fields: $fields, row: $row);
		return $result;
	}//end check()

	/**
	 * The cells of one row the checks read, as text and dates.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return array{learner:?string,title:?string,provider:?string,kind:string,completed:?DateTimeImmutable,validText:?string,validUntil:?DateTimeImmutable}
	 */
	private function fields(array $row): array {
		$validText = $this->text(value: $row['validUntil'] ?? null);

		return [
			'learner'    => $this->text(value: $row['learner'] ?? null),
			'title'      => $this->text(value: $row['title'] ?? null),
			'provider'   => $this->text(value: $row['provider'] ?? null),
			'kind'       => ($this->text(value: $row['kind'] ?? null) ?? 'classroom'),
			'completed'  => $this->date(value: $row['completedAt'] ?? null),
			'validText'  => $validText,
			'validUntil' => $this->date(value: $validText),
		];
	}//end fields()

	/**
	 * Why a required text field or the kind is refused, or null.
	 *
	 * @param array<string,mixed> $fields The row's fields.
	 *
	 * @return string|null The reason.
	 */
	private function fieldReason(array $fields): ?string {
		return match (true) {
			$fields['learner'] === null => 'The learner is missing.',
			$fields['title'] === null => 'The title is missing.',
			$fields['provider'] === null => 'The provider is missing.',
			in_array($fields['kind'], self::KINDS, true) === false => 'The kind must be one of: {kinds}.',
			default => null,
		};
	}//end fieldReason()

	/**
	 * Why the completion or expiry date is refused, or null.
	 *
	 * @param array<string,mixed> $fields The row's fields.
	 * @param DateTimeInterface   $now    Evaluation instant.
	 *
	 * @return string|null The reason.
	 */
	private function dateReason(array $fields, DateTimeInterface $now): ?string {
		$completed  = $fields['completed'];
		$validUntil = $fields['validUntil'];

		return match (true) {
			$completed === null => 'The completed on date is missing or not a date.',
			$completed > $now => 'The completed on date is in the future.',
			$fields['validText'] !== null && $validUntil === null => 'The valid until date is not a date.',
			$validUntil !== null && $validUntil <= $completed => 'The valid until date must be after the completed on date.',
			default => null,
		};
	}//end dateReason()

	/**
	 * The record fields of a valid row, without the learner.
	 *
	 * @param array<string,mixed> $fields The row's fields.
	 * @param array<string,mixed> $row    The row, for its optional texts.
	 *
	 * @return array<string,string> The record fields.
	 */
	private function record(array $fields, array $row): array {
		$record = [
			'title' => (string)$fields['title'],
			'provider' => (string)$fields['provider'],
			'kind' => $fields['kind'],
			'completedAt' => $fields['completed']->format(DateTimeInterface::ATOM),
		];
		if ($fields['validUntil'] !== null) {
			$record['validUntil'] = $fields['validUntil']->format(DateTimeInterface::ATOM);
		}

		return $record + $this->optionalTexts(row: $row);
	}//end record()

	/**
	 * The optional text cells of a row that are filled.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return array<string,string> The regulation slug and evidence note, when given.
	 */
	private function optionalTexts(array $row): array {
		$texts = [];
		foreach (['regulationSlug', 'evidenceNote'] as $optional) {
			$value = $this->text(value: $row[$optional] ?? null);
			if ($value !== null) {
				$texts[$optional] = $value;
			}
		}

		return $texts;
	}//end optionalTexts()

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

		$parts = $this->dateParts(text: $text);
		if ($parts === null || checkdate($parts[1], $parts[2], $parts[0]) === false) {
			return null;
		}

		return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $parts[0], $parts[1], $parts[2]), new DateTimeZone('UTC'));
	}//end date()

	/**
	 * The year, month and day a date cell writes, or null for another shape.
	 *
	 * @param string $text The trimmed cell.
	 *
	 * @return array{0:int,1:int,2:int}|null Year, month and day.
	 */
	private function dateParts(string $text): ?array {
		$parts = null;
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(T.*)?$/', $text, $match) === 1) {
			$parts = [(int)$match[1], (int)$match[2], (int)$match[3]];
		}

		if (preg_match('#^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$#', $text, $match) === 1) {
			$parts = [(int)$match[3], (int)$match[2], (int)$match[1]];
		}

		return $parts;
	}//end dateParts()

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
