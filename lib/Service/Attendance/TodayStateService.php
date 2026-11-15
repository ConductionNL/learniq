<?php

/**
 * Learniq Today State Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Today's state of every pupil of a group, read from the group's lessons of
 * the day, their marks and the absence reports that cover the day, through
 * the same reader the register uses. The pupil list's "Vandaag" column and
 * its "Absent today" count read it.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Attendance
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Attendance;

use DateTimeZone;

/**
 * Today's state per pupil of a group.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */
class TodayStateService {

	/**
	 * The states that count as absent today.
	 *
	 * @var array<int, string>
	 */
	public const ABSENT_STATES = [TodayState::REPORTED_ILL, TodayState::APPOINTMENT, TodayState::REPORTED_ABSENT, TodayState::ABSENT];

	/**
	 * Constructor.
	 *
	 * @param RollCallReader $reader Reads lessons, marks and reports.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RollCallReader $reader,
	) {
	}//end __construct()

	/**
	 * Every pupil of the group with their state today, keyed by pupil.
	 *
	 * @param array<string, mixed> $cohort The group, with its learnerIds.
	 * @param string               $date   The day, `Y-m-d`.
	 * @param DateTimeZone         $zone   The school's time zone.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
	 */
	public function forCohort(array $cohort, string $date, DateTimeZone $zone): array {
		$learnerIds = array_values(array_filter((array)($cohort['learnerIds'] ?? []), static fn ($id): bool => is_string($id) === true && $id !== ''));
		$cohortId = (string)($cohort['id'] ?? ($cohort['uuid'] ?? ''));
		if ($learnerIds === [] || $cohortId === '') {
			return [];
		}

		$marks = [];
		foreach ($this->reader->sessionsOn(cohortId: $cohortId, date: $date, zone: $zone) as $session) {
			foreach ($this->reader->recordsOf(sessionId: self::idOf(row: $session)) as $learnerId => $mark) {
				$marks[(string)$learnerId][] = $mark;
			}
		}

		$reports = $this->reader->reportsOn(learnerIds: $learnerIds, date: $date);

		$states = [];
		foreach ($learnerIds as $learnerId) {
			$states[$learnerId] = TodayState::of(marks: ($marks[$learnerId] ?? []), report: ($reports[$learnerId] ?? null));
		}

		return $states;
	}//end forCohort()

	/**
	 * How many pupils of a state list are absent today.
	 *
	 * @param array<string, string> $states The states, keyed by pupil.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
	 */
	public static function absentCount(array $states): int {
		return count(array_filter($states, static fn (string $state): bool => in_array($state, self::ABSENT_STATES, true)));
	}//end absentCount()

	/**
	 * A row's id, or null without one.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string|null
	 */
	private static function idOf(array $row): ?string {
		$id = ($row['id'] ?? ($row['uuid'] ?? null));
		if (is_string($id) === false || $id === '') {
			return null;
		}

		return $id;
	}//end idOf()
}//end class
