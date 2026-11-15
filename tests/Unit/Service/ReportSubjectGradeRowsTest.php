<?php

/**
 * The latest report card as one row per subject, for the parent portal's bars.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-latest-report-reads-as-one-bar-per-subject
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ReportSubjectGradeRows;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use PHPUnit\Framework\TestCase;

// The unit bootstrap does not load the golden-file helper; the full one does.
require_once __DIR__ . '/../../Support/GoldenFile.php';

/**
 * Vera's June report as six bars.
 */
class ReportSubjectGradeRowsTest extends TestCase {
	use GoldenFile;


	/**
	 * The po example set, per schema.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function po(): array {
		$set = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/profiles/po.json'), true);
		return $set['x-openregister']['seedData']['objects'];
	}//end po()

	/**
	 * Vera's report 2 becomes six rows in the card's order, named, with the caption and the teacher's words.
	 *
	 * @return void
	 */
	public function testAReportBecomesOneRowPerSubject(): void {
		$objects = self::po();
		$card    = array_values(array_filter($objects['report-card'], static fn (array $c): bool => $c['learnerRef'] === 'ee010008-0000-4000-8000-000000000415' && $c['periodName'] === 'Rapport 2'))[0];
		$names   = array_column($objects['course'], 'name', 'uuid');

		$rows = ReportSubjectGradeRows::rowsFor(card: ['id' => $card['uuid']] + $card, names: $names, group: 'Groep 7');

		self::assertSame(['Rekenen', 'Taal', 'Spelling', 'Technisch lezen', 'Begrijpend lezen', 'Wereldoriëntatie'], array_column($rows, 'subjectName'));
		self::assertSame([7.9, 8.3, 7.9, 7.7, 7.7, 8.2], array_column($rows, 'periodAverage'));
		self::assertSame([0, 1, 2, 3, 4, 5], array_column($rows, 'position'));
		self::assertStringStartsWith('Rapport 2 · ', $rows[0]['caption']);
		self::assertStringEndsWith(' · Groep 7', $rows[0]['caption']);
		self::assertStringStartsWith('Vera heeft een goede werkhouding', (string)$rows[0]['mentorComment']);
		self::assertSame($card['uuid'], $rows[0]['reportCardId']);
	}//end testAReportBecomesOneRowPerSubject()

	/**
	 * Publishing a newer card replaces the pupil's rows and leaves another pupil's alone; the card is never written.
	 *
	 * @return void
	 */
	public function testANewerCardReplacesThePupilsRows(): void {
		$store = new RegisterFaithfulStore();
		$store->rows = [
			'report-subject-grade' => [
				['id' => 'old-1', 'learnerRef' => 'vera', 'reportCardId' => 'card-1', 'subjectName' => 'Rekenen', 'tenant_id' => 't'],
				['id' => 'other', 'learnerRef' => 'sem', 'reportCardId' => 'card-9', 'subjectName' => 'Rekenen', 'tenant_id' => 't'],
			],
			'course' => [['id' => 'c-rek', 'name' => 'Rekenen'], ['id' => 'c-taal', 'name' => 'Taal']],
			'cohort' => [['id' => 'g7', 'name' => 'Groep 7']],
		];
		$deleted = [];
		$saved   = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $store->findAll($config, $_rbac, $_multitenancy));
		$objects->method('deleteObject')->willReturnCallback(static function (string $uuid, $register = null, $schema = null) use (&$deleted): bool {
			$deleted[] = [$schema, $uuid];
			return true;
		});
		$objects->method('saveObject')->willReturnCallback(function (array $object, ?array $extend = [], $register = null, $schema = null) use (&$saved, $store) {
			$saved[] = [$schema, $object];
			return $store->save((string)$schema, $object, null);
		});

		$written = (new ReportSubjectGradeRows($objects))->replace([
			'id' => 'card-2', 'learnerRef' => 'vera', 'learnerId' => 'po-leerling-147', 'cohortId' => 'g7', 'periodName' => 'Rapport 2',
			'composedAt' => '2026-07-15T16:00:00+02:00', 'mentorComment' => 'Goed gedaan.', 'tenant_id' => 't',
			'subjectGrades' => [['courseId' => 'c-rek', 'periodAverage' => 7.9, 'passed' => true], ['courseId' => 'c-taal', 'periodAverage' => 8.3, 'passed' => true], ['courseId' => 'unknown', 'periodAverage' => 6.0]],
		]);

		self::assertSame(2, $written, 'a subject without a name is left out');
		self::assertSame([['report-subject-grade', 'old-1']], $deleted, "only this pupil's older rows go");
		self::assertSame(['report-subject-grade'], array_values(array_unique(array_column($saved, 0))), 'the report card itself is never written');
		self::assertSame('Rapport 2 · juli 2026 · Groep 7', $saved[0][1]['caption']);
		self::assertSame(0, (new ReportSubjectGradeRows($objects))->replace(['id' => 'card-3']), 'a card without a pupil writes nothing');
	}//end testANewerCardReplacesThePupilsRows()
	/**
	 * The reads a replace makes, recorded: the pupil's rows, the course and
	 * plan names by id, the group by id, with entity rows and rows that are
	 * not rows; and a card without a group or grades.
	 *
	 * @return void
	 */
	public function testTheReadsAsRecorded(): void {
		$entity = new class() implements \JsonSerializable {
			/**
			 * A course as an entity serialises.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['uuid' => 'c-taal', 'name' => ' Taal '];
			}//end jsonSerialize()
		};
		$answers = [
			'report-subject-grade' => [['id' => 'old-1', 'learnerRef' => 'vera'], 'not a row'],
			'course' => [['id' => 'c-rek', 'name' => 'Rekenen'], $entity],
			'curriculum-plan' => [['id' => 'p-1', 'name' => 'Plan']],
			'cohort' => [['id' => 'g7', 'name' => ' Groep 7 ']],
		];
		$cards = [
			'a full card' => [
				'id' => 'card-2', 'learnerRef' => 'vera', 'cohortId' => 'g7', 'periodName' => 'Rapport 2', 'composedAt' => '2026-07-15T16:00:00+02:00', 'tenant_id' => 't',
				'subjectGrades' => [['courseId' => 'c-rek', 'curriculumPlanId' => 'p-1', 'periodAverage' => 7.9], ['courseId' => 'c-taal', 'periodAverage' => 8.3], ['courseId' => 'c-rek'], ['courseId' => '']],
			],
			'no group, no grades' => ['id' => 'card-3', 'learnerRef' => 'sem', 'tenant_id' => 't'],
		];

		$actual = [];
		foreach ($cards as $name => $card) {
			$reads = [];
			$saved = [];
			$objects = $this->createMock(ObjectService::class);
			$objects->method('findAll')->willReturnCallback(
				static function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use (&$reads, $answers): array {
					$reads[] = [$config, $_rbac, $_multitenancy];
					return ($answers[$config['filters']['schema']] ?? []);
				}
			);
			$objects->method('deleteObject')->willReturn(true);
			$objects->method('saveObject')->willReturnCallback(
				static function (array $object) use (&$saved) {
					$saved[] = $object;
					return OrEntityFactory::make($object, 'report-subject-grade');
				}
			);

			$written = (new ReportSubjectGradeRows($objects))->replace($card);
			$actual[$name] = ['written' => $written, 'reads' => $reads, 'saved' => $saved];
		}

		$this->assertMatchesGolden(__DIR__ . '/golden/ReportSubjectGradeRows.json', $actual);
	}//end testTheReadsAsRecorded()
}//end class
