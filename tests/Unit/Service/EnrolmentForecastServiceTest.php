<?php

/**
 * EnrolmentForecastService: moving up, repeating, leaving, intake, subjects and groups.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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

namespace OCA\Learniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Learniq\Service\EnrolmentForecastReader;
use OCA\Learniq\Service\EnrolmentForecastService;
use PHPUnit\Framework\TestCase;

/**
 * Havo 3 to havo 4, a finishing year, intake and subject choices.
 */
class EnrolmentForecastServiceTest extends TestCase {

	/**
	 * Learner ids.
	 *
	 * @param string $prefix Id prefix.
	 * @param int    $count  How many.
	 *
	 * @return array<int, string>
	 */
	private function learners(string $prefix, int $count): array {
		return array_map(static fn (int $i): string => $prefix.$i, range(1, $count));
	}//end learners()

	/**
	 * The service over havo 3, 4 and 5 and vwo 5.
	 *
	 * @param array<int, array<string, mixed>> $choices     Approved choices.
	 * @param array<string, float>             $applications Placed applications by programme.
	 *
	 * @return EnrolmentForecastService
	 */
	private function service(array $choices=[], array $applications=[]): EnrolmentForecastService {
		$reader = $this->createMock(EnrolmentForecastReader::class);
		$reader->method('programmes')->willReturn(['havo' => ['id' => 'havo', 'name' => 'havo'], 'vwo' => ['id' => 'vwo', 'name' => 'vwo']]);
		$reader->method('currentGroups')->willReturn([
			['id' => 'h3', 'programmeId' => 'havo', 'programmeYear' => 3, 'learnerIds' => $this->learners('h3-', 118)],
			['id' => 'h4', 'programmeId' => 'havo', 'programmeYear' => 4, 'learnerIds' => $this->learners('h4-', 100)],
			['id' => 'h5', 'programmeId' => 'havo', 'programmeYear' => 5, 'learnerIds' => $this->learners('h5-', 90)],
		]);
		$reader->method('durations')->willReturn(['havo' => 5, 'vwo' => 6]);
		$reader->method('placedApplications')->willReturn($applications);
		$reader->method('approvedChoices')->willReturn($choices);
		$reader->method('courseNames')->willReturn(['econ' => 'Economie']);

		return new EnrolmentForecastService($reader);
	}//end service()

	/**
	 * Havo 4 next year: 104 from havo 3 plus the repeaters of havo 4.
	 *
	 * @return void
	 */
	public function testHavoFourFromHavoThreeAndRepeaters(): void {
		$result = $this->service()->compute([
			'name' => 'Voorjaarsprognose', 'targetYear' => '2027-2028', 'targetGroupSize' => 28,
			'rates' => [
				['programmeId' => 'havo', 'programmeYear' => 3, 'upRate' => 0.88, 'repeatRate' => 0.07, 'leaveRate' => 0.05],
				['programmeId' => 'havo', 'programmeYear' => 4, 'upRate' => 0.9, 'repeatRate' => 0.05, 'leaveRate' => 0.05],
			],
		]);

		$havo4 = array_values(array_filter($result['programmeYears'], static fn (array $row): bool => $row['programmeId'] === 'havo' && $row['programmeYear'] === 4))[0];
		self::assertSame(104, $havo4['fromMovingUp']);
		self::assertSame(5, $havo4['repeaters']);
		self::assertSame(109, $havo4['learners']);
		self::assertSame(4, $havo4['groupsNeeded']);
		self::assertSame('2026-2027', $result['currentYear']);
		self::assertNotEmpty($result['computedAt']);
	}//end testHavoFourFromHavoThreeAndRepeaters()

	/**
	 * With rates of 0.9, 0.05 and 0.05, 100 learners give 90 up and 5 repeaters;
	 * the final year sends its movers out, not up.
	 *
	 * @return void
	 */
	public function testDefaultRatesAndTheFinalYear(): void {
		$result = $this->service()->compute(['name' => 'Basis', 'targetYear' => '2027-2028']);
		$rows = [];
		foreach ($result['programmeYears'] as $row) {
			$rows[$row['programmeYear']] = $row;
		}

		self::assertSame(90, $rows[5]['fromMovingUp']);
		self::assertSame(5, $rows[4]['repeaters']);
		self::assertArrayNotHasKey(6, $rows, 'havo has five years: the movers of havo 5 graduate.');
		self::assertSame(95, $rows[5]['learners'], '90 up from havo 4 and 5 of the 90 in havo 5 repeating.');
	}//end testDefaultRatesAndTheFinalYear()

	/**
	 * Rates that do not add up to one are refused, naming the programme year.
	 *
	 * @return void
	 */
	public function testRatesMustAddUpToOne(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('vwo 5');

		$this->service()->compute([
			'targetYear' => '2027-2028',
			'rates' => [['programmeId' => 'vwo', 'programmeYear' => 5, 'upRate' => 0.9, 'repeatRate' => 0.1, 'leaveRate' => 0.1]],
		]);
	}//end testRatesMustAddUpToOne()

	/**
	 * Intake comes from the scenario, else from placed applications.
	 *
	 * @return void
	 */
	public function testIntake(): void {
		$fromScenario = $this->service([], ['havo' => 12.0])->compute(['targetYear' => '2027-2028', 'intake' => [['programmeId' => 'havo', 'expected' => 140]]]);
		$fromApplications = $this->service([], ['havo' => 12.0])->compute(['targetYear' => '2027-2028']);

		$first = static fn (array $result): array => array_values(array_filter($result['programmeYears'], static fn (array $row): bool => $row['programmeYear'] === 1))[0];
		self::assertSame(140, $first($fromScenario)['intake']);
		self::assertSame(12, $first($fromApplications)['intake']);
	}//end testIntake()

	/**
	 * Economie in havo 4: counted choices plus an estimate for learners who
	 * have not chosen yet, and the groups needed at 28.
	 *
	 * @return void
	 */
	public function testSubjectGroupsAndEstimates(): void {
		$choices = [];
		foreach (array_slice($this->learners('h3-', 118), 0, 59) as $index => $learnerId) {
			$choices[] = ['learnerId' => $learnerId, 'programmeId' => 'havo', 'selectedElectiveCourseIds' => ($index < 33) ? ['econ'] : []];
		}

		$result = $this->service($choices)->compute([
			'targetYear' => '2027-2028', 'targetGroupSize' => 28,
			'rates' => [['programmeId' => 'havo', 'programmeYear' => 3, 'upRate' => 0.88, 'repeatRate' => 0.07, 'leaveRate' => 0.05]],
		]);

		$econ = $result['subjects'][0];
		self::assertSame(['Economie', 4, 33], [$econ['courseName'], $econ['programmeYear'], $econ['counted']]);
		self::assertTrue($econ['isEstimated']);
		self::assertSame($econ['counted'] + $econ['estimated'], $econ['learners']);
		self::assertSame((int)ceil($econ['learners'] / 28), $econ['groupsNeeded']);
		self::assertSame(3, (int)ceil(61 / 28));
	}//end testSubjectGroupsAndEstimates()

	/**
	 * A malformed target year is refused.
	 *
	 * @return void
	 */
	public function testTargetYearMustBeASchoolYear(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service()->compute(['targetYear' => 'next year']);
	}//end testTargetYearMustBeASchoolYear()
}//end class
