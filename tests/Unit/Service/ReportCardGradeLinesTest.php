<?php

/**
 * Learniq ReportCardGradeLines unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-a-report-card-carries-its-subject-grades-in-readable-form
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ReportCardGradeLines;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReportCardGradeLines::derive().
 */
class ReportCardGradeLinesTest extends TestCase {

	private const PERIOD = 'ee01000b-0000-4000-8000-000000000001';
	private const REKENEN = 'course-rekenen';
	private const TAAL = 'course-taal';
	private const PLAN_REKENEN = 'plan-rekenen';
	private const PLAN_WO = 'plan-wo';

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * The service over the fake store.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema slug.
	 *
	 * @return ReportCardGradeLines
	 */
	private function makeService(array $rows): ReportCardGradeLines {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = $rows;

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		return new ReportCardGradeLines(objectService: $objectService);
	}//end makeService()

	/**
	 * The names of a small primary school.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function school(): array {
		return [
			'report-period' => [['id' => self::PERIOD, 'name' => 'Rapport 1']],
			'course' => [
				['id' => self::REKENEN, 'name' => 'Rekenen'],
				['id' => self::TAAL, 'name' => 'Taal'],
			],
			'curriculum-plan' => [
				['id' => self::PLAN_REKENEN, 'name' => 'Rekenen groep 3 tot en met 8, 2025-2026'],
				['id' => self::PLAN_WO, 'name' => 'Wereldoriëntatie groep 3 tot en met 8, 2025-2026'],
			],
		];
	}//end school()

	/**
	 * A report card names its period and writes one line per subject, the
	 * subject by its course name and the grade with a decimal comma.
	 *
	 * @return void
	 */
	public function testAReportCardReadsAsPeriodAndOneLinePerSubject(): void {
		$derived = $this->makeService($this->school())->derive(
			[
				'reportPeriodId' => self::PERIOD,
				'subjectGrades' => [
					['curriculumPlanId' => self::PLAN_REKENEN, 'courseId' => self::REKENEN, 'periodAverage' => 7.9, 'passed' => true],
					['curriculumPlanId' => 'plan-taal', 'courseId' => self::TAAL, 'periodAverage' => 8, 'passed' => true],
				],
			]
		);

		self::assertSame('Rapport 1', $derived['periodName']);
		self::assertSame(['Rekenen: 7,9', 'Taal: 8,0'], $derived['gradeLines']);
	}//end testAReportCardReadsAsPeriodAndOneLinePerSubject()

	/**
	 * A subject without a course is named by its curriculum plan; a subject
	 * without a grade shows its name alone; a subject nothing names is left
	 * out rather than shown as a code.
	 *
	 * @return void
	 */
	public function testASubjectIsNamedByItsPlanWhenItHasNoCourse(): void {
		$derived = $this->makeService($this->school())->derive(
			[
				'reportPeriodId' => self::PERIOD,
				'subjectGrades' => [
					['curriculumPlanId' => self::PLAN_WO, 'courseId' => null, 'periodAverage' => 6.25],
					['curriculumPlanId' => self::PLAN_REKENEN, 'courseId' => 'course-gone', 'periodAverage' => null],
					['curriculumPlanId' => 'plan-gone', 'courseId' => 'course-gone', 'periodAverage' => 5.0],
					'not a row',
				],
			]
		);

		self::assertSame(
			['Wereldoriëntatie groep 3 tot en met 8, 2025-2026: 6,3', 'Rekenen groep 3 tot en met 8, 2025-2026'],
			$derived['gradeLines']
		);
	}//end testASubjectIsNamedByItsPlanWhenItHasNoCourse()

	/**
	 * A card without grades (groups 1 and 2) or without a known period still
	 * derives, to an empty list and no period name.
	 *
	 * @return void
	 */
	public function testACardWithoutGradesHasNoLines(): void {
		$derived = $this->makeService($this->school())->derive(['reportPeriodId' => 'period-gone', 'subjectGrades' => []]);

		self::assertNull($derived['periodName']);
		self::assertSame([], $derived['gradeLines']);
	}//end testACardWithoutGradesHasNoLines()

	/**
	 * The names are read across tenants without a session (the back-fill
	 * runs without one), each schema in one read, and a name already read is
	 * not read again for the next card.
	 *
	 * @return void
	 */
	public function testNamesAreReadOncePerSchemaWithoutTheSessionScope(): void {
		$service = $this->makeService($this->school());
		$card = [
			'reportPeriodId' => self::PERIOD,
			'subjectGrades' => [
				['curriculumPlanId' => self::PLAN_REKENEN, 'courseId' => self::REKENEN, 'periodAverage' => 7.0],
				['curriculumPlanId' => 'plan-taal', 'courseId' => self::TAAL, 'periodAverage' => 6.0],
			],
		];

		$service->derive($card);
		$readsAfterFirst = count($this->store->reads);
		$service->derive($card);

		self::assertSame(3, $readsAfterFirst, 'one read each for the period, the courses and the plans');
		self::assertSame($readsAfterFirst, count($this->store->reads), 'the second card reads nothing new');
		foreach ($this->store->reads as $read) {
			self::assertFalse($read['rbac']);
			self::assertFalse($read['multitenancy']);
		}
	}//end testNamesAreReadOncePerSchemaWithoutTheSessionScope()

	/**
	 * A failed read is not hidden: the caller decides what to keep.
	 *
	 * @return void
	 */
	public function testAFailedReadIsThrown(): void {
		$service = $this->makeService($this->school());
		$this->store->failReads = 'database gone';

		$this->expectException(\RuntimeException::class);
		$service->derive(['reportPeriodId' => self::PERIOD, 'subjectGrades' => []]);
	}//end testAFailedReadIsThrown()

	/**
	 * The example sets carry the same copies the server derives, so a fresh
	 * install shows guardians their grades before any report card is saved
	 * again (scripts/example-sets/po.py and vo.py write them).
	 *
	 * @return void
	 */
	public function testTheExampleSetsCarryWhatTheServerDerives(): void {
		foreach (['po', 'vo'] as $set) {
			$rows = [];
			$profile = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/' . $set . '.json'), true);
			foreach (['course', 'curriculum-plan', 'report-period', 'report-card'] as $slug) {
				foreach (($profile['x-openregister']['seedData']['objects'][$slug] ?? []) as $object) {
					$rows[$slug][] = array_merge($object, ['id' => $object['uuid']]);
				}
			}

			self::assertNotEmpty($rows['report-card'] ?? [], $set . ' has report cards');
			$service = $this->makeService($rows);
			foreach ($rows['report-card'] as $card) {
				$derived = $service->derive($card);
				self::assertSame($derived['periodName'], ($card['periodName'] ?? null), $set . ' ' . $card['slug']);
				self::assertSame($derived['gradeLines'], ($card['gradeLines'] ?? null), $set . ' ' . $card['slug']);
			}
		}
	}//end testTheExampleSetsCarryWhatTheServerDerives()
}//end class
