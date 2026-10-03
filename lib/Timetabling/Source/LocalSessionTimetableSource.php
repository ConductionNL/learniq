<?php

/**
 * Learniq Local Session Timetable Source
 *
 * Learniq's own `Session` schema as a timetable source: the fallback for a
 * school without planninq, and the home of sessions a teacher creates by hand.
 * Reads go through OpenRegister's ObjectService with multitenancy on and the
 * caller's RBAC off: `Session` is readable by staff groups only
 * (timetabling-visibility-rules), so learners read their lessons through
 * learniq's timetable endpoints, which decide access before they ask this
 * source (cohort membership for "My timetable", an RBAC read of the cohort
 * for the cohort page, the school's visibility policy for other timetables).
 * Sessions are fetched per cohort with an equality
 * filter, so no other cohort's session is ever loaded. The window is applied
 * by the caller ({@see \OCA\Learniq\Service\TimetableProjector}), because the
 * same rows also back the same-day changes list, whatever their start time.
 *
 * @category Timetabling
 * @package  OCA\Learniq\Timetabling\Source
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
 * @spec openspec/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling\Source;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads timetable sessions from learniq's own Session schema.
 *
 * @spec openspec/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
 */
class LocalSessionTimetableSource implements TimetableSource {

	public const NAME = 'learniq';

	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object query service (RBAC-scoped).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The source's name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
	 */
	public function name(): string {
		return self::NAME;
	}//end name()

	/**
	 * Every Session of the given cohorts. The window is left to the caller.
	 *
	 * @param array<int,string> $cohortIds Cohort UUIDs.
	 * @param string|null       $from      Unused: the caller windows the rows.
	 * @param string|null       $to        Unused: the caller windows the rows.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
	 */
	public function sessionsForCohorts(array $cohortIds, ?string $from, ?string $to): array {
		unset($from, $to);

		$rows = [];
		foreach (array_unique($cohortIds) as $cohortId) {
			if ($cohortId === '') {
				continue;
			}

			$results = $this->objectService->findAll(
				[
					'filters' => [
						'register' => self::LEARNIQ_REGISTER,
						'schema' => 'session',
						'cohortId' => $cohortId,
					],
					'sort' => ['startsAt' => 'ASC'],
				],
				_rbac: false
			);

			foreach ($results as $row) {
				$data = $this->toArray(row: $row);
				// Defensive: never let a mismatched cohort through.
				if ((string)($data['cohortId'] ?? '') !== $cohortId) {
					continue;
				}

				$data['source'] = self::NAME;
				$rows[] = $data;
			}
		}//end foreach

		return $rows;
	}//end sessionsForCohorts()

	/**
	 * The Sessions the caller covers as substitute teacher (learniq#1134).
	 *
	 * A learniq Session's regular teachers come through its cohort, which the
	 * caller already reads. A substitute is often neither a teacher nor a
	 * learner of the cohort they cover, so those lessons are read here on
	 * `substituteTeacherId` (declared on Session). Only the covered lessons
	 * load, never the rest of that cohort's timetable. Each is marked
	 * `cover: true`. The window is left to the caller.
	 *
	 * @param string      $userId The caller's Nextcloud user id.
	 * @param string|null $from   Unused: the caller windows the rows.
	 * @param string|null $to     Unused: the caller windows the rows.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-substitute-teacher-sees-the-lessons-they-cover
	 */
	public function sessionsForTeacher(string $userId, ?string $from, ?string $to): array {
		unset($from, $to);

		if ($userId === '') {
			return [];
		}

		$results = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => 'session',
					'substituteTeacherId' => $userId,
				],
				'sort' => ['startsAt' => 'ASC'],
			],
			_rbac: false
		);

		$rows = [];
		foreach ($results as $row) {
			$data = $this->toArray(row: $row);
			// Defensive: never show a lesson another teacher covers.
			if ((string)($data['substituteTeacherId'] ?? '') !== $userId) {
				continue;
			}

			$data['cover'] = true;
			$data['source'] = self::NAME;
			$rows[] = $data;
		}

		return $rows;
	}//end sessionsForTeacher()

	/**
	 * The sessions of the given courses, from learniq's own `Session` objects.
	 *
	 * @param array<int,string> $courseIds Course UUIDs.
	 * @param string|null       $from      ISO 8601 window start, or null (the projector windows).
	 * @param string|null       $to        ISO 8601 window end, or null (the projector windows).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-elective-sessions-in-the-personal-timetable
	 */
	public function sessionsForCourses(array $courseIds, ?string $from, ?string $to): array {
		unset($from, $to);

		$rows = [];
		foreach (array_unique($courseIds) as $courseId) {
			if ($courseId === '') {
				continue;
			}

			$results = $this->objectService->findAll(
				[
					'filters' => [
						'register' => self::LEARNIQ_REGISTER,
						'schema' => 'session',
						'courseId' => $courseId,
					],
					'sort' => ['startsAt' => 'ASC'],
				],
				_rbac: false
			);

			foreach ($results as $row) {
				$data = $this->toArray(row: $row);
				// Defensive: never let another course's lesson through.
				if ((string)($data['courseId'] ?? '') !== $courseId) {
					continue;
				}

				$data['source'] = self::NAME;
				$rows[] = $data;
			}
		}//end foreach

		return $rows;
	}//end sessionsForCourses()

	/**
	 * Normalise an ObjectService row (entity or array) to a plain array.
	 *
	 * @param mixed $row The row returned by ObjectService::findAll.
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()
}//end class
