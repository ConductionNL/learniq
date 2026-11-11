<?php

/**
 * The case notes a migrated learniq data subject request carries in
 * OpenRegister's data-subject-requests register (D20, privacy-reuse-openregister-register).
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-privacy-requests-live-in-openregisters-data-subject-request-register
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

/**
 * Writes the notes of one migrated request: the marker line that names the
 * source, an unmapped kind, the description and the audit trail. Built by
 * MigrateDataSubjectRequestsToOpenRegister.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-privacy-requests-live-in-openregisters-data-subject-request-register
 */
final class DataSubjectRequestNotes {

	/**
	 * The case notes: the marker line, then the description and the audit trail.
	 *
	 * @param array<string, mixed> $row      The learniq request.
	 * @param string               $sourceId Its uuid.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-privacy-requests-live-in-openregisters-data-subject-request-register
	 */
	public function notesFor(array $row, string $sourceId): string {
		$lines = array_merge(
			[MigrateDataSubjectRequestsToOpenRegister::MARKER . $sourceId],
			$this->kindLines(row: $row),
			$this->descriptionLines(row: $row),
			$this->historyBlock(trail: ($row['auditTrail'] ?? []))
		);

		// OpenRegister caps notes at 4000 characters.
		return mb_substr(implode("\n", $lines), 0, 4000);
	}//end notesFor()

	/**
	 * The note line for a kind OpenRegister has no type for, if any.
	 *
	 * @param array<string, mixed> $row The learniq request.
	 *
	 * @return array<int, string>
	 */
	private function kindLines(array $row): array {
		$kind = (string)($row['kind'] ?? '');
		if ($kind !== '' && isset(MigrateDataSubjectRequestsToOpenRegister::KIND_TO_TYPE[$kind]) === false) {
			return ['Original kind: ' . $kind];
		}

		return [];
	}//end kindLines()

	/**
	 * A blank line and the trimmed description, when there is one.
	 *
	 * @param array<string, mixed> $row The learniq request.
	 *
	 * @return array<int, string>
	 */
	private function descriptionLines(array $row): array {
		$description = ($row['description'] ?? null);
		if (is_string($description) === true && trim($description) !== '') {
			return ['', trim($description)];
		}

		return [];
	}//end descriptionLines()

	/**
	 * A blank line, the `History:` heading and one line per entry, when the
	 * trail has any.
	 *
	 * @param mixed $trail The learniq `auditTrail` value.
	 *
	 * @return array<int, string>
	 */
	private function historyBlock(mixed $trail): array {
		$history = $this->historyLines(trail: $trail);
		if ($history === []) {
			return [];
		}

		return array_merge(['', 'History:'], $history);
	}//end historyBlock()

	/**
	 * One line per audit trail entry: when, who, what, and the note if any.
	 *
	 * @param mixed $trail The learniq `auditTrail` value.
	 *
	 * @return array<int, string>
	 */
	private function historyLines(mixed $trail): array {
		if (is_array($trail) === false) {
			return [];
		}

		$lines = [];
		foreach ($trail as $entry) {
			if (is_array($entry) === true) {
				$lines[] = $this->historyLine(entry: $entry);
			}
		}

		return $lines;
	}//end historyLines()

	/**
	 * The history line of one audit trail entry.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return string
	 */
	private function historyLine(array $entry): string {
		$line = '- ' . (string)($entry['recordedAt'] ?? '') . ' ' . (string)($entry['recordedBy'] ?? '')
			. ': ' . (string)($entry['action'] ?? '');
		$note = ($entry['note'] ?? null);
		if (is_string($note) === true && $note !== '') {
			$line .= ' (' . $note . ')';
		}

		return $line;
	}//end historyLine()
}//end class
