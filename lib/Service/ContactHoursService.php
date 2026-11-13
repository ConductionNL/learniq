<?php

/**
 * Learniq Contact Hours Service
 *
 * Compares three numbers for a window: the contact hours a group was owed by
 * its hour plan, the hours it was given (lessons held, cancelled ones left
 * out) and, per learner, the hours attended. Owed comes from the hour plan
 * (HourPlanActivityService, change timetabling-multi-year-hour-plan), prorated
 * to the window; given comes from the timetable source (planninq when
 * installed, D10 and D25; learniq's own sessions otherwise); attended comes
 * from learniq's attendance records. Stores nothing.
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
 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\ContactHoursServiceInterface;
use OCA\Learniq\Timetabling\ContactHoursCalendar;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Owed, given and attended contact hours over a window.
 *
 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */
class ContactHoursService implements ContactHoursServiceInterface {

	public const MARGIN_KEY = 'contact_hours_margin_percent';
	private const ATTENDED = ['present', 'late', 'left-early'];

	/**
	 * Constructor.
	 *
	 * @param ContactHoursReader      $reader    Cohorts, periods, courses and attendance.
	 * @param HourPlanActivityService $plans     The hour plan lines per group.
	 * @param TimetableSourceResolver $sources   Planninq or learniq's own sessions.
	 * @param ContactHoursCalendar    $calendar  Teaching days and proration.
	 * @param IAppConfig              $appConfig The margin setting.
	 */
	public function __construct(
		private readonly ContactHoursReader $reader,
		private readonly HourPlanActivityService $plans,
		private readonly TimetableSourceResolver $sources,
		private readonly ContactHoursCalendar $calendar,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The report for a window, per group, course and learner.
	 *
	 * @param string      $from     First day (Y-m-d).
	 * @param string      $to       Last day (Y-m-d), inclusive.
	 * @param string|null $cohortId One group, or null for every group of the window's school years.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the timetable source cannot be read.
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
	 */
	public function forPeriod(string $from, string $to, ?string $cohortId=null): array {
		$margin = max(0, min(100, $this->appConfig->getValueInt('learniq', self::MARGIN_KEY, 10)));
		$cohorts = $this->reader->cohorts(from: $from, to: $to, cohortId: $cohortId);
		$courses = $this->reader->courseNames();

		$report = [];
		foreach ($cohorts as $cohort) {
			$report[] = $this->forCohort(cohort: $cohort, period: ['from' => $from, 'to' => $to, 'courses' => $courses, 'margin' => $margin]);
		}

		return ['from' => $from, 'to' => $to, 'marginPercent' => $margin, 'cohorts' => $report];
	}//end forPeriod()

	/**
	 * One group's courses and learners.
	 *
	 * @param array<string, mixed>                                                            $cohort The group.
	 * @param array{from: string, to: string, courses: array<string, string>, margin: int} $period First and last day, course names by id
	 *     and the margin in percent.
	 *
	 * @return array<string, mixed>
	 */
	private function forCohort(array $cohort, array $period): array {
		$hours = $this->cohortHours(cohort: $cohort, period: $period);
		$rows = $this->courseRows(hours: $hours);

		return [
			'cohortId' => $hours->cohortId,
			'cohortName' => (string)($cohort['name'] ?? ''),
			'hasHourPlan' => $hours->owed !== null,
			'courses' => $rows,
			'totals' => [
				'owed' => $this->sumOrNull(rows: $rows, field: 'owed'),
				'given' => round(array_sum(array_column($rows, 'given')), 2),
			],
			'learners' => $this->learnerRows(hours: $hours),
		];
	}//end forCohort()

	/**
	 * Read one group's owed, given and attended hours for the window.
	 *
	 * @param array<string, mixed>                                                            $cohort The group.
	 * @param array{from: string, to: string, courses: array<string, string>, margin: int} $period First and last day, course names by id
	 *     and the margin in percent.
	 *
	 * @return CohortContactHours
	 */
	private function cohortHours(array $cohort, array $period): CohortContactHours {
		$hours = new CohortContactHours();
		$hours->cohort = $cohort;
		$hours->cohortId = (string)($cohort['id'] ?? '');
		$hours->courseNames = $period['courses'];
		$hours->margin = $period['margin'];
		$hours->owed = $this->owed(cohort: $cohort, period: $period);
		[$given, $sessionCourses] = $this->given(cohortId: $hours->cohortId, period: $period);
		$hours->given = $given;
		$hours->attended = $this->attended(cohortId: $hours->cohortId, sessionCourses: $sessionCourses);

		return $hours;
	}//end cohortHours()

	/**
	 * One row per course the group was owed or given, by course name.
	 *
	 * @param CohortContactHours $hours The group's hours.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function courseRows(CohortContactHours $hours): array {
		$rows = [];
		foreach (array_unique(array_merge(array_keys($hours->owed ?? []), array_keys($hours->given))) as $courseId) {
			$rows[] = $this->courseRow(hours: $hours, courseId: (string)$courseId);
		}

		usort($rows, static fn (array $left, array $right): int => strcmp($left['courseName'], $right['courseName']));

		return $rows;
	}//end courseRows()

	/**
	 * One course's owed, given and difference.
	 *
	 * @param CohortContactHours $hours    The group's hours.
	 * @param string             $courseId The course.
	 *
	 * @return array<string, mixed>
	 */
	private function courseRow(CohortContactHours $hours, string $courseId): array {
		$given = ($hours->given[$courseId] ?? 0.0);
		$owedHours = $this->owedHours(hours: $hours, courseId: $courseId);
		$difference = $this->difference(owedHours: $owedHours, given: $given);

		return [
			'courseId' => $courseId,
			'courseName' => ($hours->courseNames[$courseId] ?? $courseId),
			'owed' => $owedHours,
			'given' => round($given, 2),
			'difference' => $difference,
			'short' => $difference !== null && $difference < 0,
		];
	}//end courseRow()

	/**
	 * A course's owed hours, rounded; null without a plan.
	 *
	 * @param CohortContactHours $hours    The group's hours.
	 * @param string             $courseId The course.
	 *
	 * @return float|null
	 */
	private function owedHours(CohortContactHours $hours, string $courseId): ?float {
		if ($hours->owed === null) {
			return null;
		}

		return round(($hours->owed[$courseId] ?? 0.0), 2);
	}//end owedHours()

	/**
	 * Given minus owed, rounded; null without owed hours.
	 *
	 * @param float|null $owedHours The owed hours, or null without a plan.
	 * @param float      $given     The given hours.
	 *
	 * @return float|null
	 */
	private function difference(?float $owedHours, float $given): ?float {
		if ($owedHours === null) {
			return null;
		}

		return round($given - $owedHours, 2);
	}//end difference()

	/**
	 * Owed hours per course, prorated to the window; null without an active plan.
	 *
	 * @param array<string, mixed>              $cohort The group.
	 * @param array{from: string, to: string} $period First and last day.
	 *
	 * @return array<string, float>|null
	 */
	private function owed(array $cohort, array $period): ?array {
		$academicYear = (string)($cohort['academicYear'] ?? '');
		$cohortId = (string)($cohort['id'] ?? '');
		$year = $this->plans->forYear(academicYear: $academicYear);
		$lines = array_values(array_filter($year['activities'] ?? [], static fn (array $row): bool => ($row['cohortId'] ?? '') === $cohortId));
		if ($lines === []) {
			return null;
		}

		$periods = $this->reader->reportPeriods(academicYear: $academicYear);
		$owed = [];
		foreach ($lines as $line) {
			$share = $this->calendar->share(
				periodCode: $line['periodCode'] ?? null,
				periods: $periods,
				academicYear: $academicYear,
				window: [$period['from'], $period['to']]
			);
			$courseId = (string)$line['courseId'];
			$owed[$courseId] = ($owed[$courseId] ?? 0.0) + ((float)$line['contactHours'] * $share);
		}

		return $owed;
	}//end owed()

	/**
	 * Given hours per course from the group's lessons that were not cancelled.
	 *
	 * @param string                                                     $cohortId The group.
	 * @param array{from: string, to: string, courses: array<string, string>} $period   First and last day, and course names by id.
	 *
	 * @return array{0: array<string, float>, 1: array<string, array{courseId: string, hours: float}>}
	 *     Hours by course, and each held lesson's course and hours.
	 */
	private function given(string $cohortId, array $period): array {
		['from' => $from, 'to' => $to] = $period;
		$byName = array_flip(array_map('mb_strtolower', $period['courses']));
		$rows = $this->sources->current()->sessionsForCohorts(cohortIds: [$cohortId], from: $from.'T00:00:00Z', to: $to.'T23:59:59Z');

		$given = [];
		$held = [];
		foreach ($rows as $row) {
			$day = substr((string)($row['startsAt'] ?? ''), 0, 10);
			if ($day < $from || $day > $to || ($row['lifecycle'] ?? '') === 'cancelled') {
				continue;
			}

			$courseId = (string)($row['courseId'] ?? '');
			if ($courseId === '') {
				$courseId = (string)($byName[mb_strtolower((string)($row['subject'] ?? ($row['title'] ?? '')))] ?? '');
			}

			if ($courseId === '') {
				continue;
			}

			$hours = $this->calendar->hours(startsAt: (string)($row['startsAt'] ?? ''), endsAt: (string)($row['endsAt'] ?? ''));
			$given[$courseId] = ($given[$courseId] ?? 0.0) + $hours;
			$held[(string)($row['id'] ?? '')] = ['courseId' => $courseId, 'hours' => $hours];
		}

		unset($held['']);

		return [$given, $held];
	}//end given()

	/**
	 * Attended hours per learner and course, from records on held lessons.
	 *
	 * @param string                                                 $cohortId       The group.
	 * @param array<string, array{courseId: string, hours: float}>  $sessionCourses Held lessons by id.
	 *
	 * @return array<string, array<string, float>> Hours by learner and course.
	 */
	private function attended(string $cohortId, array $sessionCourses): array {
		$attended = [];
		foreach ($this->reader->attendance(cohortId: $cohortId) as $record) {
			$lesson = ($sessionCourses[(string)($record['sessionId'] ?? '')] ?? null);
			if ($lesson === null || in_array($record['status'] ?? '', self::ATTENDED, true) === false) {
				continue;
			}

			$hours = (float)($record['lesuren'] ?? ((float)($record['minutesAttended'] ?? 0) / 60));
			$learnerId = (string)($record['learnerId'] ?? '');
			$attended[$learnerId][$lesson['courseId']] = ($attended[$learnerId][$lesson['courseId']] ?? 0.0) + $hours;
		}

		return $attended;
	}//end attended()

	/**
	 * Per learner of the group, attended against given per course.
	 *
	 * @param CohortContactHours $hours The group's hours.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function learnerRows(CohortContactHours $hours): array {
		$learners = [];
		foreach ((array)($hours->cohort['learnerIds'] ?? []) as $learnerId) {
			$courses = $this->learnerCourses(hours: $hours, learnerId: $learnerId);
			$learners[] = ['learnerId' => (string)$learnerId, 'courses' => $courses, 'below' => in_array(true, array_column($courses, 'below'), true)];
		}

		return $learners;
	}//end learnerRows()

	/**
	 * One learner's attended hours against the given hours of each course,
	 * marked below when under given minus the margin.
	 *
	 * @param CohortContactHours $hours     The group's hours.
	 * @param mixed              $learnerId The learner, as the group lists them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function learnerCourses(CohortContactHours $hours, mixed $learnerId): array {
		$courses = [];
		foreach ($hours->given as $courseId => $given) {
			$mine = round(($hours->attended[$learnerId][$courseId] ?? 0.0), 2);
			$courses[] = [
				'courseId' => (string)$courseId,
				'attended' => $mine,
				'given' => round($given, 2),
				'below' => $mine < round($given * (100 - $hours->margin) / 100, 2),
			];
		}

		return $courses;
	}//end learnerCourses()

	/**
	 * The sum of a column, or null when any row has no value.
	 *
	 * @param array<int, array<string, mixed>> $rows  The rows.
	 * @param string                           $field The column.
	 *
	 * @return float|null
	 */
	private function sumOrNull(array $rows, string $field): ?float {
		$values = array_column($rows, $field);
		if (in_array(null, $values, true) === true) {
			return null;
		}

		return round(array_sum($values), 2);
	}//end sumOrNull()
}//end class
