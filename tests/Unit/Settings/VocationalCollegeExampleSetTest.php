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
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Grading\GradeAggregationEngine;
use OCA\Learniq\Grading\GradePassEvaluator;
use OCA\Learniq\Lifecycle\Action\PokParentSignatureStampAction;
use OCA\Learniq\Lifecycle\PokActivationGuard;
use OCA\Learniq\Service\DemoDataService;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\PokParentSignatureRule;
use OCA\Learniq\Service\SeedProfileService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;

/**
 * Content and consistency of lib/Settings/profiles/mbo.json.
 */
class VocationalCollegeExampleSetTest extends TestCase {
	use RegisterSchemaPayloads;


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
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-vocational-college-set-is-one-consistent-college
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-vocational-college-set-is-one-consistent-college
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-vocational-college-set-is-one-consistent-college
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-work-placements-are-signed-visited-and-assessed
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
			$roles = ['student', 'school', 'praktijkopleider'];
			if ($agreement['parentSignatureRequired'] === true) {
				$roles[] = 'parent';
			}

			self::assertEqualsCanonicalizing($roles, array_keys($signatures[$uuid]), $agreement['slug']);
			self::assertSame($placement['learnerId'], $signatures[$uuid]['student']['signerId']);
			self::assertSame($placement['practicalTrainerId'], $signatures[$uuid]['praktijkopleider']['signerId']);
			foreach ($signatures[$uuid] as $signature) {
				self::assertLessThan($agreement['periodFrom'], substr($signature['signedAt'], 0, 10), $signature['slug']);
			}
		}

		self::assertEqualsCanonicalizing(array_keys($placements), array_column($agreements, 'bpvPlacementId'), 'one agreement per placement');

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-results-and-study-advice-agree-with-the-engines
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
		$pairs       = array_map(static fn (array $f): string => $f['learnerId'] . '|' . $f['curriculumPlanId'], $finals);
		self::assertEqualsCanonicalizing(array_keys($published), $pairs, 'one final grade per learner and plan with a published entry');
		foreach ($finals as $final) {
			$plan    = $plans[$final['curriculumPlanId']];
			$entries = $published[$final['learnerId'] . '|' . $final['curriculumPlanId']];
			[$value, $breakdown] = $aggregation->applyFormula($plan['formula'], $entries, $aggregation->indexComponents($plan));
			$passed = $pass->evaluatePassed($plan['formula'], $value, $entries, $plan['passRules'], (float)$scales[$plan['gradeScaleId']]['passThreshold'], $aggregation->indexComponents($plan));

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-results-and-study-advice-agree-with-the-engines
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-results-and-study-advice-agree-with-the-engines
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-vocational-college-set-loads-and-removes-cleanly
	 */
	public function testTheServiceOffersAndRemovesExactlyThisSet(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$demo = $this->createMock(DemoDataService::class);
		$demo->method('listChoices')->willReturn([]);
		$service = new SeedProfileService($appManager, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), $demo, $this->createMock(\OCA\Learniq\Service\SharedCodeFilter::class), $this->createMock(\OCA\Learniq\Service\LoadedExampleSets::class), $this->createMock(\OCA\Learniq\Portal\ExamplePortalProvisioner::class), $this->createMock(\OCA\Learniq\Service\ExampleSetDates::class));

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
	 * Every agreement went active, so the real activation guard accepts its
	 * signatures, and its parent flag is what the stamp action writes: a
	 * minor's agreement carries a parent signature from a parent listed on
	 * the student's profile, an adult's carries none. At least one agreement
	 * of each kind exists.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	public function testEveryAgreementPassesTheActivationGuardAndCarriesTheRightParentFlag(): void {
		$rows = [
			'bpv-placement' => self::by(self::of('bpv-placement'), 'uuid'),
			'learner-profile' => self::by(self::of('learner-profile'), 'uuid'),
		];
		$signaturesBySubject = [];
		foreach (self::of('pok-signature') as $signature) {
			$signaturesBySubject[$signature['subjectId']][] = $signature;
		}

		$store = $this->createMock(ObjectService::class);
		$store->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($rows) {
				if (isset($rows[(string)$schema][(string)$id]) === false) {
					throw new DoesNotExistException('not in the set');
				}

				return OrEntityFactory::make($rows[(string)$schema][(string)$id], (string)$schema);
			}
		);
		$store->method('findAll')->willReturnCallback(
			static fn (array $config): array => ($signaturesBySubject[$config['filters']['subjectId'] ?? ''] ?? [])
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2025-08-18 08:00:00', new DateTimeZone('Europe/Amsterdam')));
		$rule   = new PokParentSignatureRule($store, new LearnerRefResolver($store), $time);
		$guard  = new PokActivationGuard($store, $this->createMock(LoggerInterface::class), $rule);
		$stamp  = new PokParentSignatureStampAction($rule, $store);
		$minors = 0;

		foreach (self::of('praktijkovereenkomst') as $agreement) {
			$pok = array_merge($agreement, ['id' => $agreement['uuid']]);
			self::assertTrue($guard->check($pok, 'activate', 'mbo-stagecoordinator-01')->isAllowed(), $agreement['slug'] . ' passes the activation guard');
			self::assertSame($stamp->execute($pok, [], [], 'activate')['parentSignatureRequired'], $agreement['parentSignatureRequired'], $agreement['slug']);

			$parentSigners = array_column(array_filter($signaturesBySubject[$agreement['uuid']], static fn (array $s): bool => $s['signerRole'] === 'parent'), 'signerId');
			if ($agreement['parentSignatureRequired'] === false) {
				self::assertSame([], $parentSigners, $agreement['slug'] . ' is an adult\'s agreement');
				continue;
			}

			$minors++;
			$profile = $rows['learner-profile'][$rows['bpv-placement'][$agreement['bpvPlacementId']]['learnerRef']];
			self::assertCount(1, $parentSigners, $agreement['slug']);
			self::assertContains($parentSigners[0], $profile['parentIds'], $agreement['slug'] . ' is signed by a listed parent');
		}

		self::assertGreaterThan(0, $minors);
		self::assertLessThan(count(self::of('praktijkovereenkomst')), $minors);
	}//end testEveryAgreementPassesTheActivationGuardAndCarriesTheRightParentFlag()

	/**
	 * The file is what the generator produces, so nobody edits the JSON by
	 * hand and the rules in scripts/example-sets/mbo.py stay the truth.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-the-file-is-reproducible
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

	/**
	 * The set gives the examenportaal somebody to sign in as and something to
	 * read: an active assessor, and two active shares that name their
	 * candidate and portfolio the way the server stamps them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/example-sets/spec.md#requirement-the-vocational-set-seeds-an-assessor-with-work-to-read
	 */
	public function testTheSetSeedsAnAssessorWithPortfoliosToRead(): void {
		$assessors = self::of('external-assessor');
		self::assertCount(1, $assessors);
		$assessor = $assessors[0];
		self::assertTrue($assessor['active']);
		self::assertNotEmpty($assessor['email']);

		$portfolios = self::by(self::of('portfolio'), 'uuid');
		$learners = self::by(self::of('learner-profile'), 'uuid');
		$shares = self::of('portfolio-share');
		self::assertGreaterThanOrEqual(2, count($shares));

		foreach ($shares as $share) {
			self::assertSame('external-assessor', $share['sharedWithKind'], $share['slug']);
			self::assertSame($assessor['uuid'], $share['sharedWithExternalAssessorId'], $share['slug']);
			// Only an active grant resolves for the assessor's collection.
			self::assertSame('active', $share['lifecycle'], $share['slug']);
			self::assertArrayHasKey($share['portfolioId'], $portfolios, $share['slug'] . ' shares a portfolio of this set');

			// The readable copies match the rows they were copied from, so the
			// seed says what a live save would have stamped.
			$portfolio = $portfolios[$share['portfolioId']];
			self::assertSame($portfolio['title'], $share['portfolioTitle'], $share['slug']);
			$learner = $learners[$portfolio['learnerRef']];
			self::assertSame($learner['givenName'] . ' ' . $learner['familyName'], $share['learnerName'], $share['slug']);
		}
	}//end testTheSetSeedsAnAssessorWithPortfoliosToRead()

	/**
	 * Every new row passes the fragment that will validate it, and a share
	 * without its portfolio does not.
	 *
	 * @return void
	 */
	public function testTheNewRowsPassTheRealSchemas(): void {
		$strip = static function (array $row): array {
			unset($row['@self'], $row['uuid'], $row['slug']);
			return $row;
		};

		foreach (['external-assessor', 'portfolio', 'portfolio-entry', 'portfolio-share', 'bpv-hour-week'] as $schema) {
			foreach (self::of($schema) as $row) {
				self::assertNull(self::schemaError(slug: $schema, payload: $strip($row)), $schema . ' ' . ($row['slug'] ?? '?'));
			}
		}

		$share = $strip(self::of('portfolio-share')[0]);
		unset($share['portfolioId']);
		self::assertNotNull(self::schemaError(slug: 'portfolio-share', payload: $share), 'control: a share names its portfolio');
	}//end testTheNewRowsPassTheRealSchemas()

	/**
	 * The set seeds weeks of realised BPV hours: for every work placement unit
	 * two students have a run of weeks, with one week still waiting for the
	 * praktijkopleider and one she corrected, and the placement states both
	 * the hours its agreement promised and the hours approved so far.
	 *
	 * WHY THE TOTAL IS ASSERTED AGAINST THE WEEKS. `hoursApprovedTotal` is the
	 * number the trainer's progress card reads, and on a live instance
	 * HourWeekTotalRollup writes it. A seed whose total disagreed with its own
	 * weeks would put a figure on screen that no week supports.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-hours-are-shown-against-the-hours-that-were-agreed
	 */
	public function testTheSetSeedsWeeksOfRealisedHours(): void {
		$placements = self::by(self::of('bpv-placement'), 'uuid');
		$trainers = self::by(self::of('praktijkopleider'), 'uuid');
		$weeks = self::of('bpv-hour-week');
		self::assertNotEmpty($weeks);

		$perPlacement = [];
		$story        = [];
		foreach ($weeks as $week) {
			self::assertArrayHasKey($week['bpvPlacementId'], $placements, $week['slug'] . ' names a placement of this set');
			$placement = $placements[$week['bpvPlacementId']];
			// The student the week is about is the student of its placement.
			self::assertSame($placement['learnerRef'], $week['learnerRef'], $week['slug']);
			self::assertSame($placement['learnerRef'], $week['submittedBy'], $week['slug']);
			self::assertMatchesRegularExpression('/^\d{4}-W\d{2}$/', $week['isoWeek'], $week['slug']);
			$perPlacement[$week['bpvPlacementId']][] = $week;
			// The Esdoornveen story's placements follow their boards, not the
			// pattern below; testTheEsdoornveenStoryIsInTheSet asserts them.
			if ($placement['trainingCompanyName'] === 'Bakker Techniek BV') {
				$story[$week['bpvPlacementId']] = true;
			}
		}

		self::assertNotEmpty($story, 'the story placements carry weeks');

		// Every work placement unit of the school year has weeks, so every
		// trainer in the set has something waiting for her.
		$units = [];
		foreach (array_keys($perPlacement) as $placementId) {
			if (isset($story[$placementId]) === false) {
				$units[$placements[$placementId]['curriculumPlanId']] = true;
			}
		}

		self::assertCount(5, $units, 'every BPV unit has a placement with weeks');

		foreach ($perPlacement as $placementId => $rows) {
			$placement = $placements[$placementId];
			$label = $placement['slug'];
			$states = array_column($rows, 'lifecycle');
			if (isset($story[$placementId]) === false) {
				self::assertSame(1, count(array_keys($states, 'submitted', true)), $label . ' has one week still waiting');
				self::assertSame(1, count(array_keys($states, 'corrected', true)), $label . ' has one corrected week');
			}

			$total = 0.0;
			foreach ($rows as $week) {
				if ($week['lifecycle'] === 'submitted') {
					// Nobody has decided it, so there is no approved number and
					// no trainer on it yet.
					self::assertArrayNotHasKey('hoursApproved', $week, $week['slug']);
					self::assertArrayNotHasKey('approvedBy', $week, $week['slug']);
					continue;
				}

				self::assertArrayHasKey($week['approvedBy'], $trainers, $week['slug'] . ' names a trainer of this set');
				// The trainer who approved is the trainer of the placement.
				self::assertSame($placement['practicalTrainerId'], $week['approvedBy'], $week['slug']);
				$trainer = $trainers[$week['approvedBy']];
				self::assertSame($trainer['givenName'] . ' ' . $trainer['familyName'], $week['approvedByName'], $week['slug']);
				self::assertSame('basic', $week['assuranceLevel'], $week['slug']);

				if ($week['lifecycle'] === 'corrected') {
					// A correction keeps what the student entered and says why.
					self::assertLessThan($week['hoursSubmitted'], $week['hoursApproved'], $week['slug']);
					self::assertNotEmpty($week['note'], $week['slug']);
				} else if ($week['lifecycle'] === 'rejected') {
					// Sent back: none approved, and the note says why.
					self::assertEquals(0, $week['hoursApproved'], $week['slug']);
					self::assertNotEmpty($week['note'], $week['slug']);
				} else {
					self::assertSame($week['hoursSubmitted'], $week['hoursApproved'], $week['slug']);
				}

				$total += (float)$week['hoursApproved'];
			}

			self::assertSame($total, (float)$placement['hoursApprovedTotal'], $label . ' states the hours its weeks add up to');
			// And the denominator the card counts against really exists.
			self::assertGreaterThan(0, (float)$placement['agreedHours'], $label);
		}

		// Every placement of the set states its agreed hours, not only the ones
		// that have weeks: a card without a total shows no bar at all.
		foreach ($placements as $placement) {
			self::assertArrayHasKey('agreedHours', $placement, $placement['slug']);
		}
	}//end testTheSetSeedsWeeksOfRealisedHours()

	/**
	 * The Esdoornveen story is in the set, and its numbers come out of the
	 * rows the way the boards show them: Milan de Groot in Mechatronica
	 * niveau 4, leerjaar 2, on his placement at Bakker Techniek BV with Petra
	 * Bakker; 96 hours approved, 16 waiting, 8 sent back and 360 still to do
	 * of 480; the tussenbeoordeling, the voortgangsgesprek, the exam and the
	 * lessons of week 41; and Aylin Demir as Petra's second student.
	 *
	 * WHY WEEK 40 IS TWO ROWS. A `bpv-hour-week` has no per-day lines
	 * (deviation D-7), so "8 of the 24 hours sent back" cannot be one row. The
	 * Monday and Wednesday wait as one submitted row; the Tuesday is a row
	 * Petra approved none of (`rejected`), and her note names the day.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-sets-are-the-four-schools/specs/example-sets/spec.md
	 */
	public function testTheEsdoornveenStoryIsInTheSet(): void {
		self::assertSame('Esdoornveen', self::of('school')[0]['name']);
		$streets = array_column(self::of('vestiging'), 'street');
		self::assertContains('Esdoornlaan 40', $streets);
		self::assertSame(['Zuiddrecht'], array_values(array_unique(array_column(self::of('vestiging'), 'city'))));

		// The fourth programme, with its crebo and its six werkprocessen.
		$programme = self::by(self::of('programme'), 'name')['Mechatronica'];
		self::assertSame('mbo', $programme['level']);
		self::assertStringContainsString('25743', $programme['description']);
		$framework   = array_values(array_filter(self::of('competency-framework'), static fn (array $f): bool => ($f['sourceRef'] ?? null) === '25743'))[0];
		$werkproces  = [];
		foreach (self::of('competency') as $competency) {
			if ($competency['frameworkId'] === $framework['uuid'] && str_contains($competency['code'], '-W') === true) {
				$werkproces[$competency['code']] = $competency['title'];
				self::assertContains($competency['uuid'], $programme['requiredCompetencyIds'], $competency['slug']);
			}
		}

		self::assertSame(
			[
				'B1-K1-W1' => 'Bereidt het werk voor',
				'B1-K1-W2' => 'Maakt onderdelen',
				'B1-K1-W3' => 'Bouwt mechatronische systemen op',
				'B1-K1-W4' => 'Test en stelt systemen af',
				'B1-K2-W1' => 'Lokaliseert storingen',
				'B1-K2-W2' => 'Voert onderhoud uit',
			],
			$werkproces
		);

		// The class and its two students.
		$cohort = self::by(self::of('cohort'), 'name')['MT4-2A'];
		self::assertSame($programme['uuid'], $cohort['programmeId']);
		self::assertSame(2, $cohort['programmeYear']);
		$people = [];
		foreach (self::of('learner-profile') as $profile) {
			$people[($profile['givenName'] ?? '') . ' ' . ($profile['familyName'] ?? '')] = $profile;
		}

		$milan = $people['Milan de Groot'];
		$aylin = $people['Aylin Demir'];
		self::assertSame('mbo-student-251', $milan['ncUserId']);
		self::assertSame(['mbo-student-251', 'mbo-student-252'], $cohort['learnerIds']);
		$enrolment = self::by(self::of('enrolment'), 'learnerId')[$milan['ncUserId']];
		self::assertSame(2, $enrolment['leerjaar']);
		self::assertSame($cohort['uuid'], $enrolment['cohortId']);

		// The leerbedrijf, the trainer and the BPV-begeleider.
		$staff  = self::by(self::of('staff'), 'ncUserId');
		$petras = array_values(array_filter(self::of('praktijkopleider'), static fn (array $t): bool => $t['givenName'] === 'Petra' && $t['familyName'] === 'Bakker'));
		self::assertCount(1, $petras);
		$petra = $petras[0];
		self::assertTrue($petra['active'], 'an active row, so the trainer can be invited');
		self::assertNotEmpty($petra['email']);
		self::assertSame('Bakker Techniek BV', $petra['trainingCompanyName']);
		foreach (['mbo-docent-15', 'mbo-docent-16', 'mbo-examencommissie-02'] as $ncUserId) {
			self::assertArrayHasKey($ncUserId, $staff, $ncUserId . ' (Ruud Hermans, Fenna Yilmaz, Karin de Boer)');
		}

		$placements = [];
		foreach (self::of('bpv-placement') as $placement) {
			$placements[$placement['learnerRef']][] = $placement;
		}

		self::assertCount(1, $placements[$milan['uuid']]);
		$placement = $placements[$milan['uuid']][0];
		self::assertSame(['2026-08-31', '2027-01-29', 480], [$placement['periodFrom'], $placement['periodTo'], $placement['agreedHours']]);
		self::assertSame($petra['uuid'], $placement['practicalTrainerId']);
		self::assertSame('mbo-docent-15', $placement['schoolCoachId']);
		self::assertSame('active', $placement['lifecycle']);
		$pok = array_values(array_filter(self::of('praktijkovereenkomst'), static fn (array $p): bool => $p['bpvPlacementId'] === $placement['uuid']))[0];
		foreach (self::of('pok-signature') as $signature) {
			if ($signature['subjectId'] === $pok['uuid']) {
				self::assertSame('2026-08-27', substr($signature['signedAt'], 0, 10), $signature['slug']);
			}
		}

		// The hours, counted from the weeks the way the student page counts them.
		$hours = ['approved' => 0.0, 'waiting' => 0.0, 'returned' => 0.0];
		foreach (self::of('bpv-hour-week') as $week) {
			if ($week['bpvPlacementId'] !== $placement['uuid']) {
				continue;
			}

			match ($week['lifecycle']) {
				'approved', 'corrected' => $hours['approved'] += (float)$week['hoursApproved'],
				'submitted' => $hours['waiting'] += (float)$week['hoursSubmitted'],
				'rejected' => $hours['returned'] += (float)$week['hoursSubmitted'],
			};
			if ($week['lifecycle'] === 'rejected') {
				self::assertSame('2026-W40', $week['isoWeek']);
				self::assertStringContainsString('29 september', $week['note']);
			}
		}

		self::assertEquals(['approved' => 96, 'waiting' => 16, 'returned' => 8], $hours);
		self::assertEquals(360, $placement['agreedHours'] - array_sum($hours), 'still to do');
		self::assertEquals(96, $placement['hoursApprovedTotal']);

		$aylinPlacement = $placements[$aylin['uuid']][0];
		self::assertSame($petra['uuid'], $aylinPlacement['practicalTrainerId']);
		self::assertEquals([640, 160], [$aylinPlacement['agreedHours'], $aylinPlacement['hoursApprovedTotal']]);

		// The dates of the overview.
		$visits = [];
		foreach (self::of('bpv-visit-report') as $visit) {
			if ($visit['bpvPlacementId'] === $placement['uuid']) {
				$visits[$visit['visitDate']] = $visit;
			}
		}

		self::assertSame('tussentijds-gesprek', $visits['2026-10-13']['visitKind']);
		self::assertSame('draft', $visits['2026-10-13']['lifecycle'], 'the tussenbeoordeling has not happened yet');
		self::assertArrayHasKey('2026-09-09', $visits, 'the werkplan visit');

		$rooms = self::by(self::of('room'), 'uuid');
		$slot  = self::of('conference-slot')[0];
		self::assertSame(['2026-10-15T15:15:00+02:00', 'mbo-docent-16', 'mbo-student-251'], [$slot['startsAt'], $slot['teacherId'], $slot['learnerId']]);
		self::assertStringContainsString('B2.11', $slot['location']);
		$sitting = self::of('exam-sitting')[0];
		self::assertSame('2026-11-03T09:00:00+01:00', $sitting['startsAt']);
		self::assertSame('B1.08', $rooms[$sitting['roomIds'][0]]['code']);
		self::assertSame('Examen Nederlands lezen en luisteren', self::by(self::of('exam'), 'uuid')[$sitting['assessmentId']]['title']);

		// The lessons of Thursday 8 and Friday 9 October.
		$courses = self::by(self::of('course'), 'uuid');
		$days    = [];
		foreach (self::of('session') as $session) {
			if ($session['cohortId'] === $cohort['uuid']) {
				$days[substr($session['startsAt'], 0, 10)][$session['startsAt']] = $session;
			}
		}

		self::assertCount(4, $days['2026-10-08']);
		self::assertCount(3, $days['2026-10-09']);
		ksort($days['2026-10-08']);
		ksort($days['2026-10-09']);
		$thursday = array_values($days['2026-10-08']);
		$friday   = array_values($days['2026-10-09']);
		self::assertSame(['PLC-programmeren', 'T0.14'], [$courses[$thursday[0]['courseId']]['name'], $rooms[$thursday[0]['roomId']]['code']]);
		self::assertSame('2026-10-08T15:00:00+02:00', $thursday[3]['endsAt']);
		self::assertSame(['Nederlands', 'B1.08'], [$courses[$friday[0]['courseId']]['name'], $rooms[$friday[0]['roomId']]['code']]);
		self::assertSame('2026-10-09T12:15:00+02:00', $friday[2]['endsAt']);
		self::assertSame(['Engels', 'cancelled'], [$courses[$friday[1]['courseId']]['name'], $friday[1]['lifecycle']]);
	}//end testTheEsdoornveenStoryIsInTheSet()
}//end class
