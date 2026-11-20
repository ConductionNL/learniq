<?php

/**
 * Learniq CertificateIssueOutcome
 *
 * What issuing a course day's certificates tells the trainer: why a
 * participant got none, the kept exam results per enrolment, and the summary
 * sentence.
 *
 * @category Service
 * @package  OCA\Learniq\Service\ExamRegistration
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
 * @spec openspec/specs/certification/spec.md#scenario-youssefs-birth-date
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\ExamRegistration;

use OCA\Learniq\Support\SerialisedRow;

/**
 * The refusals and the summary of a certificate issue.
 *
 * @spec openspec/specs/certification/spec.md#scenario-youssefs-birth-date
 */
class CertificateIssueOutcome {

	/**
	 * Why a participant is skipped, in a few words.
	 *
	 * @var array<string, string>
	 */
	private const REASONS = [
		'no-result' => 'geen uitslag',
		'failed' => 'niet geslaagd',
		'unsigned' => 'kon niet worden ondertekend',
	];

	/**
	 * "10 certificaten uitgegeven. Niet uitgegeven: Sanne de Wit (geen uitslag)."
	 *
	 * @param int                                       $issued  How many were issued.
	 * @param list<array{learnerName: string|null, reason: string}> $skipped Who was skipped.
	 * @param int                                       $already How many already held one.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-thursdays-f-gassen-day
	 */
	public function summary(int $issued, array $skipped, int $already): string {
		$noun = 'certificaten';
		if ($issued === 1) {
			$noun = 'certificaat';
		}

		$line = $issued . ' ' . $noun . ' uitgegeven.';
		if ($already > 0) {
			$line .= ' ' . $already . ' had al een certificaat.';
		}

		if ($skipped !== []) {
			$names = array_map(
				fn (array $row): string => (($row['learnerName'] ?? null) ?? 'Een deelnemer') . ' (' . self::REASONS[$row['reason']] . ')',
				$skipped
			);
			$line .= ' Niet uitgegeven: ' . implode(', ', $names) . '.';
		}

		return $line;
	}//end summary()

	/**
	 * The result line per enrolment, from the day's lists; a line with a result wins.
	 *
	 * @param list<array<string, mixed>> $lists The day's registration lists.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function results(array $lists): array {
		$results = [];
		foreach ($lists as $list) {
			foreach ((array)($list['lines'] ?? []) as $line) {
				$id = (string)($line['enrolmentId'] ?? '');
				if ($id === '' || ($line['result'] ?? null) === null) {
					continue;
				}

				$results[$id] = $line;
			}
		}

		return $results;
	}//end results()

	/**
	 * Why the participant gets no certificate, or null when they passed.
	 *
	 * With results kept on a list, only `pass` passes. A day whose lists hold
	 * no result at all reads the enrolment: completed passes.
	 *
	 * @param array<string, mixed>      $enrolment   The enrolment.
	 * @param array<string, mixed>|null $line        The participant's result line.
	 * @param bool                      $resultsKept Whether any list of the day holds a result.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function refusal(array $enrolment, ?array $line, bool $resultsKept): ?string {
		if ($line !== null) {
			if ($line['result'] === 'pass') {
				return null;
			}

			return 'failed';
		}

		if ($resultsKept === false && ($enrolment['lifecycle'] ?? null) === 'completed') {
			return null;
		}

		if (($enrolment['lifecycle'] ?? null) === 'failed') {
			return 'failed';
		}

		return 'no-result';
	}//end refusal()

	/**
	 * A skipped participant.
	 *
	 * @param string      $enrolmentId The enrolment.
	 * @param string|null $name        The participant's name.
	 * @param string      $reason      The reason key.
	 *
	 * @return array{enrolmentId: string, learnerName: string|null, reason: string, sentence: string}
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function skip(string $enrolmentId, ?string $name, string $reason): array {
		return [
			'enrolmentId' => $enrolmentId,
			'learnerName' => $name,
			'reason' => $reason,
			'sentence' => (($name ?? 'Een deelnemer') . ': ' . self::REASONS[$reason] . '.'),
		];
	}//end skip()

	/**
	 * The participant's name, from the list or the enrolment's readable copy.
	 *
	 * @param array<string, mixed>      $enrolment The enrolment.
	 * @param array<string, mixed>|null $line      The result line.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function nameOf(array $enrolment, ?array $line): ?string {
		$name = trim((string)($line['learnerName'] ?? ($enrolment['learnerName'] ?? '')));
		if ($name === '') {
			return null;
		}

		return $name;
	}//end nameOf()

	/**
	 * The id of a saved certificate, else the id it was saved under.
	 *
	 * @param mixed  $saved    What the store answered.
	 * @param string $fallback The id it was saved under.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function savedId(mixed $saved, string $fallback): string {
		$row = (new SerialisedRow())->asArray(object: $saved);

		return (string)($row['id'] ?? ($row['uuid'] ?? $fallback));
	}//end savedId()
}//end class
