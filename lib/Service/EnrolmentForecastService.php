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
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Next year's learners and groups from this year's groups and a scenario.
 *
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
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
	 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
	 */
	public function compute(array $forecast): array {
		$run = $this->newRun(forecast: $forecast);
		$this->project(run: $run);

		return [
			'computedAt' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
			'scenario' => (string)($forecast['name'] ?? ''),
			'currentYear' => $run->currentYear,
			'targetYear' => $run->targetYear,
			'targetGroupSize' => $run->size,
			'programmeYears' => $this->yearRows(run: $run, years: $run->years),
			'subjects' => $this->subjectRows(run: $run),
		];
	}//end compute()

	/**
	 * Check the scenario's target year and read what the forecast starts from:
	 * the programmes, the rates and the group size.
	 *
	 * @param array<string, mixed> $forecast The scenario.
	 *
	 * @return EnrolmentForecastRun
	 *
	 * @throws InvalidArgumentException When the target year is malformed or rates do not add up to one.
	 */
	private function newRun(array $forecast): EnrolmentForecastRun {
		$targetYear = (string)($forecast['targetYear'] ?? '');
		if (preg_match('/^(\d{4})-(\d{4})$/', $targetYear, $match) !== 1) {
			throw new InvalidArgumentException('The target year must look like 2027-2028.');
		}

		$run = new EnrolmentForecastRun();
		$run->forecast = $forecast;
		$run->targetYear = $targetYear;
		$run->currentYear = sprintf('%04d-%04d', (int)$match[1] - 1, (int)$match[1]);
		$run->programmes = $this->reader->programmes();
		$run->rates = $this->rates(run: $run);
		$run->size = max(1, (int)($forecast['targetGroupSize'] ?? 28));

		return $run;
	}//end newRun()

	/**
	 * Next year's buckets and choices from this year's groups.
	 *
	 * @param EnrolmentForecastRun $run The forecast being computed.
	 *
	 * @return void
	 */
	private function project(EnrolmentForecastRun $run): void {
		$run->current = $this->reader->currentGroups(academicYear: $run->currentYear);
		$durations = $this->reader->durations(current: $run->current);
		$run->years = $this->progress(run: $run, durations: $durations);
		$this->addIntake(run: $run);
		$run->chosen = $this->chosenPerGroup(current: $run->current, targetYear: $run->targetYear);
	}//end project()

	/**
	 * Rates per programme and year, checked to add up to one.
	 *
	 * @param EnrolmentForecastRun $run The forecast being computed: its scenario and programmes.
	 *
	 * @return array<string, array{upRate: float, repeatRate: float, leaveRate: float}> Keyed `programmeId|programmeYear`.
	 *
	 * @throws InvalidArgumentException When a row does not add up to one.
	 */
	private function rates(EnrolmentForecastRun $run): array {
		$rates = [];
		foreach ((array)($run->forecast['rates'] ?? []) as $row) {
			$moveUp = (float)($row['upRate'] ?? 0);
			$repeat = (float)($row['repeatRate'] ?? 0);
			$leave = (float)($row['leaveRate'] ?? 0);
			$label = $this->label(run: $run, row: $row);
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
	 * @param EnrolmentForecastRun $run       The forecast being computed: this year's groups and the rates.
	 * @param array<string, int>   $durations Programme length in years by programme.
	 *
	 * @return array<string, array{programmeId: string, programmeYear: int, fromMovingUp: float, repeaters: float, intake: float}>
	 */
	private function progress(EnrolmentForecastRun $run, array $durations): array {
		$years = [];
		foreach ($run->current as $group) {
			$programmeId = (string)$group['programmeId'];
			$year = (int)$group['programmeYear'];
			$count = count((array)($group['learnerIds'] ?? []));
			$rate = ($run->rates[$programmeId.'|'.$year] ?? self::DEFAULT_RATES);

			$this->add(years: $years, slot: [$programmeId, $year], field: 'repeaters', amount: $count * $rate['repeatRate']);
			if ($year < ($durations[$programmeId] ?? $year)) {
				$this->add(years: $years, slot: [$programmeId, $year + 1], field: 'fromMovingUp', amount: $count * $rate['upRate']);
			}
		}

		return $years;
	}//end progress()

	/**
	 * Add the first-year intake to next year's buckets: the scenario's number,
	 * else the placed applications.
	 *
	 * @param EnrolmentForecastRun $run The forecast being computed.
	 *
	 * @return void
	 */
	private function addIntake(EnrolmentForecastRun $run): void {
		$intake = [];
		foreach ((array)($run->forecast['intake'] ?? []) as $row) {
			$intake[(string)($row['programmeId'] ?? '')] = (float)($row['expected'] ?? 0);
		}

		unset($intake['']);
		if ($intake === []) {
			$intake = $this->reader->placedApplications(targetYear: $run->targetYear);
		}

		foreach ($intake as $programmeId => $expected) {
			$this->add(years: $run->years, slot: [(string)$programmeId, 1], field: 'intake', amount: (float)$expected);
		}
	}//end addIntake()

	/**
	 * The programme-year table.
	 *
	 * @param EnrolmentForecastRun                $run   The forecast being computed: its programmes and group size.
	 * @param array<string, array<string, mixed>> $years The buckets to list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function yearRows(EnrolmentForecastRun $run, array $years): array {
		$rows = [];
		foreach ($years as $bucket) {
			$rows[] = $this->yearRow(run: $run, bucket: $bucket);
		}

		usort(
			$rows,
			static fn (array $left, array $right): int => [$left['programmeName'], $left['programmeYear']]
				<=> [$right['programmeName'], $right['programmeYear']]
		);

		return $rows;
	}//end yearRows()

	/**
	 * One programme year's row: its learners rounded per source, and the groups they fill.
	 *
	 * @param EnrolmentForecastRun $run    The forecast being computed: its programmes and group size.
	 * @param array<string, mixed> $bucket One programme year's bucket.
	 *
	 * @return array<string, mixed>
	 */
	private function yearRow(EnrolmentForecastRun $run, array $bucket): array {
		$learners = (int)(round($bucket['fromMovingUp']) + round($bucket['repeaters']) + round($bucket['intake']));

		return [
			'programmeId' => $bucket['programmeId'],
			'programmeName' => (string)($run->programmes[$bucket['programmeId']]['name'] ?? ''),
			'programmeYear' => $bucket['programmeYear'],
			'fromMovingUp' => (int)round($bucket['fromMovingUp']),
			'repeaters' => (int)round($bucket['repeaters']),
			'intake' => (int)round($bucket['intake']),
			'learners' => $learners,
			'groupsNeeded' => (int)ceil($learners / $run->size),
		];
	}//end yearRow()

	/**
	 * The approved choices counted per next year's programme year: learners
	 * who chose, and how often each subject was chosen.
	 *
	 * @param array<int, array<string, mixed>> $current    This year's groups.
	 * @param string                           $targetYear The target school year.
	 *
	 * @return array<string, array{learners: int, courses?: array<string, int>}> Keyed `programmeId|programmeYear`.
	 */
	private function chosenPerGroup(array $current, string $targetYear): array {
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

		return $chosen;
	}//end chosenPerGroup()

	/**
	 * The subject table: approved choices counted, the rest estimated from
	 * the share of each subject among the choices made so far.
	 *
	 * @param EnrolmentForecastRun $run The forecast being computed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function subjectRows(EnrolmentForecastRun $run): array {
		$run->courseNames = $this->reader->courseNames();
		$rows = [];
		foreach (array_keys($run->chosen) as $key) {
			array_push($rows, ...$this->groupSubjectRows(run: $run, key: (string)$key));
		}

		usort(
			$rows,
			static fn (array $left, array $right): int => [$left['programmeName'], $left['programmeYear'], $left['courseName']]
				<=> [$right['programmeName'], $right['programmeYear'], $right['courseName']]
		);

		return $rows;
	}//end subjectRows()

	/**
	 * The subject rows of one next year's programme year, one per chosen subject.
	 *
	 * @param EnrolmentForecastRun $run The forecast being computed.
	 * @param string               $key The programme year, `programmeId|programmeYear`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function groupSubjectRows(EnrolmentForecastRun $run, string $key): array {
		$programme = $this->programmeColumns(run: $run, key: $key);
		$missing = $this->missingChoices(run: $run, key: $key);
		$rows = [];
		foreach ((array)($run->chosen[$key]['courses'] ?? []) as $courseId => $counted) {
			$row = $programme + [
				'courseId' => (string)$courseId,
				'courseName' => ($run->courseNames[(string)$courseId] ?? (string)$courseId),
				'counted' => $counted,
				'estimated' => (int)round($missing * ($counted / max(1, $run->chosen[$key]['learners']))),
			];
			$rows[] = $row + $this->subjectTotals(row: $row, size: $run->size);
		}

		return $rows;
	}//end groupSubjectRows()

	/**
	 * The programme columns of a subject row.
	 *
	 * @param EnrolmentForecastRun $run The forecast being computed: its programmes.
	 * @param string               $key The programme year, `programmeId|programmeYear`.
	 *
	 * @return array{programmeId: string, programmeName: string, programmeYear: int}
	 */
	private function programmeColumns(EnrolmentForecastRun $run, string $key): array {
		[$programmeId, $year] = explode('|', $key);

		return [
			'programmeId' => $programmeId,
			'programmeName' => (string)($run->programmes[$programmeId]['name'] ?? ''),
			'programmeYear' => (int)$year,
		];
	}//end programmeColumns()

	/**
	 * How many of the programme year's expected learners have not chosen yet.
	 *
	 * @param EnrolmentForecastRun $run The forecast being computed.
	 * @param string               $key The programme year, `programmeId|programmeYear`.
	 *
	 * @return int
	 */
	private function missingChoices(EnrolmentForecastRun $run, string $key): int {
		$expected = 0;
		foreach ($this->yearRows(run: $run, years: array_intersect_key($run->years, [$key => true])) as $row) {
			$expected = $row['learners'];
		}

		return max(0, $expected - $run->chosen[$key]['learners']);
	}//end missingChoices()

	/**
	 * A subject's learners, counted plus estimated, and the groups they fill.
	 *
	 * @param array<string, mixed> $row  The subject row with its counted and estimated learners.
	 * @param int                  $size Target group size.
	 *
	 * @return array{learners: int, isEstimated: bool, groupsNeeded: int}
	 */
	private function subjectTotals(array $row, int $size): array {
		return [
			'learners' => $row['counted'] + $row['estimated'],
			'isEstimated' => $row['estimated'] > 0,
			'groupsNeeded' => (int)ceil(($row['counted'] + $row['estimated']) / $size),
		];
	}//end subjectTotals()

	/**
	 * Add learners to a programme year's bucket, creating it when missing.
	 *
	 * @param array<string, array<string, mixed>> $years  The buckets.
	 * @param array{0: string, 1: int}            $slot   The programme and the programme year.
	 * @param string                              $field  `fromMovingUp`, `repeaters` or `intake`.
	 * @param float                               $amount Learners to add.
	 *
	 * @return void
	 */
	private function add(array &$years, array $slot, string $field, float $amount): void {
		[$programmeId, $programmeYear] = $slot;
		$key = $programmeId.'|'.$programmeYear;
		if (isset($years[$key]) === false) {
			$years[$key] = ['programmeId' => $programmeId, 'programmeYear' => $programmeYear, 'fromMovingUp' => 0.0, 'repeaters' => 0.0, 'intake' => 0.0];
		}

		$years[$key][$field] += $amount;
	}//end add()

	/**
	 * A readable name for a rate row's programme year, such as "havo 5".
	 *
	 * @param EnrolmentForecastRun $run The forecast being computed: its programmes.
	 * @param array<string, mixed> $row The scenario's rate row.
	 *
	 * @return string
	 */
	private function label(EnrolmentForecastRun $run, array $row): string {
		$programmeId = (string)($row['programmeId'] ?? '');
		$name = (string)($run->programmes[$programmeId]['name'] ?? $programmeId);

		return trim($name.' '.(int)($row['programmeYear'] ?? 0));
	}//end label()
}//end class
