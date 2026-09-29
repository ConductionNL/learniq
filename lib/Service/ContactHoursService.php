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

use OCA\Learniq\Timetabling\ContactHoursCalendar;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Owed, given and attended contact hours over a window.
 *
 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */
class ContactHoursService {

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
			$report[] = $this->forCohort(cohort: $cohort, from: $from, to: $to, courses: $courses, margin: $margin);
		}

		return ['from' => $from, 'to' => $to, 'marginPercent' => $margin, 'cohorts' => $report];
	}//end forPeriod()

	/**
	 * One group's courses and learners.
	 *
	 * @param array<string, mixed>  $cohort  The group.
	 * @param string                $from    First day.
	 * @param string                $to      Last day.
	 * @param array<string, string> $courses Course names by id.
	 * @param int                   $margin  The margin in percent.
	 *
	 * @return array<string, mixed>
	 */
	private function forCohort(array $cohort, string $from, string $to, array $courses, int $margin): array {
		$cohortId = (string)($cohort['id'] ?? '');
		$owed = $this->owed(cohort: $cohort, from: $from, to: $to);
		[$given, $sessionCourses] = $this->given(cohortId: $cohortId, from: $from, to: $to, courses: $courses);
		$attended = $this->attended(cohortId: $cohortId, sessionCourses: $sessionCourses);

		$rows = [];
		foreach (array_unique(array_merge(array_keys($owed ?? []), array_keys($given))) as $courseId) {
			$rows[] = $this->courseRow(courseId: (string)$courseId, courses: $courses, owed: $owed, given: ($given[$courseId] ?? 0.0));
		}

		usort($rows, static fn (array $left, array $right): int => strcmp($left['courseName'], $right['courseName']));

		return [
			'cohortId' => $cohortId,
			'cohortName' => (string)($cohort['name'] ?? ''),
			'hasHourPlan' => $owed !== null,
			'courses' => $rows,
			'totals' => [
				'owed' => $this->sumOrNull(rows: $rows, field: 'owed'),
				'given' => round(array_sum(array_column($rows, 'given')), 2),
			],
			'learners' => $this->learnerRows(cohort: $cohort, attended: $attended, given: $given, margin: $margin),
		];
	}//end forCohort()

	/**
	 * One course's owed, given and difference.
	 *
	 * @param string                    $courseId The course.
	 * @param array<string, string>     $courses  Course names by id.
	 * @param array<string, float>|null $owed     Owed hours by course, or null without a plan.
	 * @param float                     $given    Given hours.
	 *
	 * @return array<string, mixed>
	 */
	private function courseRow(string $courseId, array $courses, ?array $owed, float $given): array {
		$owedHours = null;
		$difference = null;
		if ($owed !== null) {
			$owedHours = round(($owed[$courseId] ?? 0.0), 2);
			$difference = round($given - $owedHours, 2);
		}

		return [
			'courseId' => $courseId,
			'courseName' => ($courses[$courseId] ?? $courseId),
			'owed' => $owedHours,
			'given' => round($given, 2),
			'difference' => $difference,
			'short' => $difference !== null && $difference < 0,
		];
	}//end courseRow()

	/**
	 * Owed hours per course, prorated to the window; null without an active plan.
	 *
	 * @param array<string, mixed> $cohort The group.
	 * @param string               $from   First day.
	 * @param string               $to     Last day.
	 *
	 * @return array<string, float>|null
	 */
	private function owed(array $cohort, string $from, string $to): ?array {
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
			$share = $this->calendar->share(periodCode: $line['periodCode'] ?? null, periods: $periods, academicYear: $academicYear, from: $from, to: $to);
			$courseId = (string)$line['courseId'];
			$owed[$courseId] = ($owed[$courseId] ?? 0.0) + ((float)$line['contactHours'] * $share);
		}

		return $owed;
	}//end owed()

	/**
	 * Given hours per course from the group's lessons that were not cancelled.
	 *
	 * @param string                $cohortId The group.
	 * @param string                $from     First day.
	 * @param string                $to       Last day.
	 * @param array<string, string> $courses  Course names by id.
	 *
	 * @return array{0: array<string, float>, 1: array<string, array{courseId: string, hours: float}>}
	 *     Hours by course, and each held lesson's course and hours.
	 */
	private function given(string $cohortId, string $from, string $to, array $courses): array {
		$byName = array_flip(array_map('mb_strtolower', $courses));
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
	 * @param array<string, mixed>                $cohort   The group.
	 * @param array<string, array<string, float>> $attended Attended hours by learner and course.
	 * @param array<string, float>                $given    Given hours by course.
	 * @param int                                 $margin   The margin in percent.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function learnerRows(array $cohort, array $attended, array $given, int $margin): array {
		$learners = [];
		foreach ((array)($cohort['learnerIds'] ?? []) as $learnerId) {
			$courses = [];
			foreach ($given as $courseId => $hours) {
				$mine = round(($attended[$learnerId][$courseId] ?? 0.0), 2);
				$courses[] = [
					'courseId' => (string)$courseId,
					'attended' => $mine,
					'given' => round($hours, 2),
					'below' => $mine < round($hours * (100 - $margin) / 100, 2),
				];
			}

			$learners[] = ['learnerId' => (string)$learnerId, 'courses' => $courses, 'below' => in_array(true, array_column($courses, 'below'), true)];
		}

		return $learners;
	}//end learnerRows()

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
