<?php

/**
 * The primary school example set keeps its promises.
 *
 * ExampleSetDescriptorContractTest proves the file loads (shape, uuids,
 * references, schema validity). This test proves the story is consistent: an
 * absence falls on a school day of the pupil's own class and is marked by the
 * teacher who works that weekday, a report card counts exactly those marks, and
 * the removal list covers every object once.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/changes/segment-example-datasets-po/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Service\DemoDataService;
use OCA\Learniq\Service\SeedProfileService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Content and consistency of lib/Settings/profiles/po.json.
 */
class PrimarySchoolExampleSetTest extends TestCase {

	/**
	 * The decoded set, per schema slug.
	 *
	 * @var array<string, array<int, array<string, mixed>>>|null
	 */
	private static ?array $objects = null;

	/**
	 * The set's objects of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function of(string $schema): array {
		if (self::$objects === null) {
			$set = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/profiles/po.json'), true);
			self::$objects = $set['x-openregister']['seedData']['objects'];
		}

		return (self::$objects[$schema] ?? []);
	}//end of()

	/**
	 * Index a list of objects by one field.
	 *
	 * @param array<int, array<string, mixed>> $rows  The objects.
	 * @param string                           $field The field.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function by(array $rows, string $field): array {
		$index = [];
		foreach ($rows as $row) {
			$index[(string)$row[$field]] = $row;
		}

		return $index;
	}//end by()

	/**
	 * One school, two locations, groups 1 to 8 in seven classes with a
	 * combined 5/6, about 200 pupils, each with a guardian and an enrolment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-po/specs/example-sets/spec.md#requirement-the-primary-school-set-is-one-consistent-school
	 */
	public function testTheSchoolHasItsPromisedShape(): void {
		self::assertCount(1, self::of('school'));
		self::assertCount(2, self::of('vestiging'));
		self::assertSame(
			['Groep 1', 'Groep 2', 'Groep 3', 'Groep 4', 'Groep 5/6', 'Groep 7', 'Groep 8'],
			array_column(self::of('cohort'), 'name')
		);

		$profiles = self::of('learner-profile');
		$pupils   = array_values(array_filter($profiles, static fn (array $p): bool => $p['roles'] === ['learner']));
		$byUuid   = self::by($profiles, 'uuid');
		self::assertGreaterThanOrEqual(180, count($pupils));
		self::assertLessThanOrEqual(220, count($pupils));
		foreach ($pupils as $pupil) {
			self::assertNotEmpty($pupil['guardianRefs'], $pupil['ncUserId'] . ' has a guardian');
			foreach ($pupil['guardianRefs'] as $ref) {
				self::assertSame(['parent'], $byUuid[$ref]['roles']);
			}

			self::assertArrayNotHasKey('bsnEncrypted', $pupil);
		}

		$enrolled = array_column(self::of('enrolment'), 'learnerId');
		self::assertSame(count($pupils), count(array_unique($enrolled)));
		self::assertEqualsCanonicalizing(array_column($pupils, 'ncUserId'), $enrolled);

		self::assertCount(2, self::of('report-period'));
		self::assertNotEmpty(self::of('staff'));
		self::assertNotEmpty(self::of('subjectteacherassignment'));
		self::assertNotEmpty(self::of('lvs-result'));
		self::assertNotEmpty(self::of('dossier-note'));
		self::assertNotEmpty(self::of('support-request'));
	}//end testTheSchoolHasItsPromisedShape()

	/**
	 * Every mark sits on a session of the pupil's own class, on a school day,
	 * marked by the teacher who works that weekday.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-po/specs/example-sets/spec.md#requirement-the-primary-school-set-is-one-consistent-school
	 */
	public function testEveryMarkBelongsToThePupilsOwnClassAndTeacher(): void {
		$sessions  = self::by(self::of('session'), 'uuid');
		$cohorts   = self::by(self::of('cohort'), 'uuid');
		$enrolment = self::by(self::of('enrolment'), 'learnerId');
		$weekdays  = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

		self::assertGreaterThan(1000, count(self::of('attendance-record')));
		foreach (self::of('attendance-record') as $mark) {
			$session = $sessions[$mark['sessionId']];
			self::assertSame($enrolment[$mark['learnerId']]['cohortId'], $session['cohortId'], $mark['slug']);
			self::assertSame($session['cohortId'], $mark['cohortId'], $mark['slug']);

			$day = new \DateTimeImmutable($session['startsAt']);
			self::assertLessThan(6, (int)$day->format('N'), $mark['slug'] . ' is on a weekday');
			self::assertGreaterThanOrEqual($enrolment[$mark['learnerId']]['inschrijvingDate'], $day->format('Y-m-d'));

			$weekday = $weekdays[((int)$day->format('N')) - 1];
			$onDuty  = array_values(array_filter(
				$cohorts[$session['cohortId']]['teacherAssignments'],
				static fn (array $a): bool => in_array($weekday, $a['days'], true)
			));
			self::assertSame($onDuty[0]['teacherId'], $mark['markedBy'], $mark['slug'] . ' is marked by the teacher on duty');
		}
	}//end testEveryMarkBelongsToThePupilsOwnClassAndTeacher()

	/**
	 * No session falls in a holiday or on a study day of its report period.
	 *
	 * @return void
	 */
	public function testNoSessionFallsInAHolidayOrOnAStudyDay(): void {
		$closed = [];
		foreach (self::of('report-period') as $period) {
			foreach ($period['holidays'] as $holiday) {
				for ($d = new \DateTimeImmutable($holiday['startDate']); $d <= new \DateTimeImmutable($holiday['endDate']); $d = $d->modify('+1 day')) {
					$closed[$d->format('Y-m-d')] = $holiday['name'];
				}
			}

			foreach ($period['studyDays'] as $studyDay) {
				$closed[$studyDay['date']] = $studyDay['description'];
			}
		}

		self::assertNotEmpty($closed);
		foreach (self::of('session') as $session) {
			$day = substr($session['startsAt'], 0, 10);
			self::assertArrayNotHasKey($day, $closed, $session['title'] . ' falls on a day the school is closed');
		}
	}//end testNoSessionFallsInAHolidayOrOnAStudyDay()

	/**
	 * A report card's attendance summary counts exactly the marks in its
	 * period, so the card and the attendance list never disagree.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-po/specs/example-sets/spec.md#requirement-the-primary-school-set-is-one-consistent-school
	 */
	public function testReportCardsCountTheMarksOfTheirPeriod(): void {
		$sessions = self::by(self::of('session'), 'uuid');
		$periods  = self::by(self::of('report-period'), 'uuid');
		$counted  = [];
		foreach (self::of('attendance-record') as $mark) {
			$day = substr($sessions[$mark['sessionId']]['startsAt'], 0, 10);
			foreach ($periods as $uuid => $period) {
				if ($day >= $period['startDate'] && $day <= $period['endDate']) {
					$counted[$mark['learnerId'] . '|' . $uuid][$mark['status']] = (($counted[$mark['learnerId'] . '|' . $uuid][$mark['status']] ?? 0) + 1);
				}
			}
		}

		self::assertGreaterThan(300, count(self::of('report-card')));
		foreach (self::of('report-card') as $card) {
			$tally   = ($counted[$card['learnerId'] . '|' . $card['reportPeriodId']] ?? []);
			$summary = $card['attendanceSummary'];
			self::assertSame(($tally['absent-excused'] ?? 0), $summary['absentExcusedCount'], $card['slug']);
			self::assertSame(($tally['absent-unexcused'] ?? 0), $summary['absentUnexcusedCount'], $card['slug']);
			self::assertSame(($tally['late'] ?? 0), $summary['lateCount'], $card['slug']);
			self::assertSame(($tally['left-early'] ?? 0), $summary['leftEarlyCount'], $card['slug']);
		}
	}//end testReportCardsCountTheMarksOfTheirPeriod()

	/**
	 * The leerplicht story holds: a groep 7 pupil crosses 16 unexcused hours
	 * and the flag lists exactly those marks.
	 *
	 * @return void
	 */
	public function testTheLeerplichtFlagListsItsUnexcusedMarks(): void {
		$flag  = self::of('attendance-flag')[0];
		$marks = self::by(self::of('attendance-record'), 'uuid');

		self::assertGreaterThan(16, $flag['metricValue']);
		foreach ($flag['breachingRecordIds'] as $uuid) {
			self::assertSame('absent-unexcused', $marks[$uuid]['status']);
			self::assertSame($flag['learnerId'], $marks[$uuid]['learnerId']);
		}
	}//end testTheLeerplichtFlagListsItsUnexcusedMarks()

	/**
	 * Loads and removes cleanly: the service offers the set with its true
	 * count, and its removal list is every object exactly once, children
	 * before parents.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-po/specs/example-sets/spec.md#requirement-the-primary-school-set-loads-and-removes-cleanly
	 */
	public function testTheServiceOffersAndRemovesExactlyThisSet(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$demo = $this->createMock(DemoDataService::class);
		$demo->method('listChoices')->willReturn([]);
		$service = new SeedProfileService($appManager, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), $demo, $this->createMock(\OCA\Learniq\Service\SharedCodeFilter::class), $this->createMock(\OCA\Learniq\Service\LoadedExampleSets::class));

		$offered = array_values(array_filter($service->listChoices(), static fn (array $c): bool => $c['id'] === 'po'))[0];
		self::of('school');
		$all = [];
		foreach (array_keys((array)self::$objects) as $schema) {
			$all = array_merge($all, self::of($schema));
		}

		self::assertSame(count($all), $offered['objectCount']);

		$uuids = $service->uuidsFor('po');
		self::assertCount(count($all), array_unique($uuids));
		self::assertSame(end($all)['uuid'], $uuids[0], 'the last-loaded object is removed first');
		self::assertSame(self::of('school')[0]['uuid'], end($uuids), 'the school is removed last');
	}//end testTheServiceOffersAndRemovesExactlyThisSet()

	/**
	 * The file is what the generator produces, so nobody edits the JSON by
	 * hand and the rules in scripts/example-sets/po.py stay the truth.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-po/specs/example-sets/spec.md#scenario-the-file-is-reproducible
	 */
	public function testTheFileIsWhatTheGeneratorProduces(): void {
		$python = trim((string)shell_exec('command -v python3 2>/dev/null'));
		if ($python === '') {
			self::markTestSkipped('python3 is not available here; run python3 scripts/example-sets/po.py --check by hand.');
		}

		$script = dirname(__DIR__, 3) . '/scripts/example-sets/po.py';
		exec(escapeshellarg($python) . ' ' . escapeshellarg($script) . ' --check 2>&1', $output, $exitCode);

		self::assertSame(0, $exitCode, implode("\n", $output));
	}//end testTheFileIsWhatTheGeneratorProduces()

	/**
	 * The promoted seed rows live in the set, found by name, and the register
	 * no longer carries them: OpenRegister never read `x-openregister-seed`,
	 * so the rows only ever became reachable by moving.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-po/specs/example-sets/spec.md#requirement-the-register-no-longer-carries-dark-primary-school-seeds
	 */
	public function testThePromotedSeedMovedOutOfTheRegister(): void {
		self::assertContains('Voorbeeldschool De Wilgenboom', array_column(self::of('school'), 'name'));
		self::assertContains('Dependance Noorderpark', array_column(self::of('vestiging'), 'name'));
		self::assertContains('technisch lezen', array_column(self::of('group-plan'), 'subject'));

		$register = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/learniq_register.json'), true);
		$promoted = [
			'School', 'Vestiging', 'Cohort', 'Enrolment', 'ReportPeriod',
			'GroupPlan', 'GroupPlanSubgroup', 'GroupPlanEvaluation', 'Staff', 'SubjectTeacherAssignment',
		];
		foreach ($promoted as $schema) {
			self::assertSame([], ($register['components']['schemas'][$schema]['x-openregister-seed'] ?? []), $schema . ' still carries seed rows');
		}
	}//end testThePromotedSeedMovedOutOfTheRegister()
}//end class
