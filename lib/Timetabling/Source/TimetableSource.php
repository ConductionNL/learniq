<?php

/**
 * Learniq Timetable Source
 *
 * Where learniq reads timetable sessions from. Planninq owns the school
 * timetable (decision D10), so when planninq is installed its lessons are the
 * source; without planninq, learniq's own `Session` schema is. Every reader
 * (my timetable, the cohort timetable, the conflict scan after an import)
 * asks {@see TimetableSourceResolver} for the current source and reads rows
 * in learniq's session shape, so none of them needs a second code path.
 *
 * Row shape: `id`, `title`, `startsAt`, `endsAt`, `location`, `cohortId`,
 * `lifecycle` and `source` (`learniq` or `planninq`), plus whatever the source
 * carries (a local Session keeps all its fields; a planninq lesson adds
 * `teacherUserId`, `roomReference`, `groupReference`, `externalRef`).
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

use RuntimeException;

/**
 * A place learniq reads timetable sessions from.
 *
 * @spec openspec/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
 */
interface TimetableSource {

	/**
	 * The source's name, carried on every row as `source`.
	 *
	 * @return string `learniq` or `planninq`.
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
	 */
	public function name(): string;

	/**
	 * The sessions of the given cohorts, optionally narrowed to a window.
	 *
	 * @param array<int,string> $cohortIds Cohort UUIDs.
	 * @param string|null       $from      ISO 8601 window start, or null.
	 * @param string|null       $to        ISO 8601 window end, or null.
	 *
	 * @return array<int,array<string,mixed>> Sessions in learniq's session shape.
	 *
	 * @throws RuntimeException When the source cannot answer.
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
	 */
	public function sessionsForCohorts(array $cohortIds, ?string $from, ?string $to): array;

	/**
	 * The sessions a teacher gives, when the source knows a lesson's teacher.
	 *
	 * @param string      $userId The teacher's Nextcloud user id.
	 * @param string|null $from   ISO 8601 window start, or null.
	 * @param string|null $to     ISO 8601 window end, or null.
	 *
	 * @return array<int,array<string,mixed>> Sessions in learniq's session shape.
	 *
	 * @throws RuntimeException When the source cannot answer.
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
	 */
	public function sessionsForTeacher(string $userId, ?string $from, ?string $to): array;

	/**
	 * The sessions of the given courses, for learners enrolled in a course
	 * without a cohort (an elective from a subject choice), when the source
	 * knows a lesson's course.
	 *
	 * @param array<int,string> $courseIds Course UUIDs.
	 * @param string|null       $from      ISO 8601 window start, or null.
	 * @param string|null       $to        ISO 8601 window end, or null.
	 *
	 * @return array<int,array<string,mixed>> Sessions in learniq's session shape.
	 *
	 * @throws RuntimeException When the source cannot answer.
	 *
	 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-elective-sessions-in-the-personal-timetable
	 */
	public function sessionsForCourses(array $courseIds, ?string $from, ?string $to): array;
}//end interface
