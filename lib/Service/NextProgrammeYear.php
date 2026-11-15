<?php

/**
 * Learniq Next Programme Year
 *
 * The year of its programme a cohort moves up to at rollover.
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
 * @spec openspec/specs/school-structure/spec.md#requirement-a-cohort-knows-which-year-of-its-programme-it-is-in
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * The to-year cohort's year of its programme.
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-a-cohort-knows-which-year-of-its-programme-it-is-in
 */
final class NextProgrammeYear {

	/**
	 * The to-year cohort's year of its programme: one more than the from-year
	 * cohort's, when the group stays in the same programme. A group that moves
	 * to another programme, or whose year is not known, gets none.
	 *
	 * @param array<string,mixed> $fromCohort The from-year cohort.
	 * @param array<string,mixed> $mapping    The promote mapping.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-cohort-knows-which-year-of-its-programme-it-is-in
	 */
	public function forMapping(array $fromCohort, array $mapping): ?int {
		$year = self::programmeYearOf(cohort: $fromCohort);
		if ($year === null || self::movesProgramme(fromCohort: $fromCohort, mapping: $mapping) === true) {
			return null;
		}

		return ($year + 1);
	}//end forMapping()

	/**
	 * The cohort's year of its programme, from a whole number or a string of digits; null otherwise.
	 *
	 * @param array<string,mixed> $cohort The cohort.
	 *
	 * @return int|null
	 */
	private static function programmeYearOf(array $cohort): ?int {
		$year = $cohort['programmeYear'] ?? null;
		if (is_int($year) === false && (is_string($year) === false || ctype_digit($year) === false)) {
			return null;
		}

		return (int)$year;
	}//end programmeYearOf()

	/**
	 * Whether the promote mapping names a programme other than the from-year cohort's.
	 *
	 * @param array<string,mixed> $fromCohort The from-year cohort.
	 * @param array<string,mixed> $mapping    The promote mapping.
	 *
	 * @return bool
	 */
	private static function movesProgramme(array $fromCohort, array $mapping): bool {
		$toProgramme = (string)($mapping['toProgrammeId'] ?? '');
		return $toProgramme !== '' && $toProgramme !== (string)($fromCohort['programmeId'] ?? '');
	}//end movesProgramme()
}//end class
