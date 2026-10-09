<?php

/**
 * Learniq AbsenceReportDefaults
 *
 * What an absence report that leaves the end day or the note out gets: the
 * first day as the last (a one-day absence), and the words of its kind as
 * the note. The guardian's form asks one "Wanneer?" question and makes the
 * note optional (board wilgenboom MobielDetail). Stored in the school's own
 * language, like every readable copy.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/trainer-returns-hours-with-a-question/specs/attendance/spec.md#requirement-an-absence-is-reported-with-one-question-and-an-optional-note
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Fills the end day and the note of an absence report that has none.
 *
 * @spec openspec/changes/trainer-returns-hours-with-a-question/specs/attendance/spec.md#requirement-an-absence-is-reported-with-one-question-and-an-optional-note
 */
class AbsenceReportDefaults {

	/**
	 * The note a report without one gets, by its kind.
	 */
	public const REASON_WORDS = [
		'illness' => 'Ziek',
		'medical-appointment' => 'Naar de dokter of tandarts',
		'family-circumstance' => 'Familieomstandigheden',
		'religious-observance' => 'Religieuze feestdag',
		'bereavement' => 'Begrafenis',
		'other' => 'Andere reden',
	];

	/**
	 * The values a report gets where it says nothing.
	 *
	 * @param array<string, mixed> $payload The report being written.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/trainer-returns-hours-with-a-question/specs/attendance/spec.md#requirement-an-absence-is-reported-with-one-question-and-an-optional-note
	 */
	public function fill(array $payload): array {
		$defaults = [];
		$from = ($payload['dateFrom'] ?? null);
		if (is_string($from) === true && $from !== '' && (string)($payload['dateTo'] ?? '') === '') {
			$defaults['dateTo'] = $from;
		}

		if (trim((string)($payload['reason'] ?? '')) === '') {
			$defaults['reason'] = (self::REASON_WORDS[(string)($payload['reasonKind'] ?? '')] ?? self::REASON_WORDS['other']);
		}

		return $defaults;
	}//end fill()
}//end class
