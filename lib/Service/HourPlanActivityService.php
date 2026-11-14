<?php

/**
 * Learniq Hour Plan Activity Service
 *
 * The teaching activities a school year needs, derived from the hour plans:
 * for every cohort of that school year that follows a programme and knows its
 * programme year, the active plan of the programme for the intake year the
 * cohort started in, filtered on the cohort's programme year, with the
 * teachers assigned to that cohort and course. Nothing is stored; the list is
 * derived on every read. Learniq plans the education and never places an
 * activity in a week, a day or a room: that is the timetabling system's job,
 * whose timetable planninq stores (decision D10).
 *
 * The reads skip the caller's RBAC: the list is a derived report whose access
 * is decided by its callers (HourPlanController allows staff groups only; the
 * in-process query event answers another fleet app on the server).
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
 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\HourPlanActivityServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives the teaching activities of a school year from the hour plans.
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
 */
class HourPlanActivityService implements HourPlanActivityServiceInterface {

	private const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister object access.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The intake year of a cohort in its programme year: the school year it started.
	 *
	 * 2026-2027 in programme year 2 started in 2025-2026.
	 *
	 * @param string $academicYear  The school year, `YYYY-YYYY`.
	 * @param int    $programmeYear The cohort's year of its programme (1 or more).
	 *
	 * @return string|null The intake year, or null for an unreadable school year.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	public function intakeYearOf(string $academicYear, int $programmeYear): ?string {
		if (preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $match) !== 1 || $programmeYear < 1) {
			return null;
		}

		$start = (int)$match[1] - ($programmeYear - 1);
		return sprintf('%04d-%04d', $start, $start + 1);
	}//end intakeYearOf()

	/**
	 * The activities of a school year.
	 *
	 * @param string $academicYear The school year, `YYYY-YYYY`.
	 *
	 * @return array{academicYear:string,activities:array<int,array<string,mixed>>,cohortsWithoutPlan:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	public function forYear(string $academicYear): array {
		$year = ['academicYear' => $academicYear, 'activities' => [], 'withoutPlan' => [], 'plans' => [], 'courseNames' => []];
		foreach ($this->read(schema: 'cohort', filters: ['academicYear' => $academicYear]) as $cohort) {
			$year = $this->addCohort(year: $year, cohort: $cohort);
		}

		return [
			'academicYear' => $academicYear,
			'activities' => $this->withCourseNames(activities: $year['activities'], courseIds: array_keys($year['courseNames'])),
			'cohortsWithoutPlan' => $year['withoutPlan'],
		];
	}//end forYear()

	/**
	 * Add one cohort to the school year: its activities, or a row without a plan.
	 *
	 * A cohort without a programme, with an unreadable programme year or of
	 * another school year is skipped. Each plan is read once per programme
	 * and intake year.
	 *
	 * @param array<string,mixed> $year   The school year so far: academicYear, activities, withoutPlan, plans and courseNames.
	 * @param array<string,mixed> $cohort The cohort.
	 *
	 * @return array<string,mixed> The school year with the cohort added.
	 */
	private function addCohort(array $year, array $cohort): array {
		$academicYear = $year['academicYear'];
		$programmeId = (string)($cohort['programmeId'] ?? '');
		$programmeYear = (int)($cohort['programmeYear'] ?? 0);
		$intake = $this->intakeYearOf(academicYear: $academicYear, programmeYear: $programmeYear);
		if ($programmeId === '' || $intake === null || (string)($cohort['academicYear'] ?? '') !== $academicYear) {
			return $year;
		}

		$key = $programmeId . '|' . $intake;
		if (array_key_exists($key, $year['plans']) === false) {
			$year['plans'][$key] = $this->activePlan(programmeId: $programmeId, intakeYear: $intake);
		}

		$cohortRow = [
			'cohortId' => $this->idOf(row: $cohort),
			'cohortName' => (string)($cohort['name'] ?? ''),
			'programmeYear' => $programmeYear,
		];
		if ($year['plans'][$key] === null) {
			$year['withoutPlan'][] = array_merge($cohortRow, ['programmeId' => $programmeId, 'intakeYear' => $intake]);
			return $year;
		}

		return $this->addActivities(year: $year, cohort: ['row' => $cohortRow, 'plan' => $year['plans'][$key]]);
	}//end addCohort()

	/**
	 * Add the activities of one cohort's plan lines for its programme year.
	 *
	 * @param array<string,mixed>                                                    $year   The school year so far.
	 * @param array{row: array{cohortId: string, cohortName: string, programmeYear: int}, plan: array<string,mixed>} $cohort The cohort row and its plan.
	 *
	 * @return array<string,mixed> The school year with the activities added.
	 */
	private function addActivities(array $year, array $cohort): array {
		$cohortRow = $cohort['row'];
		$teachers = $this->teachersOf(cohortId: $cohortRow['cohortId']);
		foreach ($this->linesFor(plan: $cohort['plan'], programmeYear: $cohortRow['programmeYear']) as $line) {
			$courseId = (string)$line['courseId'];
			$year['courseNames'][$courseId] = true;
			$year['activities'][] = array_merge(
				$cohortRow,
				[
					'hourPlanId' => $this->idOf(row: $cohort['plan']),
					'courseId' => $courseId,
					'courseName' => '',
					'periodCode' => $line['periodCode'] ?? null,
					'contactHours' => (float)($line['contactHours'] ?? 0),
					'otherHours' => (float)($line['otherHours'] ?? 0),
					'activityKind' => (string)($line['activityKind'] ?? 'lesson'),
					'teacherIds' => ($teachers[$courseId] ?? []),
					'note' => $line['note'] ?? null,
				]
			);
		}

		return $year;
	}//end addActivities()

	/**
	 * The plan lines of one programme year.
	 *
	 * @param array<string,mixed> $plan          The hour plan.
	 * @param int                 $programmeYear The programme year.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function linesFor(array $plan, int $programmeYear): array {
		$lines = [];
		foreach ((array)($plan['lines'] ?? []) as $line) {
			if (is_array($line) === true && (int)($line['programmeYear'] ?? 0) === $programmeYear && (string)($line['courseId'] ?? '') !== '') {
				$lines[] = $line;
			}
		}

		return $lines;
	}//end linesFor()

	/**
	 * The active plan of a programme for an intake year, or null.
	 *
	 * @param string $programmeId The programme.
	 * @param string $intakeYear  The intake year.
	 *
	 * @return array<string,mixed>|null
	 */
	private function activePlan(string $programmeId, string $intakeYear): ?array {
		$filters = ['programmeId' => $programmeId, 'intakeYear' => $intakeYear];
		foreach ($this->read(schema: 'hour-plan', filters: $filters) as $plan) {
			if ($this->matches(row: $plan, filters: $filters) === true && ($plan['lifecycle'] ?? '') === 'active') {
				return $plan;
			}
		}

		return null;
	}//end activePlan()

	/**
	 * Whether a row carries every filter value, compared as strings, in filter order.
	 *
	 * @param array<string,mixed>  $row     The row.
	 * @param array<string,string> $filters The values by field.
	 *
	 * @return bool
	 */
	private function matches(array $row, array $filters): bool {
		foreach ($filters as $field => $value) {
			if ((string)($row[$field] ?? '') !== $value) {
				return false;
			}
		}

		return true;
	}//end matches()

	/**
	 * The teachers of a cohort per course, from SubjectTeacherAssignment.
	 *
	 * @param string $cohortId The cohort.
	 *
	 * @return array<string,array<int,string>> Teacher ids per course id.
	 */
	private function teachersOf(string $cohortId): array {
		$out = [];
		foreach ($this->read(schema: 'subjectteacherassignment', filters: ['cohortId' => $cohortId]) as $assignment) {
			$courseId = (string)($assignment['courseId'] ?? '');
			$teacherId = (string)($assignment['teacherId'] ?? '');
			if ($courseId !== '' && $teacherId !== '' && (string)($assignment['cohortId'] ?? '') === $cohortId) {
				$out[$courseId][] = $teacherId;
			}
		}

		return array_map(static fn (array $ids): array => array_values(array_unique($ids)), $out);
	}//end teachersOf()

	/**
	 * Fill each activity's course name in one read of the courses involved.
	 *
	 * @param array<int,array<string,mixed>> $activities The activities.
	 * @param array<int,string>              $courseIds  The course ids involved.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function withCourseNames(array $activities, array $courseIds): array {
		if ($courseIds === []) {
			return $activities;
		}

		$names = [];
		foreach ($this->readByIds(schema: 'course', ids: $courseIds) as $course) {
			$names[$this->idOf(row: $course)] = (string)($course['name'] ?? '');
		}

		foreach ($activities as $index => $activity) {
			$activities[$index]['courseName'] = ($names[$activity['courseId']] ?? '');
		}

		return $activities;
	}//end withCourseNames()

	/**
	 * Read learniq objects as plain arrays; a failing read yields none.
	 *
	 * @param string              $schema  Schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function read(string $schema, array $filters): array {
		return $this->rows(schema: $schema, config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters)]);
	}//end read()

	/**
	 * The rows of a schema with these ids (every row for no ids), as the system.
	 *
	 * @param string            $schema The schema.
	 * @param array<int,string> $ids    The ids.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function readByIds(string $schema, array $ids): array {
		$config = ['filters' => ['register' => self::REGISTER, 'schema' => $schema]];
		if ($ids !== []) {
			$config['ids'] = array_values($ids);
		}

		return $this->rows(schema: $schema, config: $config);
	}//end readByIds()

	/**
	 * The rows one read finds, as arrays; a read that fails is logged and finds none.
	 *
	 * @param string              $schema The schema, for the log.
	 * @param array<string,mixed> $config The read.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(string $schema, array $config): array {
		try {
			$rows = $this->objectService->findAll($config, _rbac: false);
		} catch (Throwable $exception) {
			$this->logger->warning('[HourPlanActivityService] Could not read {schema}: {msg}', ['schema' => $schema, 'msg' => $exception->getMessage()]);
			return [];
		}

		return $this->asRows(rows: $rows);
	}//end rows()

	/**
	 * The read results as arrays: an entity serialised, anything that is not a row dropped.
	 *
	 * @param array<int,mixed> $rows The read results.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function asRows(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = (array)$row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end asRows()

	/**
	 * The id of a row.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ''));
	}//end idOf()
}//end class
