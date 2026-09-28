<?php

/**
 * Tests for HourPlanActivityService.
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
 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Event\HourPlanActivitiesQueryEvent;
use OCA\Learniq\Listener\HourPlanActivitiesQueryListener;
use OCA\Learniq\Service\HourPlanActivityService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Programme year to intake year, the plan lines of that year, teachers and course names.
 */
class HourPlanActivityServiceTest extends TestCase {

	/**
	 * The stored objects per schema.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $store = [];

	/**
	 * Build the service over the store.
	 *
	 * @return HourPlanActivityService
	 */
	private function service(): HourPlanActivityService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['register'], $filters['schema']);
				$rows = array_filter(
					($this->store[$schema] ?? []),
					static function (array $row) use ($filters, $config): bool {
						if (isset($config['ids']) === true && in_array($row['id'], $config['ids'], true) === false) {
							return false;
						}

						foreach ($filters as $key => $value) {
							if (($row[$key] ?? null) !== $value) {
								return false;
							}
						}

						return true;
					}
				);
				return OrEntityFactory::makeMany(array_values($rows), $schema);
			}
		);

		return new HourPlanActivityService($objects, new NullLogger());
	}//end service()

	/**
	 * Seed MV2A in its second year, its intake plan, a draft sibling and a teacher.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = [
			'cohort' => [
				['id' => 'c-mv2a', 'name' => 'MV2A', 'programmeId' => 'p-mmc', 'programmeYear' => 2, 'academicYear' => '2026-2027'],
				['id' => 'c-mv1a', 'name' => 'MV1A', 'programmeId' => 'p-mmc', 'programmeYear' => 1, 'academicYear' => '2026-2027'],
				['id' => 'c-old', 'name' => 'MV2A oud', 'programmeId' => 'p-mmc', 'programmeYear' => 2, 'academicYear' => '2025-2026'],
				['id' => 'c-none', 'name' => 'Losse groep', 'academicYear' => '2026-2027'],
			],
			'hour-plan' => [
				[
					'id' => 'hp-2025', 'programmeId' => 'p-mmc', 'intakeYear' => '2025-2026', 'lifecycle' => 'active',
					'lines' => [
						['courseId' => 'co-ne', 'programmeYear' => 1, 'periodCode' => 'P1', 'contactHours' => 40],
						['courseId' => 'co-ne', 'programmeYear' => 2, 'periodCode' => 'P1', 'contactHours' => 30, 'otherHours' => 5],
						['courseId' => 'co-bpv', 'programmeYear' => 2, 'periodCode' => null, 'contactHours' => 0, 'otherHours' => 400, 'activityKind' => 'work-placement'],
					],
				],
				['id' => 'hp-2025-draft', 'programmeId' => 'p-mmc', 'intakeYear' => '2025-2026', 'lifecycle' => 'draft', 'lines' => [['courseId' => 'co-x', 'programmeYear' => 2, 'contactHours' => 99]]],
			],
			'subjectteacherassignment' => [
				['id' => 'sta-1', 'cohortId' => 'c-mv2a', 'courseId' => 'co-ne', 'teacherId' => 'mbo-docent-01'],
			],
			'course' => [
				['id' => 'co-ne', 'name' => 'Nederlands'],
				['id' => 'co-bpv', 'name' => 'BPV'],
			],
		];
	}//end setUp()

	/**
	 * Year two of a cohort in 2026-2027 started in 2025-2026.
	 *
	 * @return void
	 */
	public function testIntakeYearArithmetic(): void {
		self::assertSame('2025-2026', $this->service()->intakeYearOf(academicYear: '2026-2027', programmeYear: 2));
		self::assertSame('2026-2027', $this->service()->intakeYearOf(academicYear: '2026-2027', programmeYear: 1));
		self::assertSame('2024-2025', $this->service()->intakeYearOf(academicYear: '2026-2027', programmeYear: 3));
		self::assertNull($this->service()->intakeYearOf(academicYear: '2026', programmeYear: 1));
		self::assertNull($this->service()->intakeYearOf(academicYear: '2026-2027', programmeYear: 0));
	}//end testIntakeYearArithmetic()

	/**
	 * MV2A gets the year-two lines of the active 2025-2026 plan, with teacher and course name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#scenario-a-timetabler-exports-next-year-s-activities
	 */
	public function testActivitiesOfASchoolYear(): void {
		$out = $this->service()->forYear(academicYear: '2026-2027');

		$mv2a = array_values(array_filter($out['activities'], static fn (array $a): bool => $a['cohortId'] === 'c-mv2a'));
		self::assertCount(2, $mv2a);
		self::assertSame('Nederlands', $mv2a[0]['courseName']);
		self::assertSame(30.0, $mv2a[0]['contactHours']);
		self::assertSame(['mbo-docent-01'], $mv2a[0]['teacherIds']);
		self::assertSame('work-placement', $mv2a[1]['activityKind']);
		self::assertSame([], $mv2a[1]['teacherIds']);
		self::assertNotContains('co-x', array_column($out['activities'], 'courseId'), 'a draft plan is never read');
		self::assertNotContains('c-old', array_column($out['activities'], 'cohortId'), 'another school year is never read');

		// MV1A started in 2026-2027, which has no active plan.
		self::assertSame(['c-mv1a'], array_column($out['cohortsWithoutPlan'], 'cohortId'));
		self::assertSame('2026-2027', $out['cohortsWithoutPlan'][0]['intakeYear']);
	}//end testActivitiesOfASchoolYear()

	/**
	 * The in-process query event gets the same answer, and a bad year is refused.
	 *
	 * @return void
	 */
	public function testQueryEventIsAnswered(): void {
		$listener = new HourPlanActivitiesQueryListener($this->service());

		$event = new HourPlanActivitiesQueryEvent(sourceApp: 'integriq', academicYear: '2026-2027');
		$listener->handle($event);
		self::assertTrue($event->isHandled());
		self::assertSame('integriq', $event->getSourceApp());
		self::assertCount(2, $event->getResult()['activities']);

		$bad = new HourPlanActivitiesQueryEvent(sourceApp: 'integriq', academicYear: 'next year');
		$listener->handle($bad);
		self::assertNull($bad->getResult());
		self::assertNotNull($bad->getError());
	}//end testQueryEventIsAnswered()
}//end class
