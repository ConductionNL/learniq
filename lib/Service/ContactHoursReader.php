<?php

/**
 * Learniq Contact Hours Reader
 *
 * The register reads behind the contact hours report: the groups of a window,
 * the course names, a school year's report periods and a group's attendance
 * records. The report is for staff who may read every group (instructors,
 * team leads, compliance officers, checked by the controller), so the reads
 * skip row RBAC, as the attendance pages' own aggregates do.
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
 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Cohorts, courses, report periods and attendance for the report.
 *
 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */
class ContactHoursReader {

	private const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister objects.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The groups of the window's school years, or the one asked for.
	 *
	 * @param string      $from     First day (Y-m-d).
	 * @param string      $to       Last day (Y-m-d).
	 * @param string|null $cohortId One group, or null.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
	 */
	public function cohorts(string $from, string $to, ?string $cohortId): array {
		if ($cohortId !== null && $cohortId !== '') {
			return $this->read(schema: 'cohort', filters: [], ids: [$cohortId]);
		}

		$years = array_unique([$this->schoolYearOf(date: $from), $this->schoolYearOf(date: $to)]);
		$cohorts = [];
		foreach ($years as $year) {
			foreach ($this->read(schema: 'cohort', filters: ['academicYear' => $year]) as $cohort) {
				if ((string)($cohort['academicYear'] ?? '') === $year) {
					$cohorts[(string)($cohort['id'] ?? '')] = $cohort;
				}
			}
		}

		unset($cohorts['']);

		return array_values($cohorts);
	}//end cohorts()

	/**
	 * Course names by id.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
	 */
	public function courseNames(): array {
		$names = [];
		foreach ($this->read(schema: 'course', filters: []) as $course) {
			$names[(string)($course['id'] ?? '')] = (string)($course['name'] ?? ($course['code'] ?? ''));
		}

		unset($names['']);

		return $names;
	}//end courseNames()

	/**
	 * A school year's report periods.
	 *
	 * @param string $academicYear The school year (YYYY-YYYY).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
	 */
	public function reportPeriods(string $academicYear): array {
		return array_values(
			array_filter(
				$this->read(schema: 'report-period', filters: ['academicYear' => $academicYear]),
				static fn (array $period): bool => (string)($period['academicYear'] ?? '') === $academicYear
			)
		);
	}//end reportPeriods()

	/**
	 * A group's attendance records.
	 *
	 * @param string $cohortId The group.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-learner-who-attended-too-little-is-marked
	 */
	public function attendance(string $cohortId): array {
		return array_values(
			array_filter(
				$this->read(schema: 'attendance-record', filters: ['cohortId' => $cohortId]),
				static fn (array $record): bool => (string)($record['cohortId'] ?? '') === $cohortId
			)
		);
	}//end attendance()

	/**
	 * The school year a date falls in, counted from 1 August.
	 *
	 * @param string $date A date (Y-m-d).
	 *
	 * @return string YYYY-YYYY.
	 */
	private function schoolYearOf(string $date): string {
		$year = (int)substr($date, 0, 4);
		if ((int)substr($date, 5, 2) < 8) {
			$year--;
		}

		return sprintf('%04d-%04d', $year, $year + 1);
	}//end schoolYearOf()

	/**
	 * Rows as arrays.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Field filters.
	 * @param array<int, string>   $ids     Ids to read, or none.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read(string $schema, array $filters, array $ids=[]): array {
		$config = ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters)];
		if ($ids !== []) {
			$config['ids'] = $ids;
		}

		try {
			$rows = $this->objectService->findAll($config, _rbac: false);
		} catch (Throwable $exception) {
			unset($exception);
			return [];
		}

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
	}//end read()
}//end class
