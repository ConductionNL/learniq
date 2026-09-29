<?php

/**
 * Learniq Enrolment Forecast Reader
 *
 * The register reads behind the enrolment forecast: this year's groups with
 * a programme and programme year, programme names and lengths, placed
 * applications for the target year, approved subject choices and course
 * names. The forecast is for team leads and compliance officers (checked by
 * the controller) and shows counts only, so the reads skip row RBAC.
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
 * @spec openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Groups, programmes, applications and choices for the forecast.
 *
 * @spec openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */
class EnrolmentForecastReader {

	private const REGISTER = 'learniq';
	private const PLACED = ['placed', 'converted'];
	private const APPROVED = ['approved', 'locked'];

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
	 * This year's groups that name a programme and a programme year.
	 *
	 * @param string $academicYear The current school year.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
	 */
	public function currentGroups(string $academicYear): array {
		return array_values(
			array_filter(
				$this->read(schema: 'cohort', filters: ['academicYear' => $academicYear]),
				static fn (array $cohort): bool => (string)($cohort['academicYear'] ?? '') === $academicYear
					&& (string)($cohort['programmeId'] ?? '') !== ''
					&& (int)($cohort['programmeYear'] ?? 0) > 0
			)
		);
	}//end currentGroups()

	/**
	 * Programmes by id.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
	 */
	public function programmes(): array {
		return $this->byId(rows: $this->read(schema: 'programme', filters: []));
	}//end programmes()

	/**
	 * Programme length in years: the longest active hour plan, else the
	 * highest programme year a group is in.
	 *
	 * @param array<int, array<string, mixed>> $current This year's groups.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
	 */
	public function durations(array $current): array {
		$durations = [];
		foreach ($current as $group) {
			$programmeId = (string)$group['programmeId'];
			$durations[$programmeId] = max(($durations[$programmeId] ?? 0), (int)$group['programmeYear']);
		}

		foreach ($this->read(schema: 'hour-plan', filters: ['lifecycle' => 'active']) as $plan) {
			$programmeId = (string)($plan['programmeId'] ?? '');
			if ($programmeId !== '' && ($plan['lifecycle'] ?? '') === 'active') {
				$durations[$programmeId] = max(($durations[$programmeId] ?? 0), (int)($plan['durationYears'] ?? 0));
			}
		}

		return $durations;
	}//end durations()

	/**
	 * Placed applications per programme for rounds of the target year.
	 *
	 * @param string $targetYear The target school year.
	 *
	 * @return array<string, float> Count by programme.
	 *
	 * @spec openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
	 */
	public function placedApplications(string $targetYear): array {
		$rounds = [];
		foreach ($this->read(schema: 'admissions-round', filters: ['academicYear' => $targetYear]) as $round) {
			if ((string)($round['academicYear'] ?? '') === $targetYear) {
				$rounds[(string)($round['id'] ?? '')] = true;
			}
		}

		$counts = [];
		foreach ($this->read(schema: 'admission', filters: []) as $application) {
			$programmeId = (string)($application['programmeId'] ?? '');
			$inRound = isset($rounds[(string)($application['admissionsRoundId'] ?? '')]);
			if ($programmeId !== '' && $inRound === true && in_array($application['lifecycle'] ?? '', self::PLACED, true) === true) {
				$counts[$programmeId] = ($counts[$programmeId] ?? 0.0) + 1;
			}
		}

		return $counts;
	}//end placedApplications()

	/**
	 * Approved subject choices for the target year.
	 *
	 * @param string $targetYear The target school year.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
	 */
	public function approvedChoices(string $targetYear): array {
		return array_values(
			array_filter(
				$this->read(schema: 'subject-choice', filters: ['academicYear' => $targetYear]),
				static fn (array $choice): bool => (string)($choice['academicYear'] ?? '') === $targetYear
					&& in_array($choice['lifecycle'] ?? '', self::APPROVED, true) === true
			)
		);
	}//end approvedChoices()

	/**
	 * Course names by id.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#requirement-the-forecast-says-how-many-groups-are-needed
	 */
	public function courseNames(): array {
		$courses = $this->byId(rows: $this->read(schema: 'course', filters: []));

		return array_map(static fn (array $course): string => (string)($course['name'] ?? ''), $courses);
	}//end courseNames()

	/**
	 * Rows keyed by id.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function byId(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			$out[(string)($row['id'] ?? '')] = $row;
		}

		unset($out['']);

		return $out;
	}//end byId()

	/**
	 * Rows as arrays.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Field filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read(string $schema, array $filters): array {
		try {
			$rows = $this->objectService->findAll(['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters)], _rbac: false);
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
