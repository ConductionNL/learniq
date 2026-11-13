<?php

/**
 * Unit tests for EnrolmentForecastReader: the rows the enrolment forecast
 * reads, filtered again on its own terms and keyed by id.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\EnrolmentForecastReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Service\EnrolmentForecastReader
 */
class EnrolmentForecastReaderTest extends TestCase {

	/**
	 * Queries made: [filters, rbac].
	 *
	 * @var list<array{0: array<string, mixed>, 1: bool}>
	 */
	private array $queries = [];

	/**
	 * A reader whose store answers per schema.
	 *
	 * @param array<string, list<mixed>> $bySchema Rows per schema.
	 *
	 * @return EnrolmentForecastReader
	 */
	private function reader(array $bySchema): EnrolmentForecastReader {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true) use ($bySchema): array {
				$this->queries[] = [$config['filters'], $_rbac];
				return $bySchema[$config['filters']['schema']] ?? [];
			}
		);
		return new EnrolmentForecastReader($objects);
	}//end reader()

	/**
	 * An entity row serialising to the given data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Current groups are the year's cohorts with a programme and a positive programme year.
	 *
	 * @return void
	 */
	public function testCurrentGroupsAreTheYearsPlacedCohorts(): void {
		$reader = $this->reader(
			[
				'cohort' => [
					['id' => 'c-1', 'academicYear' => '2026-2027', 'programmeId' => 'p-1', 'programmeYear' => 2],
					$this->entity(['id' => 'c-2', 'academicYear' => '2026-2027', 'programmeId' => 'p-2', 'programmeYear' => '1']),
					['id' => 'c-3', 'academicYear' => '2025-2026', 'programmeId' => 'p-1', 'programmeYear' => 1],
					['id' => 'c-4', 'academicYear' => '2026-2027', 'programmeId' => '', 'programmeYear' => 1],
					['id' => 'c-5', 'academicYear' => '2026-2027', 'programmeId' => 'p-1', 'programmeYear' => 0],
					'not a row',
				],
			]
		);

		self::assertSame(['c-1', 'c-2'], array_column($reader->currentGroups('2026-2027'), 'id'));
		self::assertSame([[['register' => 'learniq', 'schema' => 'cohort', 'academicYear' => '2026-2027'], false]], $this->queries);
	}//end testCurrentGroupsAreTheYearsPlacedCohorts()

	/**
	 * Programmes and course names are keyed by id; rows without an id are dropped.
	 *
	 * @return void
	 */
	public function testProgrammesAndCourseNamesAreKeyedById(): void {
		$reader = $this->reader(
			[
				'programme' => [['id' => 'p-1', 'name' => 'Havo'], ['name' => 'no id']],
				'course' => [['id' => 'k-1', 'name' => 'Wiskunde'], ['id' => 'k-2'], ['id' => '', 'name' => 'leeg']],
			]
		);

		self::assertSame(['p-1' => ['id' => 'p-1', 'name' => 'Havo']], $reader->programmes());
		self::assertSame(['k-1' => 'Wiskunde', 'k-2' => ''], $reader->courseNames());
	}//end testProgrammesAndCourseNamesAreKeyedById()

	/**
	 * A programme's duration is the longest of its current years and its active hour plans.
	 *
	 * @return void
	 */
	public function testDurationsTakeTheLongestOfGroupsAndActivePlans(): void {
		$reader = $this->reader(
			[
				'hour-plan' => [
					['programmeId' => 'p-1', 'lifecycle' => 'active', 'durationYears' => 5],
					['programmeId' => 'p-2', 'lifecycle' => 'active', 'durationYears' => 1],
					['programmeId' => 'p-3', 'lifecycle' => 'draft', 'durationYears' => 6],
					['programmeId' => '', 'lifecycle' => 'active', 'durationYears' => 9],
				],
			]
		);

		$durations = $reader->durations(
			[
				['programmeId' => 'p-1', 'programmeYear' => 2],
				['programmeId' => 'p-2', 'programmeYear' => 3],
				['programmeId' => 'p-2', 'programmeYear' => '2'],
			]
		);

		self::assertSame(['p-1' => 5, 'p-2' => 3], $durations);
		self::assertSame(['register' => 'learniq', 'schema' => 'hour-plan', 'lifecycle' => 'active'], $this->queries[0][0]);
	}//end testDurationsTakeTheLongestOfGroupsAndActivePlans()

	/**
	 * Placed and converted applications in the target year's rounds count per programme.
	 *
	 * @return void
	 */
	public function testPlacedApplicationsCountPerProgramme(): void {
		$reader = $this->reader(
			[
				'admissions-round' => [
					['id' => 'r-1', 'academicYear' => '2027-2028'],
					['id' => 'r-old', 'academicYear' => '2026-2027'],
				],
				'admission' => [
					['programmeId' => 'p-1', 'admissionsRoundId' => 'r-1', 'lifecycle' => 'placed'],
					['programmeId' => 'p-1', 'admissionsRoundId' => 'r-1', 'lifecycle' => 'converted'],
					['programmeId' => 'p-2', 'admissionsRoundId' => 'r-1', 'lifecycle' => 'placed'],
					['programmeId' => 'p-1', 'admissionsRoundId' => 'r-1', 'lifecycle' => 'submitted'],
					['programmeId' => 'p-1', 'admissionsRoundId' => 'r-old', 'lifecycle' => 'placed'],
					['programmeId' => '', 'admissionsRoundId' => 'r-1', 'lifecycle' => 'placed'],
				],
			]
		);

		self::assertSame(['p-1' => 2.0, 'p-2' => 1.0], $reader->placedApplications('2027-2028'));
	}//end testPlacedApplicationsCountPerProgramme()

	/**
	 * Approved and locked choices of the target year are returned.
	 *
	 * @return void
	 */
	public function testApprovedChoicesOfTheYear(): void {
		$reader = $this->reader(
			[
				'subject-choice' => [
					['id' => 's-1', 'academicYear' => '2027-2028', 'lifecycle' => 'approved'],
					['id' => 's-2', 'academicYear' => '2027-2028', 'lifecycle' => 'locked'],
					['id' => 's-3', 'academicYear' => '2027-2028', 'lifecycle' => 'submitted'],
					['id' => 's-4', 'academicYear' => '2026-2027', 'lifecycle' => 'approved'],
				],
			]
		);

		self::assertSame(['s-1', 's-2'], array_column($reader->approvedChoices('2027-2028'), 'id'));
	}//end testApprovedChoicesOfTheYear()

	/**
	 * A store that throws reads as empty.
	 *
	 * @return void
	 */
	public function testAFailingStoreReadsAsEmpty(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('down'));
		$reader = new EnrolmentForecastReader($objects);

		self::assertSame([], $reader->currentGroups('2026-2027'));
		self::assertSame([], $reader->programmes());
		self::assertSame([], $reader->durations([]));
	}//end testAFailingStoreReadsAsEmpty()
}//end class
