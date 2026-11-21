<?php

/**
 * Learniq Own Timetable Cohorts
 *
 * The cohorts a user learns or teaches in, as the timetable visibility rules read them.
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * A user's own cohorts: listed as teacher or learner, or enrolled.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
 */
final class OwnTimetableCohorts {

	/**
	 * Constructor.
	 *
	 * @param TimetableDirectory $directory The timetable directory.
	 */
	public function __construct(
		private readonly TimetableDirectory $directory,
	) {
	}//end __construct()

	/**
	 * The cohorts the caller learns or teaches in, keyed by id.
	 *
	 * @param string $uid The caller.
	 *
	 * @return array<string,array<string,mixed>>
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	public function forUser(string $uid): array {
		$cohorts  = $this->directory->cohorts();
		$enrolled = $this->enrolledCohortIds(uid: $uid);
		$own      = [];
		foreach ($cohorts as $cohort) {
			$id     = $this->directory->idOf(row: $cohort);
			$member = $this->teachesOrLearnsIn(uid: $uid, cohort: $cohort) || isset($enrolled[$id]);
			if ($id !== '' && $member === true) {
				$own[$id] = $cohort;
			}
		}

		return $own;
	}//end forUser()

	/**
	 * The cohort ids the caller's own enrolments point at, as a set.
	 *
	 * @param string $uid The caller.
	 *
	 * @return array<string,true>
	 */
	private function enrolledCohortIds(string $uid): array {
		$enrolled = [];
		foreach ($this->directory->enrolmentsOf(uid: $uid) as $enrolment) {
			if ((string)($enrolment['learnerId'] ?? '') === $uid && (string)($enrolment['cohortId'] ?? '') !== '') {
				$enrolled[(string)$enrolment['cohortId']] = true;
			}
		}

		return $enrolled;
	}//end enrolledCohortIds()

	/**
	 * Whether the caller is listed as a teacher or a learner of the cohort.
	 *
	 * @param string              $uid    The caller.
	 * @param array<string,mixed> $cohort The cohort row.
	 *
	 * @return bool
	 */
	private function teachesOrLearnsIn(string $uid, array $cohort): bool {
		return in_array($uid, (array)($cohort['teacherIds'] ?? []), true)
			|| in_array($uid, (array)($cohort['learnerIds'] ?? []), true);
	}//end teachesOrLearnsIn()
}//end class
