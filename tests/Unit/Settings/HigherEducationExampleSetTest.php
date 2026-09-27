<?php

/**
 * The higher education example set keeps its promises.
 *
 * ExampleSetDescriptorContractTest proves the file loads (shape, uuids,
 * references, schema validity). This test proves the story is consistent: a
 * final grade is what the grade engine computes from the published entries, a
 * binding study advice counts exactly the credits of the passed final grades
 * and a negative one follows a warning, an item statistic matches the stored
 * responses, a peer reviewer never reviews their own group, and the removal
 * list covers every object once.
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
 * @spec openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md
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
 * Content and consistency of lib/Settings/profiles/he.json.
 */
class HigherEducationExampleSetTest extends TestCase {

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
			$set = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/profiles/he.json'), true);
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
	 * The first object of a schema whose field has the given value.
	 *
	 * @param string $schema The schema slug.
	 * @param string $field  The field.
	 * @param mixed  $value  The value.
	 *
	 * @return array<string, mixed>
	 */
	private static function find(string $schema, string $field, mixed $value): array {
		foreach (self::of(schema: $schema) as $row) {
			if (($row[$field] ?? null) === $value) {
				return $row;
			}
		}

		self::fail('no ' . $schema . ' with ' . $field . ' ' . json_encode($value));
	}//end find()

	/**
	 * The students of one intake cohort, found by the cohort's name.
	 *
	 * @param string $suffix The end of the cohort name, e.g. "cohort 2025".
	 *
	 * @return array<int, string> Nextcloud user ids.
	 */
	private static function studentsOfIntake(string $suffix): array {
		$ids = [];
		foreach (self::of(schema: 'cohort') as $cohort) {
			if (str_ends_with($cohort['name'], $suffix) === true) {
				$ids = array_merge($ids, $cohort['learnerIds']);
			}
		}

		return $ids;
	}//end studentsOfIntake()

	/**
	 * Students who left during the year: every enrolment they hold after
	 * block 1 is withdrawn.
	 *
	 * @return array<string, bool>
	 */
	private static function withdrawn(): array {
		$out = [];
		foreach (self::of(schema: 'enrolment') as $enrolment) {
			if ($enrolment['lifecycle'] === 'withdrawn') {
				$out[$enrolment['learnerId']] = true;
			}
		}

		return $out;
	}//end withdrawn()

	/**
	 * One institution, two faculties, four programmes with learning outcomes
	 * on the Dublin descriptors, about 400 students each in one cohort and
	 * enrolled in the courses of their own programme, study advisers on Staff.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md#requirement-the-higher-education-set-is-one-consistent-institution
	 */
	public function testTheInstitutionHasItsPromisedShape(): void {
		self::assertSame('00X4', self::find(schema: 'school', field: 'name', value: 'Voorbeeldhogeschool Esdoornstad')['brin']);
		$faculties = array_column(self::of(schema: 'vestiging'), 'name');
		self::assertContains('Faculteit Gezondheid en Welzijn, Campus Zuid', $faculties);
		self::assertContains('Faculteit Techniek en ICT, Campus Noord', $faculties);

		$competencies = self::by(rows: self::of(schema: 'competency'), field: 'uuid');
		$dublin       = ['Kennis en inzicht', 'Toepassen van kennis en inzicht', 'Oordeelsvorming', 'Communicatie', 'Leervaardigheden'];
		foreach (['HBO-V Verpleegkunde', 'Social Work', 'HBO-ICT', 'Werktuigbouwkunde'] as $name) {
			$programme = self::find(schema: 'programme', field: 'name', value: $name);
			$firstYear = 0;
			foreach (self::of(schema: 'course') as $course) {
				if (in_array($programme['uuid'], $course['programmeIds'], true) === false) {
					continue;
				}

				self::assertGreaterThan(0, $course['ectsCredits'], $course['slug'] . ' carries study credits');
				self::assertContains($course['uuid'], $programme['courseIds']);
				if (in_array('jaar 1', $course['tags'], true) === true) {
					$firstYear += $course['ectsCredits'];
				}
			}

			self::assertSame(60, $firstYear, $name . ' has a propedeuse of 60 credits');
			self::assertGreaterThanOrEqual(10, count($programme['requiredCompetencyIds']));
			foreach ($programme['requiredCompetencyIds'] as $uuid) {
				$outcome = $competencies[$uuid];
				self::assertContains($competencies[$outcome['parentId']]['title'], $dublin, $outcome['code'] . ' sits under a Dublin descriptor');
			}
		}//end foreach

		$students = array_values(array_filter(self::of(schema: 'learner-profile'), static fn (array $p): bool => $p['roles'] === ['learner']));
		self::assertGreaterThanOrEqual(380, count($students));
		self::assertLessThanOrEqual(420, count($students));

		$cohortOf = [];
		foreach (self::of(schema: 'cohort') as $cohort) {
			foreach ($cohort['learnerIds'] as $learnerId) {
				self::assertArrayNotHasKey($learnerId, $cohortOf, $learnerId . ' is in one cohort only');
				$cohortOf[$learnerId] = $cohort;
			}
		}

		$courses  = self::by(rows: self::of(schema: 'course'), field: 'uuid');
		$enrolled = [];
		foreach (self::of(schema: 'enrolment') as $enrolment) {
			$cohort = $cohortOf[$enrolment['learnerId']];
			self::assertSame($cohort['uuid'], $enrolment['cohortId'], $enrolment['slug']);
			self::assertContains($cohort['programmeId'], $courses[$enrolment['courseId']]['programmeIds'], $enrolment['slug']);
			$enrolled[$enrolment['learnerId']] = true;
		}

		foreach ($students as $student) {
			self::assertArrayHasKey($student['ncUserId'], $cohortOf);
			self::assertArrayHasKey($student['ncUserId'], $enrolled);
			self::assertSame([], $student['parentIds']);
			self::assertArrayNotHasKey('bsnEncrypted', $student);
		}

		$advisers = array_filter(self::of(schema: 'staff'), static fn (array $s): bool => str_starts_with(($s['qualifications'][0] ?? ''), 'Studieadviseur'));
		self::assertGreaterThanOrEqual(4, count($advisers));
	}//end testTheInstitutionHasItsPromisedShape()

	/**
	 * Every final grade is exactly what the grade engine computes from the
	 * published entries of its plan, and an enrolment is completed when its
	 * final grade passed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md#requirement-the-higher-education-set-is-one-consistent-institution
	 */
	public function testEveryFinalGradeIsWhatTheEngineComputes(): void {
		$engine = new GradeAggregationEngine();
		$pass   = new GradePassEvaluator($engine);
		$plans  = self::by(rows: self::of(schema: 'curriculum-plan'), field: 'uuid');
		$scales = self::by(rows: self::of(schema: 'grade-scale'), field: 'uuid');

		$entries = [];
		foreach (self::of(schema: 'grade-entry') as $entry) {
			if ($entry['lifecycle'] === 'published') {
				$entries[$entry['learnerId'] . '|' . $entry['curriculumPlanId']][] = $entry;
			}
		}

		$enrolments = [];
		foreach (self::of(schema: 'enrolment') as $enrolment) {
			$enrolments[$enrolment['learnerId'] . '|' . $enrolment['courseId']] = $enrolment;
		}

		self::assertGreaterThan(1000, count(self::of(schema: 'final-grade')));
		$graded = [];
		foreach (self::of(schema: 'final-grade') as $final) {
			self::assertArrayNotHasKey($final['learnerId'] . '|' . $final['courseId'], $graded, $final['slug'] . ' is the only final grade of its course');
			$graded[$final['learnerId'] . '|' . $final['courseId']] = $final['curriculumPlanId'];
			$plan = $plans[$final['curriculumPlanId']];
			$rows = ($entries[$final['learnerId'] . '|' . $final['curriculumPlanId']] ?? []);
			self::assertNotEmpty($rows, $final['slug'] . ' has published entries');

			[$value, $breakdown] = $engine->applyFormula($plan['formula'], $rows, $engine->indexComponents($plan));
			self::assertEqualsWithDelta($value, $final['value'], 0.00001, $final['slug']);
			self::assertEquals($breakdown, $final['breakdown'], $final['slug']);

			$passed = $pass->evaluatePassed($plan['formula'], $value, $rows, $plan['passRules'], (float)$scales[$plan['gradeScaleId']]['passThreshold']);
			self::assertSame($passed, $final['passed'], $final['slug']);

			$expected = 'failed';
			if ($passed === true) {
				$expected = 'completed';
			}

			self::assertSame($expected, $enrolments[$final['learnerId'] . '|' . $final['courseId']]['lifecycle'], $final['slug']);
		}//end foreach

		// The other direction: a finished enrolment has a final grade, a
		// withdrawn one has none, and no published entry is left without one.
		foreach ($enrolments as $key => $enrolment) {
			self::assertSame($enrolment['lifecycle'] !== 'withdrawn', isset($graded[$key]), $enrolment['slug']);
		}

		$gradedPlans = [];
		foreach ($graded as $key => $planId) {
			$gradedPlans[explode('|', $key)[0] . '|' . $planId] = true;
		}

		self::assertEqualsCanonicalizing(array_keys($entries), array_keys($gradedPlans));
	}//end testEveryFinalGradeIsWhatTheEngineComputes()

	/**
	 * Every first-year student who stayed gets one binding study advice that
	 * counts exactly the credits of their passed final grades; a negative
	 * advice follows an issued warning and a hearing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md#requirement-the-binding-study-advice-follows-the-grades
	 */
	public function testTheBindingStudyAdviceCountsThePassedCredits(): void {
		$courses  = self::by(rows: self::of(schema: 'course'), field: 'uuid');
		$warnings = self::by(rows: self::of(schema: 'bsa-warning'), field: 'uuid');
		$left     = self::withdrawn();

		$decided = [];
		foreach (self::of(schema: 'bsa-decision') as $decision) {
			$earned = 0.0;
			foreach (self::of(schema: 'final-grade') as $final) {
				if ($final['learnerId'] === $decision['learnerId'] && $final['passed'] === true
					&& in_array($decision['programmeId'], $courses[$final['courseId']]['programmeIds'], true) === true
				) {
					$earned += (float)$courses[$final['courseId']]['ectsCredits'];
				}
			}

			self::assertSame($earned, (float)$decision['ectsAchieved'], $decision['slug']);
			self::assertSame($earned >= $decision['ectsNormRequired'], $decision['decisionType'] === 'positive', $decision['slug']);
			if (str_starts_with($decision['decisionType'], 'negative') === true) {
				self::assertNotEmpty($decision['warningIds'], $decision['slug'] . ' follows a warning');
				foreach ($decision['warningIds'] as $uuid) {
					self::assertSame($decision['learnerId'], $warnings[$uuid]['learnerId']);
					self::assertContains($warnings[$uuid]['lifecycle'], ['issued', 'acknowledged']);
				}

				self::assertNotEmpty($decision['rationale']);
				self::assertNotEmpty($decision['studentHeardAt']);
			}

			self::assertArrayNotHasKey($decision['learnerId'], $decided, $decision['slug'] . ' is the only advice of its student');
			$decided[$decision['learnerId']] = true;
		}//end foreach

		foreach (self::studentsOfIntake(suffix: 'cohort 2025') as $learnerId) {
			self::assertSame(isset($left[$learnerId]) === false, isset($decided[$learnerId]), $learnerId . ' has an advice unless they left');
		}

		self::assertContains('negative', array_column(self::of(schema: 'bsa-decision'), 'decisionType'));
		foreach (self::of(schema: 'bsa-progress-flag') as $flag) {
			self::assertLessThan($flag['ectsRequiredAtCheck'], $flag['ectsEarned'], $flag['slug']);
		}
	}//end testTheBindingStudyAdviceCountsThePassedCredits()

	/**
	 * Every mark sits on a workgroup of the student's own cohort, marked by
	 * the teacher of that course, and an attendance flag lists absences only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md#requirement-the-higher-education-set-is-one-consistent-institution
	 */
	public function testEveryMarkBelongsToTheStudentsOwnWorkgroup(): void {
		$sessions = self::by(rows: self::of(schema: 'session'), field: 'uuid');
		$cohortOf = [];
		foreach (self::of(schema: 'enrolment') as $enrolment) {
			$cohortOf[$enrolment['learnerId']] = $enrolment['cohortId'];
		}

		$teacher = [];
		foreach (self::of(schema: 'subjectteacherassignment') as $assignment) {
			$teacher[$assignment['cohortId'] . '|' . $assignment['courseId']] = $assignment['teacherId'];
		}

		self::assertGreaterThan(100, count(self::of(schema: 'attendance-record')));
		foreach (self::of(schema: 'attendance-record') as $mark) {
			$session = $sessions[$mark['sessionId']];
			self::assertSame($cohortOf[$mark['learnerId']], $session['cohortId'], $mark['slug']);
			self::assertSame($session['cohortId'], $mark['cohortId'], $mark['slug']);
			self::assertSame($teacher[$session['cohortId'] . '|' . $session['courseId']], $mark['markedBy'], $mark['slug']);
			self::assertLessThan(6, (int)(new \DateTimeImmutable($session['startsAt']))->format('N'), $mark['slug'] . ' is on a weekday');
		}

		$marks     = self::by(rows: self::of(schema: 'attendance-record'), field: 'uuid');
		$threshold = self::of(schema: 'attendance-threshold')[0];
		self::assertNotEmpty(self::of(schema: 'attendance-flag'));
		foreach (self::of(schema: 'attendance-flag') as $flag) {
			self::assertLessThan($threshold['limit'], $flag['metricValue']);
			foreach ($flag['breachingRecordIds'] as $uuid) {
				self::assertStringStartsWith('absent-', $marks[$uuid]['status']);
				self::assertSame($flag['learnerId'], $marks[$uuid]['learnerId']);
			}
		}
	}//end testEveryMarkBelongsToTheStudentsOwnWorkgroup()

	/**
	 * An exam asks only items from its bank, a result answers exactly the
	 * exam's items, and an item statistic matches the stored responses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md#requirement-the-item-bank-exams-agree-with-their-statistics
	 */
	public function testTheItemBankExamsAgreeWithTheirStatistics(): void {
		$items   = self::by(rows: self::of(schema: 'item'), field: 'uuid');
		$banks   = self::by(rows: self::of(schema: 'item-bank'), field: 'uuid');
		$exams   = self::by(rows: self::of(schema: 'exam'), field: 'uuid');
		$entries = self::by(rows: self::of(schema: 'grade-entry'), field: 'uuid');

		$results = [];
		foreach (self::of(schema: 'assessment-result') as $result) {
			$exam = $exams[$result['assessmentId']];
			self::assertSame(array_column($exam['itemRefs'], 'itemId'), array_column($result['responses'], 'itemId'), $result['slug']);
			$entry = $entries[$result['gradeEntryId']];
			self::assertSame($result['uuid'], $entry['assessmentResultId'], $result['slug']);
			self::assertSame($result['learnerId'], $entry['learnerId'], $result['slug']);
			$results[$result['assessmentId']][] = $result;
		}

		foreach (self::of(schema: 'exam') as $exam) {
			foreach ($exam['itemRefs'] as $ref) {
				self::assertContains($ref['itemId'], $banks[$items[$ref['itemId']]['itemBankId']]['itemIds'], $exam['slug']);
			}
		}

		self::assertGreaterThanOrEqual(40, count(self::of(schema: 'item-statistics')));
		$covered = [];
		foreach (self::of(schema: 'item-statistics') as $stat) {
			$covered[$stat['assessmentId'] . '|' . $stat['itemId']] = true;
			$rows = $results[$stat['assessmentId']];
			$full = 0;
			foreach ($rows as $result) {
				foreach ($result['responses'] as $response) {
					if ($response['itemId'] === $stat['itemId'] && ($response['autoScore'] ?? $response['manualScore']) >= $items[$stat['itemId']]['maxScore']) {
						$full++;
					}
				}
			}

			self::assertSame(count($rows), $stat['sampleSize'], $stat['slug']);
			self::assertEqualsWithDelta($full / count($rows), $stat['pValue'], 0.0000001, $stat['slug']);
		}

		// Every item of a main sitting (a sitting of 20 or more) has its statistic.
		foreach ($results as $assessmentId => $rows) {
			if (count($rows) < 20) {
				continue;
			}

			foreach ($exams[$assessmentId]['itemRefs'] as $ref) {
				self::assertArrayHasKey($assessmentId . '|' . $ref['itemId'], $covered, $exams[$assessmentId]['slug']);
			}
		}

		// A revision flag is raised only where its statistic crosses the
		// default threshold (ItemAnalysisRecomputeHandler).
		$stats = self::by(rows: self::of(schema: 'item-statistics'), field: 'uuid');
		self::assertNotEmpty(self::of(schema: 'item-revision-flag'));
		foreach (self::of(schema: 'item-revision-flag') as $flag) {
			$stat    = $stats[$flag['itemStatisticsId']];
			$crossed = match ($flag['reason']) {
				'too-difficult' => $stat['pValue'] < 0.2,
				'too-easy' => $stat['pValue'] > 0.95,
				'negative-discrimination' => $stat['itemTotalCorrelation'] < 0,
				'low-discrimination' => $stat['itemTotalCorrelation'] >= 0 && $stat['itemTotalCorrelation'] < 0.1,
			};
			self::assertTrue($crossed, $flag['slug'] . ' is justified by ' . $stat['slug']);
			self::assertSame($stat['itemId'], $flag['itemId']);
		}

		$proctoring = self::of(schema: 'proctoring-session')[0];
		$proctored  = self::find(schema: 'assessment-result', field: 'uuid', value: $proctoring['assessmentResultId']);
		self::assertSame($proctoring['uuid'], $proctored['proctoringSessionId']);
		self::assertTrue($exams[$proctored['assessmentId']]['proctoring']['nativeTestMode']);
	}//end testTheItemBankExamsAgreeWithTheirStatistics()

	/**
	 * A peer reviewer never reviews their own group, a summary averages its
	 * reviews, and each member's project grade names the group's submission.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md#requirement-the-higher-education-set-is-one-consistent-institution
	 */
	public function testPeerReviewsNeverReviewTheirOwnGroup(): void {
		$submissions = self::by(rows: self::of(schema: 'submission'), field: 'uuid');
		$reviews     = [];
		foreach (self::of(schema: 'peer-review') as $review) {
			self::assertNotContains($review['reviewerId'], $submissions[$review['submissionId']]['learnerIds'], $review['slug']);
			$reviews[$review['submissionId']][] = $review['totalScore'];
		}

		self::assertEqualsCanonicalizing(array_keys($submissions), array_column(self::of(schema: 'peer-feedback-summary'), 'submissionId'));
		foreach (self::of(schema: 'peer-feedback-summary') as $summary) {
			$scores = $reviews[$summary['submissionId']];
			self::assertGreaterThanOrEqual(2, count($scores));
			self::assertEqualsWithDelta(array_sum($scores) / count($scores), $summary['averageScore'], 0.0000001, $summary['slug']);
		}

		$groupOf = [];
		foreach (self::of(schema: 'submission') as $submission) {
			foreach ($submission['learnerIds'] as $learnerId) {
				$groupOf[$learnerId] = $submission['uuid'];
			}
		}

		foreach (self::of(schema: 'grade-entry') as $entry) {
			if ($entry['sourceKind'] === 'assignment-submission') {
				self::assertSame($groupOf[$entry['learnerId']], $entry['submissionId'], $entry['slug']);
			}
		}
	}//end testPeerReviewsNeverReviewTheirOwnGroup()

	/**
	 * Every third-year student has an internship portfolio, graded through a
	 * portfolio grade entry and shared with their workplace assessor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md#requirement-the-higher-education-set-is-one-consistent-institution
	 */
	public function testEveryInternshipPortfolioIsGradedAndShared(): void {
		$entries   = self::by(rows: self::of(schema: 'grade-entry'), field: 'uuid');
		$assessors = self::by(rows: self::of(schema: 'external-assessor'), field: 'uuid');
		$shared    = [];
		foreach (self::of(schema: 'portfolio-share') as $share) {
			self::assertSame('external-assessor', $share['sharedWithKind']);
			self::assertArrayHasKey($share['sharedWithExternalAssessorId'], $assessors);
			$shared[$share['portfolioId']] = true;
		}

		$owners = [];
		foreach (self::of(schema: 'portfolio') as $portfolio) {
			$entry = $entries[$portfolio['gradeEntryId']];
			self::assertSame('portfolio', $entry['sourceKind']);
			self::assertSame($portfolio['uuid'], $entry['portfolioId']);
			self::assertSame($portfolio['gradeValue'], $entry['value']);
			self::assertArrayHasKey($portfolio['uuid'], $shared, $portfolio['slug'] . ' is shared');
			$owners[] = $portfolio['learnerId'];
		}

		self::assertEqualsCanonicalizing(self::studentsOfIntake(suffix: 'cohort 2023'), $owners);
	}//end testEveryInternshipPortfolioIsGradedAndShared()

	/**
	 * A granted exemption produced its exemption entry, a proven fraud case
	 * invalidated its entry and the work was redone, and a learning record
	 * export covers only the learner's own grades.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md#requirement-the-higher-education-set-is-one-consistent-institution
	 */
	public function testExamBoardCasesAndExportsLeaveATrail(): void {
		$entries  = self::by(rows: self::of(schema: 'grade-entry'), field: 'uuid');
		$profiles = self::by(rows: self::of(schema: 'learner-profile'), field: 'uuid');
		$granted  = 0;
		foreach (self::of(schema: 'exemption-case') as $case) {
			if ($case['lifecycle'] !== 'granted') {
				continue;
			}

			$entry = $entries[$case['resultingGradeEntryId']];
			self::assertSame('exemption', $entry['sourceKind']);
			self::assertSame($case['uuid'], $entry['exemptionCaseId']);
			self::assertSame($profiles[$case['learnerId']]['ncUserId'], $entry['learnerId']);
			$granted++;
		}

		self::assertGreaterThanOrEqual(1, $granted);

		$fraud     = self::find(schema: 'fraud-case', field: 'verdict', value: 'fraud-proven');
		$contested = $entries[$fraud['contestedGradeEntryId']];
		self::assertSame('invalidated', $contested['lifecycle']);
		self::assertSame($fraud['uuid'], $contested['fraudCaseId']);
		$redone = array_filter(
			self::of(schema: 'grade-entry'),
			static fn (array $e): bool => $e['learnerId'] === $contested['learnerId'] && $e['componentId'] === $contested['componentId']
				&& $e['curriculumPlanId'] === $contested['curriculumPlanId'] && $e['lifecycle'] === 'published'
		);
		self::assertNotEmpty($redone, 'the invalidated work was handed in again');

		$finals    = self::by(rows: self::of(schema: 'final-grade'), field: 'uuid');
		$generated = 0;
		foreach (self::of(schema: 'learning-record-export') as $export) {
			foreach ($export['coverageReport'] as $row) {
				self::assertSame($export['learnerId'], $finals[$row['sourceId']]['learnerId'], $export['slug']);
			}

			if ($export['lifecycle'] === 'generated') {
				self::assertNotEmpty($export['coverageReport']);
				$generated++;
			}
		}

		self::assertGreaterThanOrEqual(1, $generated);
	}//end testExamBoardCasesAndExportsLeaveATrail()

	/**
	 * Loads and removes cleanly: the service offers the set with its true
	 * count, and its removal list is every object exactly once, children
	 * before parents.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md#requirement-the-higher-education-set-loads-and-removes-cleanly
	 */
	public function testTheServiceOffersAndRemovesExactlyThisSet(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$demo = $this->createMock(DemoDataService::class);
		$demo->method('listChoices')->willReturn([]);
		$service = new SeedProfileService($appManager, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), $demo);

		$offered = array_values(array_filter($service->listChoices(), static fn (array $c): bool => $c['id'] === 'he'))[0];
		self::of(schema: 'school');
		$all = [];
		foreach (array_keys((array)self::$objects) as $schema) {
			$all = array_merge($all, self::of(schema: $schema));
		}

		self::assertSame(count($all), $offered['objectCount']);
		self::assertSame('Higher education (HBO or university)', $offered['label']);

		$uuids = $service->uuidsFor('he');
		self::assertCount(count($all), array_unique($uuids));
		self::assertSame(end($all)['uuid'], $uuids[0], 'the last-loaded object is removed first');
		self::assertSame(self::of(schema: 'school')[0]['uuid'], end($uuids), 'the institution is removed last');
	}//end testTheServiceOffersAndRemovesExactlyThisSet()

	/**
	 * The file is what the generator produces, so nobody edits the JSON by
	 * hand and the rules in scripts/example-sets/he.py stay the truth.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-example-datasets-he/specs/example-sets/spec.md#scenario-the-file-is-reproducible
	 */
	public function testTheFileIsWhatTheGeneratorProduces(): void {
		$python = trim((string)shell_exec('command -v python3 2>/dev/null'));
		if ($python === '') {
			self::markTestSkipped('python3 is not available here; run python3 scripts/example-sets/he.py --check by hand.');
		}

		$script = dirname(__DIR__, 3) . '/scripts/example-sets/he.py';
		exec(escapeshellarg($python) . ' ' . escapeshellarg($script) . ' --check 2>&1', $output, $exitCode);

		self::assertSame(0, $exitCode, implode("\n", $output));
	}//end testTheFileIsWhatTheGeneratorProduces()
}//end class
