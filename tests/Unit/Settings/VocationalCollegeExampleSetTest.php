<?php

/**
 * The vocational college (MBO) example set keeps its promises.
 *
 * ExampleSetDescriptorContractTest proves the file loads (shape, uuids,
 * references, schema validity). This test proves the story is consistent: a
 * lesson never falls on a placement day, a mark is made by the teacher of that
 * lesson, a placement is signed, visited and assessed inside its period, a
 * final grade is what the grade engine computes, a first-year decision counts
 * exactly the credits of the passed units, and the removal list covers every
 * object once.
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
 * @spec openspec/changes/segment-example-datasets-mbo/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Grading\GradeAggregationEngine;
use OCA\Learniq\Grading\GradePassEvaluator;
use OCA\Learniq\Service\DemoDataService;
use OCA\Learniq\Service\SeedProfileService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Content and consistency of lib/Settings/profiles/mbo.json.
 */
class VocationalCollegeExampleSetTest extends TestCase {

	/**
	 * Weekday names by ISO day number minus one.
	 *
	 * @var string[]
	 */
	private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

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
			$set = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/profiles/mbo.json'), true);
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
	 * The weekday name of a date or timestamp.
	 *
	 * @param string $when A date or date-time.
	 *
	 * @return string
	 */
	private static function weekday(string $when): string {
		return self::WEEKDAYS[((int)(new \DateTimeImmutable(substr($when, 0, 10)))->format('N')) - 1];
	}//end weekday()

	/**
	 * One college with two locations, three programmes with a
	 * kwalificatiedossier-style framework each, a class per programme and
	 * leerjaar, about 250 students each enrolled once, and a stagecoordinator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-mbo/specs/example-sets/spec.md#requirement-the-vocational-college-set-is-one-consistent-college
	 */
	public function testTheCollegeHasItsPromisedShape(): void {
		self::assertCount(1, self::of('school'));
		self::assertCount(2, self::of('vestiging'));
		$programmes = self::by(self::of('programme'), 'name');
		foreach (['Logistiek medewerker', 'Verzorgende IG', 'Software developer'] as $name) {
			self::assertArrayHasKey($name, $programmes);
			self::assertSame('mbo', $programmes[$name]['level']);
		}

		$frameworks = self::of('competency-framework');
		self::assertGreaterThanOrEqual(3, count($frameworks));
		foreach ($frameworks as $framework) {
			self::assertSame('sbb-kwalificatiedossier', $framework['sourceAuthority']);
		}

		$cohorts = self::by(self::of('cohort'), 'name');
		foreach (['LOG2-1A', 'LOG2-2A', 'VIG3-1A', 'VIG3-2A', 'VIG3-3A', 'SD4-1A', 'SD4-2A', 'SD4-3A'] as $name) {
			self::assertArrayHasKey($name, $cohorts);
		}

		$students = array_values(array_filter(self::of('learner-profile'), static fn (array $p): bool => $p['roles'] === ['learner']));
		self::assertGreaterThanOrEqual(230, count($students));
		self::assertLessThanOrEqual(270, count($students));

		$courses    = self::by(self::of('course'), 'uuid');
		$cohortById = self::by(self::of('cohort'), 'uuid');
		$enrolled   = [];
		foreach (self::of('enrolment') as $enrolment) {
			$cohort = $cohortById[$enrolment['cohortId']];
			self::assertContains($enrolment['learnerId'], $cohort['learnerIds'], $enrolment['slug']);
			self::assertContains($cohort['programmeId'], $courses[$enrolment['courseId']]['programmeIds'], $enrolment['slug']);
			$enrolled[] = $enrolment['learnerId'];
		}

		self::assertEqualsCanonicalizing(array_column($students, 'ncUserId'), $enrolled);
		foreach ($students as $student) {
			self::assertArrayNotHasKey('bsnEncrypted', $student);
		}

		$qualifications = array_merge(...array_column(self::of('staff'), 'qualifications'));
		self::assertContains('stagecoördinator', $qualifications);
		self::assertGreaterThan(100, count(self::of('bpv-placement')));
		self::assertGreaterThan(300, count(self::of('werkproces-assessment')));
		self::assertNotEmpty(self::of('bsa-warning'));
		self::assertNotEmpty(self::of('fraud-case'));
		self::assertNotEmpty(self::of('exemption-case'));
	}//end testTheCollegeHasItsPromisedShape()

	/**
	 * No lesson falls in a holiday, on a study day, or on a day the student
	 * of that class is at the placement (a visit or an assessment day).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-mbo/specs/example-sets/spec.md#requirement-the-vocational-college-set-is-one-consistent-college
	 */
	public function testLessonsNeverFallOnClosedOrPlacementDays(): void {
		$closed = [];
		foreach (self::of('report-period') as $period) {
			foreach ($period['holidays'] as $holiday) {
				for ($d = new \DateTimeImmutable($holiday['startDate']); $d <= new \DateTimeImmutable($holiday['endDate']); $d = $d->modify('+1 day')) {
					$closed[$d->format('Y-m-d')] = true;
				}
			}

			foreach ($period['studyDays'] as $studyDay) {
				$closed[$studyDay['date']] = true;
			}
		}

		$lessonDays = [];
		foreach (self::of('session') as $session) {
			$day = substr($session['startsAt'], 0, 10);
			self::assertArrayNotHasKey($day, $closed, $session['title']);
			$lessonDays[$session['cohortId'] . '|' . $day] = true;
		}

		$enrolment  = self::by(self::of('enrolment'), 'learnerId');
		$placements = self::by(self::of('bpv-placement'), 'uuid');
		$workDays   = [];
		foreach (self::of('bpv-visit-report') as $visit) {
			$workDays[] = [$placements[$visit['bpvPlacementId']], $visit['visitDate'], $visit['slug']];
		}

		foreach (self::of('werkproces-assessment') as $assessment) {
			$workDays[] = [$placements[$assessment['bpvPlacementId']], $assessment['assessedAt'], $assessment['slug']];
		}

		self::assertNotEmpty($workDays);
		foreach ($workDays as [$placement, $day, $slug]) {
			$cohortId = $enrolment[$placement['learnerId']]['cohortId'];
			self::assertArrayNotHasKey($cohortId . '|' . $day, $lessonDays, $slug . ' falls on a lesson day of the class');
			self::assertArrayNotHasKey($day, $closed, $slug);
		}
	}//end testLessonsNeverFallOnClosedOrPlacementDays()

	/**
	 * Every mark sits on a lesson of the student's own class, is marked by a
	 * teacher assigned to that class and unit who works that weekday, and an
	 * excuse covers the day it is linked to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-mbo/specs/example-sets/spec.md#requirement-the-vocational-college-set-is-one-consistent-college
	 */
	public function testEveryMarkIsMadeByTheTeacherOfThatLesson(): void {
		$sessions  = self::by(self::of('session'), 'uuid');
		$enrolment = self::by(self::of('enrolment'), 'learnerId');
		$staff     = self::by(self::of('staff'), 'ncUserId');
		$excuses   = self::by(self::of('excuse-request'), 'uuid');
		$assigned  = [];
		foreach (self::of('subjectteacherassignment') as $assignment) {
			$assigned[$assignment['cohortId'] . '|' . $assignment['courseId']][] = $assignment['teacherId'];
		}

		self::assertGreaterThan(500, count(self::of('attendance-record')));
		foreach (self::of('attendance-record') as $mark) {
			$session = $sessions[$mark['sessionId']];
			self::assertSame($enrolment[$mark['learnerId']]['cohortId'], $session['cohortId'], $mark['slug']);
			self::assertSame($session['cohortId'], $mark['cohortId'], $mark['slug']);
			self::assertContains($mark['markedBy'], $assigned[$session['cohortId'] . '|' . $session['courseId']], $mark['slug']);
			self::assertContains(self::weekday($session['startsAt']), $staff[$mark['markedBy']]['workingDays'], $mark['slug']);
			if ($mark['excuseRequestId'] !== null) {
				$excuse = $excuses[$mark['excuseRequestId']];
				$day    = substr($session['startsAt'], 0, 10);
				self::assertSame($mark['learnerId'], $excuse['learnerId'], $mark['slug']);
				self::assertTrue($day >= $excuse['dateFrom'] && $day <= $excuse['dateTo'], $mark['slug']);
				$expected = 'absent-unexcused';
				if ($excuse['lifecycle'] === 'approved') {
					$expected = 'absent-excused';
				}

				self::assertSame($expected, $mark['status'], $mark['slug']);
			}
		}//end foreach
	}//end testEveryMarkIsMadeByTheTeacherOfThatLesson()

	/**
	 * A placement is signed by student, school and praktijkopleider before it
	 * starts, visited by its BPV-docent and assessed by its praktijkopleider
	 * inside its period, against the werkprocessen of the student's own
	 * programme; each PVB result is the last confirmed assessment of its
	 * component, as WerkprocesGradeEmitHandler writes it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-mbo/specs/example-sets/spec.md#requirement-work-placements-are-signed-visited-and-assessed
	 */
	public function testPlacementsAreSignedVisitedAndAssessed(): void {
		$placements  = self::by(self::of('bpv-placement'), 'uuid');
		$agreements  = self::by(self::of('praktijkovereenkomst'), 'uuid');
		$staff       = self::by(self::of('staff'), 'ncUserId');
		$competency  = self::by(self::of('competency'), 'uuid');
		$programmes  = self::by(self::of('programme'), 'uuid');
		$signatures  = [];
		foreach (self::of('pok-signature') as $signature) {
			$signatures[$signature['subjectId']][$signature['signerRole']] = $signature;
		}

		foreach ($agreements as $uuid => $agreement) {
			$placement = $placements[$agreement['bpvPlacementId']];
			self::assertSame([$placement['periodFrom'], $placement['periodTo']], [$agreement['periodFrom'], $agreement['periodTo']]);
			self::assertEqualsCanonicalizing(['student', 'school', 'praktijkopleider'], array_keys($signatures[$uuid]), $agreement['slug']);
			self::assertSame($placement['learnerId'], $signatures[$uuid]['student']['signerId']);
			self::assertSame($placement['practicalTrainerId'], $signatures[$uuid]['praktijkopleider']['signerId']);
			foreach ($signatures[$uuid] as $signature) {
				self::assertLessThan($agreement['periodFrom'], substr($signature['signedAt'], 0, 10), $signature['slug']);
			}
		}

		self::assertCount(count($placements), $agreements);

		foreach (self::of('bpv-visit-report') as $visit) {
			$placement = $placements[$visit['bpvPlacementId']];
			self::assertTrue($visit['visitDate'] >= $placement['periodFrom'] && $visit['visitDate'] <= $placement['periodTo'], $visit['slug']);
			self::assertSame($placement['schoolCoachId'], $visit['schoolCoachId'], $visit['slug']);
			self::assertContains(self::weekday($visit['visitDate']), $staff[$visit['schoolCoachId']]['workingDays'], $visit['slug']);
		}

		$last = [];
		foreach (self::of('werkproces-assessment') as $assessment) {
			$placement = $placements[$assessment['bpvPlacementId']];
			self::assertSame('completed', $placement['lifecycle'], $assessment['slug']);
			$inPeriod = ($assessment['assessedAt'] >= $placement['periodFrom'] && $assessment['assessedAt'] <= $placement['periodTo']);
			self::assertTrue($inPeriod, $assessment['slug']);
			self::assertSame($placement['practicalTrainerId'], $assessment['assessorId'], $assessment['slug']);
			self::assertSame($placement['curriculumPlanId'], $assessment['curriculumPlanId'], $assessment['slug']);
			$werkproces = $competency[$assessment['competencyId']];
			self::assertSame($assessment['werkprocesCode'], $werkproces['code'], $assessment['slug']);
			self::assertContains($assessment['competencyId'], $programmes[$placement['programmeId']]['requiredCompetencyIds'], $assessment['slug']);
			$key = $placement['learnerId'] . '|' . $assessment['componentId'];
			if (isset($last[$key]) === false || $assessment['assessedAt'] >= $last[$key]['assessedAt']) {
				$last[$key] = $assessment;
			}
		}

		$pvb = array_values(array_filter(self::of('grade-entry'), static fn (array $e): bool => $e['grader'] === 'praktijkopleider'));
		self::assertCount(count($last), $pvb);
		foreach ($pvb as $entry) {
			$expected = 0.0;
			if ($last[$entry['learnerId'] . '|' . $entry['componentId']]['assessment'] === 'competent') {
				$expected = 1.0;
			}

			self::assertEquals($expected, $entry['value'], $entry['slug']);
		}
	}//end testPlacementsAreSignedVisitedAndAssessed()

	/**
	 * Every final grade is what the grade engine computes from the learner's
	 * published entries for that plan, and every learner with a published
	 * entry has one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-mbo/specs/example-sets/spec.md#requirement-results-and-study-advice-agree-with-the-engines
	 */
	public function testFinalGradesAreWhatTheGradeEngineComputes(): void {
		$plans     = self::by(self::of('curriculum-plan'), 'uuid');
		$scales    = self::by(self::of('grade-scale'), 'uuid');
		$published = [];
		foreach (self::of('grade-entry') as $entry) {
			if ($entry['lifecycle'] === 'published') {
				$published[$entry['learnerId'] . '|' . $entry['curriculumPlanId']][] = $entry;
			}
		}

		$aggregation = new GradeAggregationEngine();
		$pass        = new GradePassEvaluator($aggregation);
		$finals      = self::of('final-grade');
		self::assertCount(count($published), $finals);
		foreach ($finals as $final) {
			$plan    = $plans[$final['curriculumPlanId']];
			$entries = $published[$final['learnerId'] . '|' . $final['curriculumPlanId']];
			[$value, $breakdown] = $aggregation->applyFormula($plan['formula'], $entries, $aggregation->indexComponents($plan));
			$passed = $pass->evaluatePassed($plan['formula'], $value, $entries, $plan['passRules'], (float)$scales[$plan['gradeScaleId']]['passThreshold']);

			self::assertEqualsWithDelta($value, $final['value'], 0.00001, $final['slug']);
			self::assertSame($passed, $final['passed'], $final['slug']);
			self::assertEquals($breakdown, $final['breakdown'], $final['slug']);
		}
	}//end testFinalGradesAreWhatTheGradeEngineComputes()

	/**
	 * Every first-year student has one decision; it counts the credits of the
	 * passed units of the programme (as BsaProgressEvaluator does), a negative
	 * decision follows an issued warning and ends the enrolment, and a positive
	 * one meets the norm.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-mbo/specs/example-sets/spec.md#requirement-results-and-study-advice-agree-with-the-engines
	 */
	public function testFirstYearAdviceCountsThePassedUnits(): void {
		$courses   = self::by(self::of('course'), 'uuid');
		$enrolment = self::by(self::of('enrolment'), 'learnerId');
		$warnings  = self::by(self::of('bsa-warning'), 'uuid');
		$earned    = [];
		foreach (self::of('final-grade') as $final) {
			if ($final['passed'] === true) {
				$course = $courses[$final['courseId']];
				foreach ($course['programmeIds'] as $programmeId) {
					$key          = $final['learnerId'] . '|' . $programmeId;
					$earned[$key] = (($earned[$key] ?? 0) + (int)($course['ectsCredits'] ?? 0));
				}
			}
		}

		$firstYears = array_keys(array_filter($enrolment, static fn (array $e): bool => $e['leerjaar'] === 1));
		$decisions  = self::of('bsa-decision');
		self::assertEqualsCanonicalizing($firstYears, array_column($decisions, 'learnerId'));
		$negative = 0;
		foreach ($decisions as $decision) {
			self::assertSame(($earned[$decision['learnerId'] . '|' . $decision['programmeId']] ?? 0), $decision['ectsAchieved'], $decision['slug']);
			if (str_starts_with($decision['decisionType'], 'negative') === true) {
				$negative++;
				self::assertNotEmpty($decision['warningIds'], $decision['slug']);
				foreach ($decision['warningIds'] as $uuid) {
					self::assertSame($decision['learnerId'], $warnings[$uuid]['learnerId']);
					self::assertContains($warnings[$uuid]['lifecycle'], ['issued', 'acknowledged']);
				}

				self::assertSame('withdrawn', $enrolment[$decision['learnerId']]['lifecycle'], $decision['slug']);
			}

			if ($decision['decisionType'] === 'positive') {
				self::assertGreaterThanOrEqual($decision['ectsNormRequired'], $decision['ectsAchieved'], $decision['slug']);
			}
		}//end foreach

		self::assertGreaterThan(0, $negative);
		self::assertCount($negative, array_filter($enrolment, static fn (array $e): bool => $e['lifecycle'] === 'withdrawn'));
	}//end testFirstYearAdviceCountsThePassedUnits()

	/**
	 * Both attendance flags list exactly the marks that crossed their
	 * threshold: sixteen unexcused hours for a minor, and attendance under
	 * 80 percent for an adult.
	 *
	 * @return void
	 */
	public function testAttendanceFlagsListTheirMarks(): void {
		$marks      = self::by(self::of('attendance-record'), 'uuid');
		$thresholds = self::by(self::of('attendance-threshold'), 'uuid');
		self::assertCount(2, self::of('attendance-flag'));
		foreach (self::of('attendance-flag') as $flag) {
			$threshold = $thresholds[$flag['attendanceThresholdId']];
			self::assertNotEmpty($flag['breachingRecordIds']);
			foreach ($flag['breachingRecordIds'] as $uuid) {
				self::assertSame($flag['learnerId'], $marks[$uuid]['learnerId']);
				self::assertStringStartsWith('absent', $marks[$uuid]['status']);
			}

			if ($threshold['kind'] === 'leerplicht-16uur') {
				self::assertGreaterThan($threshold['limit'], $flag['metricValue']);
			} else {
				self::assertLessThan($threshold['limit'], $flag['metricValue']);
			}
		}
	}//end testAttendanceFlagsListTheirMarks()

	/**
	 * Exam board cases point at their results: a granted exemption at an
	 * exemption entry for the same learner and component, a proven fraud at
	 * an invalidated entry followed by a published resit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-mbo/specs/example-sets/spec.md#requirement-results-and-study-advice-agree-with-the-engines
	 */
	public function testExamBoardCasesPointAtTheirResults(): void {
		$entries  = self::by(self::of('grade-entry'), 'uuid');
		$profiles = self::by(self::of('learner-profile'), 'uuid');
		$granted  = 0;
		foreach (self::of('exemption-case') as $case) {
			if ($case['lifecycle'] !== 'granted') {
				self::assertArrayNotHasKey('resultingGradeEntryId', $case);
				continue;
			}

			$granted++;
			$entry = $entries[$case['resultingGradeEntryId']];
			self::assertSame('exemption', $entry['sourceKind']);
			self::assertSame($case['uuid'], $entry['exemptionCaseId']);
			self::assertSame($case['componentId'], $entry['componentId']);
			self::assertSame($profiles[$case['learnerId']]['ncUserId'], $entry['learnerId']);
		}

		self::assertGreaterThan(0, $granted);

		$proven = array_values(array_filter(self::of('fraud-case'), static fn (array $c): bool => ($c['verdict'] ?? null) === 'fraud-proven'));
		self::assertNotEmpty($proven);
		foreach ($proven as $case) {
			$contested = $entries[$case['contestedGradeEntryId']];
			self::assertSame('invalidated', $contested['lifecycle']);
			self::assertSame($case['uuid'], $contested['fraudCaseId']);
			$resits = array_filter(
				self::of('grade-entry'),
				static fn (array $e): bool => $e['learnerId'] === $contested['learnerId']
					&& $e['componentId'] === $contested['componentId']
					&& $e['lifecycle'] === 'published'
					&& $e['gradedAt'] > $case['decidedAt']
			);
			self::assertNotEmpty($resits, $case['slug'] . ' is followed by a resit');
		}
	}//end testExamBoardCasesPointAtTheirResults()

	/**
	 * Loads and removes cleanly: the service offers the set with its true
	 * count, and its removal list is every object exactly once, children
	 * before parents.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-mbo/specs/example-sets/spec.md#requirement-the-vocational-college-set-loads-and-removes-cleanly
	 */
	public function testTheServiceOffersAndRemovesExactlyThisSet(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$demo = $this->createMock(DemoDataService::class);
		$demo->method('listChoices')->willReturn([]);
		$service = new SeedProfileService($appManager, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), $demo);

		$offered = array_values(array_filter($service->listChoices(), static fn (array $c): bool => $c['id'] === 'mbo'))[0];
		self::assertSame('Vocational education (MBO)', $offered['label']);
		self::of('school');
		$all = [];
		foreach (array_keys((array)self::$objects) as $schema) {
			$all = array_merge($all, self::of($schema));
		}

		self::assertSame(count($all), $offered['objectCount']);

		$uuids = $service->uuidsFor('mbo');
		self::assertCount(count($all), array_unique($uuids));
		self::assertSame(end($all)['uuid'], $uuids[0], 'the last-loaded object is removed first');
		self::assertSame(self::of('school')[0]['uuid'], end($uuids), 'the college is removed last');
	}//end testTheServiceOffersAndRemovesExactlyThisSet()

	/**
	 * The file is what the generator produces, so nobody edits the JSON by
	 * hand and the rules in scripts/example-sets/mbo.py stay the truth.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-mbo/specs/example-sets/spec.md#scenario-the-file-is-reproducible
	 */
	public function testTheFileIsWhatTheGeneratorProduces(): void {
		$python = trim((string)shell_exec('command -v python3 2>/dev/null'));
		if ($python === '') {
			self::markTestSkipped('python3 is not available here; run python3 scripts/example-sets/mbo.py --check by hand.');
		}

		$script = dirname(__DIR__, 3) . '/scripts/example-sets/mbo.py';
		exec(escapeshellarg($python) . ' ' . escapeshellarg($script) . ' --check 2>&1', $output, $exitCode);

		self::assertSame(0, $exitCode, implode("\n", $output));
	}//end testTheFileIsWhatTheGeneratorProduces()
}//end class
