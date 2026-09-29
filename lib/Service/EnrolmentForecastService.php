<?php

/**
 * Learniq Enrolment Forecast Service
 *
 * Forecasts next school year's learners per programme, programme year and
 * elective subject from what learniq already knows: this year's groups with
 * their programme year (change timetabling-multi-year-hour-plan), the
 * scenario's progression rates per programme year, the expected intake or
 * the placed applications, and the approved subject choices. Writes only the
 * scenario's own `result`; never a group, enrolment or choice.
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

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Next year's learners and groups from this year's groups and a scenario.
 *
 * @spec openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */
class EnrolmentForecastService {

	public const DEFAULT_RATES = ['upRate' => 0.9, 'repeatRate' => 0.05, 'leaveRate' => 0.05];
	private const TOLERANCE = 0.001;

	/**
	 * Constructor.
	 *
	 * @param EnrolmentForecastReader $reader Groups, programmes, applications and choices.
	 */
	public function __construct(
		private readonly EnrolmentForecastReader $reader,
	) {
	}//end __construct()

	/**
	 * Compute a scenario's forecast.
	 *
	 * @param array<string, mixed> $forecast The scenario.
	 *
	 * @return array<string, mixed> The result, with `computedAt`.
	 *
	 * @throws InvalidArgumentException When the target year is malformed or rates do not add up to one.
	 *
	 * @spec openspec/changes/timetabling-enrolment-forecast/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
	 */
	public function compute(array $forecast): array {
		$targetYear = (string)($forecast['targetYear'] ?? '');
		if (preg_match('/^(\d{4})-(\d{4})$/', $targetYear, $match) !== 1) {
			throw new InvalidArgumentException('The target year must look like 2027-2028.');
		}

		$currentYear = sprintf('%04d-%04d', (int)$match[1] - 1, (int)$match[1]);
		$programmes = $this->reader->programmes();
		$rates = $this->rates(forecast: $forecast, programmes: $programmes);
		$size = max(1, (int)($forecast['targetGroupSize'] ?? 28));

		$current = $this->reader->currentGroups(academicYear: $currentYear);
		$durations = $this->reader->durations(current: $current);
		$years = $this->progress(current: $current, rates: $rates, durations: $durations);
		$years = $this->addIntake(years: $years, forecast: $forecast, targetYear: $targetYear);

		return [
			'computedAt' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
			'scenario' => (string)($forecast['name'] ?? ''),
			'currentYear' => $currentYear,
			'targetYear' => $targetYear,
			'targetGroupSize' => $size,
			'programmeYears' => $this->yearRows(years: $years, programmes: $programmes, size: $size),
			'subjects' => $this->subjectRows(years: $years, current: $current, targetYear: $targetYear, programmes: $programmes, size: $size),
		];
	}//end compute()

	/**
	 * Rates per programme and year, checked to add up to one.
	 *
	 * @param array<string, mixed>                $forecast   The scenario.
	 * @param array<string, array<string, mixed>> $programmes Programmes by id.
	 *
	 * @return array<string, array{upRate: float, repeatRate: float, leaveRate: float}> Keyed `programmeId|programmeYear`.
	 *
	 * @throws InvalidArgumentException When a row does not add up to one.
	 */
	private function rates(array $forecast, array $programmes): array {
		$rates = [];
		foreach ((array)($forecast['rates'] ?? []) as $row) {
			$moveUp = (float)($row['upRate'] ?? 0);
			$repeat = (float)($row['repeatRate'] ?? 0);
			$leave = (float)($row['leaveRate'] ?? 0);
			$label = $this->label(programmes: $programmes, programmeId: (string)($row['programmeId'] ?? ''), programmeYear: (int)($row['programmeYear'] ?? 0));
			if (abs(($moveUp + $repeat + $leave) - 1.0) > self::TOLERANCE) {
				throw new InvalidArgumentException(sprintf('The rates for %s add up to %s, not 1.', $label, round($moveUp + $repeat + $leave, 3)));
			}

			$key = (string)($row['programmeId'] ?? '').'|'.(int)($row['programmeYear'] ?? 0);
			$rates[$key] = ['upRate' => $moveUp, 'repeatRate' => $repeat, 'leaveRate' => $leave];
		}

		return $rates;
	}//end rates()

	/**
	 * Next year's learners per programme year from this year's groups.
	 *
	 * @param array<int, array<string, mixed>>                                  $current   This year's groups.
	 * @param array<string, array{upRate: float, repeatRate: float, leaveRate: float}> $rates     Rates by key.
	 * @param array<string, int>                                                $durations Programme length in years by programme.
	 *
	 * @return array<string, array{programmeId: string, programmeYear: int, fromMovingUp: float, repeaters: float, intake: float}>
	 */
	private function progress(array $current, array $rates, array $durations): array {
		$years = [];
		foreach ($current as $group) {
			$programmeId = (string)$group['programmeId'];
			$year = (int)$group['programmeYear'];
			$count = count((array)($group['learnerIds'] ?? []));
			$rate = ($rates[$programmeId.'|'.$year] ?? self::DEFAULT_RATES);

			$this->add(years: $years, programmeId: $programmeId, programmeYear: $year, field: 'repeaters', amount: $count * $rate['repeatRate']);
			if ($year < ($durations[$programmeId] ?? $year)) {
				$this->add(years: $years, programmeId: $programmeId, programmeYear: $year + 1, field: 'fromMovingUp', amount: $count * $rate['upRate']);
			}
		}

		return $years;
	}//end progress()

	/**
	 * Add the first-year intake: the scenario's number, else the placed applications.
	 *
	 * @param array<string, array<string, mixed>> $years      The buckets.
	 * @param array<string, mixed>                $forecast   The scenario.
	 * @param string                              $targetYear The target school year.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function addIntake(array $years, array $forecast, string $targetYear): array {
		$intake = [];
		foreach ((array)($forecast['intake'] ?? []) as $row) {
			$intake[(string)($row['programmeId'] ?? '')] = (float)($row['expected'] ?? 0);
		}

		unset($intake['']);
		if ($intake === []) {
			$intake = $this->reader->placedApplications(targetYear: $targetYear);
		}

		foreach ($intake as $programmeId => $expected) {
			$this->add(years: $years, programmeId: (string)$programmeId, programmeYear: 1, field: 'intake', amount: (float)$expected);
		}

		return $years;
	}//end addIntake()

	/**
	 * The programme-year table.
	 *
	 * @param array<string, array<string, mixed>> $years      The buckets.
	 * @param array<string, array<string, mixed>> $programmes Programmes by id.
	 * @param int                                 $size       Target group size.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function yearRows(array $years, array $programmes, int $size): array {
		$rows = [];
		foreach ($years as $bucket) {
			$learners = (int)(round($bucket['fromMovingUp']) + round($bucket['repeaters']) + round($bucket['intake']));
			$rows[] = [
				'programmeId' => $bucket['programmeId'],
				'programmeName' => (string)($programmes[$bucket['programmeId']]['name'] ?? ''),
				'programmeYear' => $bucket['programmeYear'],
				'fromMovingUp' => (int)round($bucket['fromMovingUp']),
				'repeaters' => (int)round($bucket['repeaters']),
				'intake' => (int)round($bucket['intake']),
				'learners' => $learners,
				'groupsNeeded' => (int)ceil($learners / $size),
			];
		}

		usort(
			$rows,
			static fn (array $left, array $right): int => [$left['programmeName'], $left['programmeYear']]
				<=> [$right['programmeName'], $right['programmeYear']]
		);

		return $rows;
	}//end yearRows()

	/**
	 * The subject table: approved choices counted, the rest estimated from
	 * the share of each subject among the choices made so far.
	 *
	 * @param array<string, array<string, mixed>> $years      The buckets.
	 * @param array<int, array<string, mixed>>    $current    This year's groups.
	 * @param string                              $targetYear The target school year.
	 * @param array<string, array<string, mixed>> $programmes Programmes by id.
	 * @param int                                 $size       Target group size.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function subjectRows(array $years, array $current, string $targetYear, array $programmes, int $size): array {
		$nextYearOf = [];
		foreach ($current as $group) {
			foreach ((array)($group['learnerIds'] ?? []) as $learnerId) {
				$nextYearOf[(string)$learnerId] = (int)$group['programmeYear'] + 1;
			}
		}

		$chosen = [];
		foreach ($this->reader->approvedChoices(targetYear: $targetYear) as $choice) {
			$key = (string)($choice['programmeId'] ?? '').'|'.($nextYearOf[(string)($choice['learnerId'] ?? '')] ?? 0);
			$chosen[$key]['learners'] = ($chosen[$key]['learners'] ?? 0) + 1;
			foreach ((array)($choice['selectedElectiveCourseIds'] ?? []) as $courseId) {
				$chosen[$key]['courses'][(string)$courseId] = ($chosen[$key]['courses'][(string)$courseId] ?? 0) + 1;
			}
		}

		$courseNames = $this->reader->courseNames();
		$rows = [];
		foreach ($chosen as $key => $group) {
			[$programmeId, $year] = explode('|', (string)$key);
			$expected = 0;
			foreach ($this->yearRows(years: array_intersect_key($years, [$key => true]), programmes: $programmes, size: $size) as $row) {
				$expected = $row['learners'];
			}

			$missing = max(0, $expected - $group['learners']);
			foreach ((array)($group['courses'] ?? []) as $courseId => $counted) {
				$estimated = (int)round($missing * ($counted / max(1, $group['learners'])));
				$rows[] = [
					'programmeId' => $programmeId,
					'programmeName' => (string)($programmes[$programmeId]['name'] ?? ''),
					'programmeYear' => (int)$year,
					'courseId' => (string)$courseId,
					'courseName' => ($courseNames[(string)$courseId] ?? (string)$courseId),
					'counted' => $counted,
					'estimated' => $estimated,
					'learners' => $counted + $estimated,
					'isEstimated' => $estimated > 0,
					'groupsNeeded' => (int)ceil(($counted + $estimated) / $size),
				];
			}
		}//end foreach

		usort(
			$rows,
			static fn (array $left, array $right): int => [$left['programmeName'], $left['programmeYear'], $left['courseName']]
				<=> [$right['programmeName'], $right['programmeYear'], $right['courseName']]
		);

		return $rows;
	}//end subjectRows()

	/**
	 * Add learners to a programme year's bucket, creating it when missing.
	 *
	 * @param array<string, array<string, mixed>> $years         The buckets.
	 * @param string                              $programmeId   The programme.
	 * @param int                                 $programmeYear The programme year.
	 * @param string                              $field         `fromMovingUp`, `repeaters` or `intake`.
	 * @param float                               $amount        Learners to add.
	 *
	 * @return void
	 */
	private function add(array &$years, string $programmeId, int $programmeYear, string $field, float $amount): void {
		$key = $programmeId.'|'.$programmeYear;
		if (isset($years[$key]) === false) {
			$years[$key] = ['programmeId' => $programmeId, 'programmeYear' => $programmeYear, 'fromMovingUp' => 0.0, 'repeaters' => 0.0, 'intake' => 0.0];
		}

		$years[$key][$field] += $amount;
	}//end add()

	/**
	 * A readable name for a programme year, such as "havo 5".
	 *
	 * @param array<string, array<string, mixed>> $programmes    Programmes by id.
	 * @param string                              $programmeId   The programme.
	 * @param int                                 $programmeYear The year.
	 *
	 * @return string
	 */
	private function label(array $programmes, string $programmeId, int $programmeYear): string {
		$name = (string)($programmes[$programmeId]['name'] ?? $programmeId);

		return trim($name.' '.$programmeYear);
	}//end label()
}//end class
