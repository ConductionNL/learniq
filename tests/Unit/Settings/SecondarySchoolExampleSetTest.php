<?php

/**
 * The secondary school example set keeps its promises.
 *
 * ExampleSetDescriptorContractTest proves the file loads (shape, uuids,
 * references, schema validity). This test proves the story is consistent: a
 * mark falls on a school day of the pupil's own class and is marked by the
 * attendance desk or a teacher on duty, a grade falls on a toetsweek day the
 * pupil was in school, an SE final grade is the weighted average of its SE
 * grades, a report card counts exactly its marks and shows exactly its grades,
 * and the removal list covers every object once. Those checks hold for the
 * 2025-2026 school year; the story layer (Noor Bakker of H4b on Monday 5
 * October 2026) is asserted by its own two tests.
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

use OCA\Learniq\Service\DemoDataService;
use OCA\Learniq\Service\SeedProfileService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Content and consistency of lib/Settings/profiles/vo.json.
 */
class SecondarySchoolExampleSetTest extends TestCase {

	/**
	 * The classes, in load order.
	 *
	 * @var string[]
	 */
	private const CLASSES = ['HV1a', 'HV1b', 'H2a', 'V2a', 'H3b', 'V3a', 'H4a', 'V4a', 'H5a', 'V5a', 'V6a'];

	/**
	 * The exam classes and their last lesson day.
	 *
	 * @var array<string, string>
	 */
	private const EXAM_CLASSES = ['H5a' => '2026-04-17', 'V6a' => '2026-04-17'];

	/**
	 * The weeks in which papers are sat: the three toetsweken and the exam
	 * classes' SE week before Easter.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private const TOETS_WEEKS = [
		['2025-11-10', '2025-11-14'],
		['2026-03-02', '2026-03-06'],
		['2026-03-30', '2026-04-02'],
		['2026-06-15', '2026-06-19'],
	];

	/**
	 * The school year the set is built around. The story layer adds Noor
	 * Bakker's class H4b of 2026-2027 on top, asserted on its own.
	 */
	private const YEAR = '2025-2026';

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
			$set = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/profiles/vo.json'), true);
			self::$objects = $set['x-openregister']['seedData']['objects'];
		}

		return (self::$objects[$schema] ?? []);
	}//end of()

	/**
	 * The objects of one schema in the base school year: a row with an
	 * academicYear in that year, or a row whose cohort is a class of it.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function base(string $schema): array {
		$classes = [];
		foreach (self::of('cohort') as $cohort) {
			if ($cohort['academicYear'] === self::YEAR) {
				$classes[$cohort['uuid']] = true;
			}
		}

		return array_values(
			array_filter(
				self::of($schema),
				static function (array $row) use ($classes): bool {
					if (isset($row['academicYear']) === true) {
						return $row['academicYear'] === self::YEAR;
					}

					return isset($classes[($row['cohortId'] ?? '')]) === true;
				}
			)
		);
	}//end base()

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
	 * Group a list of objects by one or more fields.
	 *
	 * @param array<int, array<string, mixed>> $rows   The objects.
	 * @param string[]                         $fields The fields that make the key.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function groupBy(array $rows, array $fields): array {
		$groups = [];
		foreach ($rows as $row) {
			$key = implode('|', array_map(static fn (string $f): string => (string)$row[$f], $fields));
			$groups[$key][] = $row;
		}

		return $groups;
	}//end groupBy()

	/**
	 * The pupils (learner profiles with the learner role).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function pupils(): array {
		return array_values(array_filter(self::of('learner-profile'), static fn (array $p): bool => $p['roles'] === ['learner']));
	}//end pupils()

	/**
	 * The staff user id that carries a qualification.
	 *
	 * @param string $qualification The qualification.
	 *
	 * @return string
	 */
	private static function staffWith(string $qualification): string {
		foreach (self::of('staff') as $staff) {
			if (in_array($qualification, $staff['qualifications'], true) === true) {
				return $staff['ncUserId'];
			}
		}

		return '';
	}//end staffWith()

	/**
	 * One school with two locations, eleven classes from the brugklas to vwo
	 * 6, about 300 pupils each with a guardian and one enrolment in a class of
	 * their leerjaar, a mentor and subject teachers per class, and a decaan and
	 * a zorgcoördinator on Staff.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-secondary-school-set-is-one-consistent-school
	 */
	public function testTheSchoolHasItsPromisedShape(): void {
		self::assertCount(1, self::of('school'));
		self::assertMatchesRegularExpression('/^[0-9]{2}[A-Z][0-9]$/', self::of('school')[0]['brin']);
		self::assertCount(2, self::of('vestiging'));
		self::assertSame(self::CLASSES, array_column(self::base('cohort'), 'name'));

		$profiles = self::by(self::of('learner-profile'), 'uuid');
		$pupils   = self::pupils();
		self::assertGreaterThanOrEqual(250, count($pupils));
		self::assertLessThanOrEqual(350, count($pupils));
		foreach ($pupils as $pupil) {
			self::assertNotEmpty($pupil['guardianRefs'], $pupil['ncUserId'] . ' has a guardian');
			foreach ($pupil['guardianRefs'] as $ref) {
				self::assertSame(['parent'], $profiles[$ref]['roles']);
			}

			self::assertArrayNotHasKey('bsnEncrypted', $pupil);
			self::assertArrayNotHasKey('eckId', $pupil);
		}

		// Postcodes start with 0 and phone numbers with 06-0: the Netherlands issues neither.
		foreach (array_merge(self::of('learner-profile'), self::of('vestiging')) as $row) {
			$postcode = ($row['address']['postalCode'] ?? $row['postalCode'] ?? '0');
			self::assertStringStartsWith('0', $postcode, $row['slug']);
			foreach (($row['emergencyContacts'] ?? []) as $contact) {
				self::assertStringStartsWith('06-0', $contact['phone'], $row['slug']);
			}
		}

		foreach (self::of('admission') as $application) {
			self::assertStringStartsWith('06-0', ($application['guardianPhone'] ?? '06-0'), $application['slug']);
		}

		$cohorts   = self::by(self::of('cohort'), 'uuid');
		$enrolment = self::groupBy(self::base('enrolment'), ['learnerId']);
		self::assertEqualsCanonicalizing(array_column($pupils, 'ncUserId'), array_keys($enrolment));
		$leerjaren = [];
		foreach ($enrolment as $learner => $rows) {
			self::assertCount(1, $rows, $learner . ' has one enrolment');
			$class = $cohorts[$rows[0]['cohortId']];
			self::assertSame((int)preg_replace('/\D/', '', $class['name']), $rows[0]['leerjaar'], $learner . ' sits in a class of its leerjaar');
			self::assertContains($learner, $class['learnerIds']);
			$leerjaren[$rows[0]['leerjaar']] = true;
		}

		self::assertSame([1, 2, 3, 4, 5, 6], array_keys(array_intersect_key(array_fill(1, 6, true), $leerjaren)));

		$assignments = self::groupBy(self::of('subjectteacherassignment'), ['cohortId']);
		foreach (self::of('cohort') as $class) {
			$mentor   = $class['teacherAssignments'][0]['teacherId'];
			$teachers = array_column($assignments[$class['uuid']] ?? [], 'teacherId');
			self::assertGreaterThanOrEqual(8, count($teachers), $class['name'] . ' has subject teachers');
			self::assertContains($mentor, $teachers, $class['name'] . ': the mentor teaches the class');
			self::assertEqualsCanonicalizing(array_values(array_unique($teachers)), $class['teacherIds']);
		}

		self::assertNotSame('', self::staffWith('decaan'));
		self::assertNotSame('', self::staffWith('zorgcoördinator'));
		self::assertCount(3, self::base('report-period'));
	}//end testTheSchoolHasItsPromisedShape()

	/**
	 * Every mark sits on a session of the pupil's own class, on a school day
	 * on or after the inschrijving; the attendance desk marks absences and
	 * departures, a teacher of the class who works that weekday marks a late
	 * arrival.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-a-late-arrival-belongs-to-the-pupils-own-class-and-a-teacher-on-duty
	 */
	public function testEveryMarkBelongsToThePupilsOwnClassAndATeacherOnDuty(): void {
		$sessions  = self::by(self::of('session'), 'uuid');
		$cohorts   = self::by(self::of('cohort'), 'uuid');
		$enrolment = self::by(self::base('enrolment'), 'learnerId');
		$staff     = self::by(self::of('staff'), 'ncUserId');
		$desk      = self::staffWith('verzuimcoördinator');

		self::assertGreaterThan(1000, count(self::base('attendance-record')));
		$lates = 0;
		foreach (self::base('attendance-record') as $mark) {
			$session = $sessions[$mark['sessionId']];
			self::assertSame($enrolment[$mark['learnerId']]['cohortId'], $session['cohortId'], $mark['slug']);
			self::assertSame($session['cohortId'], $mark['cohortId'], $mark['slug']);

			$day = new \DateTimeImmutable($session['startsAt']);
			self::assertLessThan(6, (int)$day->format('N'), $mark['slug'] . ' is on a weekday');
			self::assertGreaterThanOrEqual($enrolment[$mark['learnerId']]['inschrijvingDate'], $day->format('Y-m-d'));

			if ($mark['status'] !== 'late') {
				self::assertSame($desk, $mark['markedBy'], $mark['slug'] . ' is marked by the attendance desk');
				continue;
			}

			$lates++;
			$weekday = self::WEEKDAYS[((int)$day->format('N')) - 1];
			self::assertContains($mark['markedBy'], $cohorts[$session['cohortId']]['teacherIds'], $mark['slug']);
			self::assertContains($weekday, $staff[$mark['markedBy']]['workingDays'], $mark['slug'] . ' is marked by a teacher on duty');
		}//end foreach

		self::assertGreaterThan(100, $lates);
	}//end testEveryMarkBelongsToThePupilsOwnClassAndATeacherOnDuty()

	/**
	 * No session falls in a holiday or on a study day, and an exam class has
	 * no lessons after its last lesson day.
	 *
	 * @return void
	 */
	public function testNoSessionFallsOnAClosedDayOrAfterTheExamClassesStop(): void {
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

		$names = array_column(self::of('cohort'), 'name', 'uuid');
		self::assertNotEmpty($closed);
		foreach (self::base('session') as $session) {
			$day = substr($session['startsAt'], 0, 10);
			self::assertArrayNotHasKey($day, $closed, $session['title'] . ' falls on a day the school is closed');
			$lastDay = (self::EXAM_CLASSES[$names[$session['cohortId']]] ?? '2026-07-10');
			self::assertLessThanOrEqual($lastDay, $day, $session['title'] . ' falls after the last lesson day');
		}
	}//end testNoSessionFallsOnAClosedDayOrAfterTheExamClassesStop()

	/**
	 * A report card's attendance summary counts exactly the marks in its
	 * period, so the card and the attendance list never disagree.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-the-report-card-and-the-attendance-list-agree
	 */
	public function testReportCardsCountTheMarksOfTheirPeriod(): void {
		$sessions = self::by(self::of('session'), 'uuid');
		$periods  = self::by(self::base('report-period'), 'uuid');
		$counted  = [];
		foreach (self::base('attendance-record') as $mark) {
			$day = substr($sessions[$mark['sessionId']]['startsAt'], 0, 10);
			foreach ($periods as $uuid => $period) {
				if ($day >= $period['startDate'] && $day <= $period['endDate']) {
					$key = $mark['learnerId'] . '|' . $uuid;
					$counted[$key][$mark['status']] = (($counted[$key][$mark['status']] ?? 0) + 1);
				}
			}
		}

		$enrolment = self::by(self::base('enrolment'), 'learnerId');
		$cards     = self::groupBy(self::of('report-card'), ['learnerId', 'reportPeriodId']);
		foreach (self::pupils() as $pupil) {
			foreach ($periods as $uuid => $period) {
				$expected = (int)($enrolment[$pupil['ncUserId']]['inschrijvingDate'] <= $period['endDate']);
				self::assertCount($expected, $cards[$pupil['ncUserId'] . '|' . $uuid] ?? [], $pupil['ncUserId'] . ' has one card per period enrolled');
			}
		}

		self::assertGreaterThan(800, count(self::of('report-card')));
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
	 * Every grade scores a declared component in that component's period, on
	 * a session of the pupil's own class during a toetsweek (or after it, for
	 * an inhaaltoets), never on a day the pupil was absent, and for a paper
	 * the class sat.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-grades-agree-with-the-records-derived-from-them
	 */
	public function testEveryGradeSitsOnAToetsweekDayThePupilWasInSchool(): void {
		$plans     = self::by(self::of('curriculum-plan'), 'uuid');
		$sessions  = self::by(self::of('session'), 'uuid');
		$enrolment = self::by(self::base('enrolment'), 'learnerId');
		$papers    = self::by(
			array_map(static fn (array $e): array => $e + ['key' => $e['cohortId'] . '|' . $e['courseId'] . '|' . $e['curriculumPlanComponentId']], self::of('exam')),
			'key'
		);
		$absent    = [];
		foreach (self::base('attendance-record') as $mark) {
			if (str_starts_with($mark['status'], 'absent') === true) {
				$absent[$mark['learnerId'] . '|' . substr($sessions[$mark['sessionId']]['startsAt'], 0, 10)] = true;
			}
		}

		self::assertGreaterThan(1000, count(self::base('grade-entry')));
		foreach (self::base('grade-entry') as $grade) {
			$components = array_column($plans[$grade['curriculumPlanId']]['components'], null, 'componentId');
			self::assertArrayHasKey($grade['componentId'], $components, $grade['slug']);
			self::assertSame((string)$components[$grade['componentId']]['period'], $grade['period'], $grade['slug']);

			$session = $sessions[$grade['sessionId']];
			$day     = substr($session['startsAt'], 0, 10);
			self::assertSame($enrolment[$grade['learnerId']]['cohortId'], $session['cohortId'], $grade['slug']);
			self::assertSame($session['cohortId'], $grade['cohortId'], $grade['slug']);
			self::assertArrayNotHasKey($grade['learnerId'] . '|' . $day, $absent, $grade['slug'] . ' is not on a day the pupil was absent');

			$paper = $papers[$grade['cohortId'] . '|' . $grade['courseId'] . '|' . $grade['componentId']];
			$sat   = substr($paper['availableFrom'], 0, 10);
			$inWeek = array_filter(self::TOETS_WEEKS, static fn (array $w): bool => $sat >= $w[0] && $sat <= $w[1]);
			self::assertNotEmpty($inWeek, $paper['slug'] . ' is sat in a toetsweek');
			self::assertSame($sat, substr($sessions[$paper['sessionId']]['startsAt'], 0, 10), $paper['slug']);
			if ($day !== $sat) {
				self::assertGreaterThan(array_values($inWeek)[0][1], $day, $grade['slug'] . ' is an inhaaltoets after the week');
				self::assertArrayHasKey($grade['learnerId'] . '|' . $sat, $absent, $grade['slug'] . ' is made up because the pupil was absent');
			}
		}//end foreach
	}//end testEveryGradeSitsOnAToetsweekDayThePupilWasInSchool()

	/**
	 * Every final grade is the weighted average of its grades, with the
	 * per-period breakdown GradeAggregationEngine computes, and every exam
	 * class pupil has an SE final grade on a PTA for each package subject.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-an-se-final-grade-is-the-weighted-average-of-its-se-grades
	 */
	public function testFinalGradesAreTheWeightedAverageOfTheirGrades(): void {
		$plans   = self::by(self::of('curriculum-plan'), 'uuid');
		$entries = self::groupBy(self::of('grade-entry'), ['learnerId', 'curriculumPlanId']);

		self::assertGreaterThan(300, count(self::of('final-grade')));
		foreach (self::of('final-grade') as $final) {
			$own     = $entries[$final['learnerId'] . '|' . $final['curriculumPlanId']];
			$weights = array_column($plans[$final['curriculumPlanId']]['components'], 'weight', 'componentId');
			$sum     = 0.0;
			$total   = 0.0;
			$periods = [];
			foreach ($own as $entry) {
				$weight = (float)$weights[$entry['componentId']];
				$sum   += $entry['value'] * $weight;
				$total += $weight;
				$periods[$entry['period']][] = [$entry['value'] * $weight, $weight];
				self::assertSame($final['courseId'], $entry['courseId'], $entry['slug']);
				$part = $final['breakdown']['components'][$entry['componentId']];
				self::assertEqualsWithDelta($entry['value'], $part['value'], 0.00001, $final['slug']);
				self::assertEqualsWithDelta($weight, $part['weight'], 0.00001, $final['slug']);
				self::assertEqualsWithDelta($entry['value'] * $weight, $part['contribution'], 0.00001, $final['slug']);
			}

			self::assertCount(count($own), $final['breakdown']['components'], $final['slug']);
			self::assertEqualsWithDelta(round($sum / $total, 4), $final['value'], 0.00001, $final['slug']);
			self::assertSame($final['value'] >= 5.5, $final['passed'], $final['slug']);
			foreach ($periods as $period => $parts) {
				$average = round(array_sum(array_column($parts, 0)) / array_sum(array_column($parts, 1)), 4);
				self::assertEqualsWithDelta($average, $final['breakdown']['periods'][$period], 0.00001, $final['slug']);
			}
		}//end foreach

		$cohorts   = self::by(self::of('cohort'), 'uuid');
		$enrolment = self::by(self::base('enrolment'), 'learnerId');
		$finals    = self::groupBy(self::of('final-grade'), ['learnerId']);
		foreach (self::pupils() as $pupil) {
			$class = $cohorts[$enrolment[$pupil['ncUserId']]['cohortId']]['name'];
			if (isset(self::EXAM_CLASSES[$class]) === false) {
				continue;
			}

			$pta = array_filter($finals[$pupil['ncUserId']] ?? [], static fn (array $f): bool => $plans[$f['curriculumPlanId']]['kind'] === 'pta');
			self::assertGreaterThanOrEqual(6, count($pta), $pupil['ncUserId'] . ' has an SE final grade per package subject');
		}
	}//end testFinalGradesAreTheWeightedAverageOfTheirGrades()

	/**
	 * A report card line backed by a final grade shows that grade's period
	 * average and lists exactly the grades of that period, as
	 * ReportCardComposer would compose it; an exam class card has only such
	 * lines.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-the-report-card-shows-the-period-average-of-the-stored-grades
	 */
	public function testReportCardLinesShowTheStoredGradesOfTheirPeriod(): void {
		$periods = self::by(self::of('report-period'), 'uuid');
		$finals  = self::by(
			array_map(static fn (array $f): array => $f + ['key' => $f['learnerId'] . '|' . $f['curriculumPlanId']], self::of('final-grade')),
			'key'
		);
		$entries = self::groupBy(self::of('grade-entry'), ['learnerId', 'curriculumPlanId', 'period']);
		$names   = array_column(self::of('cohort'), 'name', 'uuid');

		$backed = 0;
		foreach (self::of('report-card') as $card) {
			$code = $periods[$card['reportPeriodId']]['periodCode'];
			foreach ($card['subjectGrades'] as $line) {
				$key = $card['learnerId'] . '|' . $line['curriculumPlanId'];
				if (isset($finals[$key]) === false) {
					self::assertArrayNotHasKey($names[$card['cohortId']], self::EXAM_CLASSES, $card['slug'] . ' exam card lines have a final grade');
					continue;
				}

				$backed++;
				$final = $finals[$key];
				self::assertSame(($final['breakdown']['periods'][$code] ?? null), $line['periodAverage'], $card['slug']);
				self::assertSame($final['courseId'], $line['courseId'], $card['slug']);
				self::assertEqualsCanonicalizing(
					array_column($entries[$key . '|' . $code] ?? [], 'uuid'),
					$line['sourceGradeEntryIds'],
					$card['slug'] . ' lists the grades of its period'
				);
			}
		}//end foreach

		self::assertGreaterThan(1000, $backed);
	}//end testReportCardLinesShowTheStoredGradesOfTheirPeriod()

	/**
	 * Every leerjaar 3 pupil made a profielkeuze; an approved or validated
	 * choice satisfies the package rules, a choice in needs-revision carries
	 * the validator's message.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-an-approved-profielkeuze-satisfies-the-package-rules
	 */
	public function testTheProfielkeuzeFollowsThePackageRules(): void {
		$plans     = self::by(self::of('curriculum-plan'), 'uuid');
		$third     = array_keys(array_filter(self::by(self::base('enrolment'), 'learnerId'), static fn (array $e): bool => $e['leerjaar'] === 3));
		$choices   = self::by(self::of('subject-choice'), 'learnerId');
		self::assertEqualsCanonicalizing($third, array_keys($choices));

		$revisions = 0;
		foreach ($choices as $learner => $choice) {
			$rules    = $plans[$choice['curriculumPlanId']]['electiveRules'];
			$selected = $choice['selectedElectiveCourseIds'];
			$broken   = [];
			if (count($selected) < $rules['minElectives']) {
				$broken[] = sprintf('At least %d elective(s) required (selected %d).', $rules['minElectives'], count($selected));
			}

			if (count($selected) > $rules['maxElectives']) {
				$broken[] = sprintf('At most %d elective(s) allowed (selected %d).', $rules['maxElectives'], count($selected));
			}

			foreach ($rules['mutuallyExclusive'] as $group) {
				$chosen = array_values(array_intersect($group, $selected));
				if (count($chosen) > 1) {
					$broken[] = sprintf('Mutually exclusive courses selected together: %s.', implode(', ', $chosen));
				}
			}

			self::assertSame($broken, $choice['validationErrors'], $learner);
			self::assertSame($broken === [], in_array($choice['lifecycle'], ['validated', 'approved'], true), $learner);
			self::assertTrue($choice['guardianConsentGiven'], $learner);
			$revisions += (int)($choice['lifecycle'] === 'needs-revision');
		}//end foreach

		self::assertGreaterThanOrEqual(1, $revisions);
	}//end testTheProfielkeuzeFollowsThePackageRules()

	/**
	 * The one schooladvies arrived with a brugklas pupil, whose converted
	 * application points at that pupil's profile and enrolment.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-the-incoming-schooladvies-belongs-to-a-brugklas-pupil
	 */
	public function testTheIncomingSchooladviesBelongsToABrugklasPupil(): void {
		self::assertCount(1, self::of('school-advies'));
		$advies    = self::of('school-advies')[0];
		$enrolment = self::by(self::base('enrolment'), 'learnerId')[$advies['learnerId']];
		$profile   = self::by(self::pupils(), 'ncUserId')[$advies['learnerId']];
		$cohorts   = self::by(self::of('cohort'), 'uuid');

		self::assertSame(1, $enrolment['leerjaar']);
		self::assertStringStartsWith('HV1', $cohorts[$enrolment['cohortId']]['name']);

		$converted = array_values(array_filter(
			self::of('admission'),
			static fn (array $a): bool => ($a['convertedLearnerProfileId'] ?? null) === $profile['uuid']
		));
		self::assertCount(1, $converted);
		self::assertSame('converted', $converted[0]['lifecycle']);
		self::assertSame([$enrolment['uuid']], $converted[0]['convertedEnrolmentIds']);
		self::assertSame($advies['definitiefAdviesLevel'], $converted[0]['schoolAdviceAdjustedLevel']);
		self::assertSame($advies['voorlopigAdviesLevel'], $converted[0]['schoolAdviceLevel']);
	}//end testTheIncomingSchooladviesBelongsToABrugklasPupil()

	/**
	 * A leerplicht flag lists exactly its pupil's unexcused absences in the
	 * window, which add up to more than 16 hours; the attendance-percentage
	 * flag carries the percentage on that pupil's report card.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-schools-own-records-tell-the-secondary-school-story
	 */
	public function testTheVerzuimFlagsListTheirRecords(): void {
		$thresholds = self::by(self::of('attendance-threshold'), 'uuid');
		$sessions   = self::by(self::of('session'), 'uuid');
		$periods    = self::of('report-period');
		$cards      = self::groupBy(self::of('report-card'), ['learnerId', 'reportPeriodId']);
		$kinds      = [];
		foreach (self::of('attendance-flag') as $flag) {
			$threshold = $thresholds[$flag['attendanceThresholdId']];
			$kinds[]   = $threshold['kind'];
			$expected  = [];
			foreach (self::of('attendance-record') as $mark) {
				$day      = substr($sessions[$mark['sessionId']]['startsAt'], 0, 10);
				$inWindow = ($mark['learnerId'] === $flag['learnerId'] && $day >= $flag['windowStart'] && $day <= $flag['windowEnd']);
				$counts   = ($threshold['kind'] === 'leerplicht-16uur')
					? ($mark['status'] === 'absent-unexcused')
					: str_starts_with($mark['status'], 'absent');
				if ($inWindow === true && $counts === true) {
					$expected[] = $mark['uuid'];
				}
			}

			self::assertEqualsCanonicalizing($expected, $flag['breachingRecordIds'], $flag['slug']);
			if ($threshold['kind'] === 'leerplicht-16uur') {
				self::assertGreaterThan($threshold['limit'], $flag['metricValue'], $flag['slug']);
				continue;
			}

			$period = array_values(array_filter($periods, static fn (array $p): bool => $p['startDate'] === $flag['windowStart']))[0];
			$card   = $cards[$flag['learnerId'] . '|' . $period['uuid']][0];
			self::assertSame($card['attendanceSummary']['attendancePercent'], $flag['metricValue'], $flag['slug']);
			self::assertLessThan($threshold['limit'], $flag['metricValue'], $flag['slug']);
		}//end foreach

		self::assertContains('leerplicht-16uur', $kinds);
		self::assertContains('generic', $kinds);
	}//end testTheVerzuimFlagsListTheirRecords()

	/**
	 * A weighted average as the boards show it: one decimal, rounded half up,
	 * with a decimal comma.
	 *
	 * @param array<int, array<string, mixed>> $entries Grade entries with a value and a weight.
	 *
	 * @return string
	 */
	private static function shown(array $entries): string {
		$sum   = 0.0;
		$total = 0.0;
		foreach ($entries as $entry) {
			$sum   += ($entry['value'] * $entry['weight']);
			$total += $entry['weight'];
		}

		return number_format(round($sum / $total, 4), 1, ',', '');
	}//end shown()

	/**
	 * Vaartveld College on Monday 5 October 2026: Noor Bakker of H4b, her
	 * father Erik, her mentor Sanne Kramer, her seven lessons with the room
	 * change and the cancelled lesson, and the people on the boards.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-sets-are-the-four-schools/specs/example-sets/spec.md
	 */
	public function testNoorBakkersMondayInH4bComesOutOfTheData(): void {
		self::assertSame('Vaartveld College', self::of('school')[0]['name']);
		self::assertContains('Vaartlaan 40', array_column(self::of('vestiging'), 'street'));
		self::assertSame(['Zuiddrecht'], array_values(array_unique(array_column(self::of('vestiging'), 'city'))));

		$noor = array_values(array_filter(self::pupils(), static fn (array $p): bool => ($p['givenName'] ?? '') === 'Noor' && ($p['familyName'] ?? '') === 'Bakker'));
		self::assertCount(1, $noor);
		$noor     = $noor[0];
		$profiles = self::by(self::of('learner-profile'), 'uuid');
		$fathers  = array_filter(
			$noor['guardianRefs'],
			static fn (string $ref): bool => $profiles[$ref]['givenName'] === 'Erik' && $profiles[$ref]['familyName'] === 'Bakker'
		);
		self::assertCount(1, $fathers, 'Erik Bakker is her guardian');
		self::assertContains($profiles[array_values($fathers)[0]]['ncUserId'], $noor['parentIds']);

		$classes = array_values(array_filter(self::of('cohort'), static fn (array $c): bool => $c['name'] === 'H4b'));
		self::assertCount(1, $classes);
		$h4b = $classes[0];
		self::assertSame('2026-2027', $h4b['academicYear']);
		self::assertCount(27, $h4b['learnerIds']);
		self::assertContains($noor['ncUserId'], $h4b['learnerIds']);
		$enrolment = array_values(array_filter(self::of('enrolment'), static fn (array $e): bool => $e['learnerId'] === $noor['ncUserId'] && $e['lifecycle'] === 'active'));
		self::assertCount(1, $enrolment);
		self::assertSame($h4b['uuid'], $enrolment[0]['cohortId']);
		self::assertSame(4, $enrolment[0]['leerjaar']);

		$staff = array_column(self::of('staff'), 'name', 'ncUserId');
		$names = [
			'Sanne Kramer',
			'Jeroen Smit',
			'Anouk Visser',
			'Marloes Peters',
			'Emre Demir',
			'Thomas de Boer',
			'Ingrid Jansen',
			'Arjen Willems',
			'Ellen Hendriks',
			'Wouter Mulder',
			'Naima Vos',
			'Bart Dijkstra',
			'Youssef El Idrissi',
		];
		foreach ($names as $name) {
			self::assertContains($name, $staff);
		}

		self::assertSame('Sanne Kramer', $staff[$h4b['teacherAssignments'][0]['teacherId']], 'her mentor');

		// Monday's lessons: subject, times, room and teacher as on the boards.
		$courses  = array_column(self::of('course'), 'name', 'uuid');
		$rooms    = array_column(self::of('room'), 'code', 'uuid');
		$teachers = [];
		foreach (self::of('subjectteacherassignment') as $assignment) {
			if ($assignment['cohortId'] === $h4b['uuid']) {
				$teachers[$assignment['courseId']] = $assignment['teacherId'];
			}
		}

		$monday = array_values(array_filter(self::of('session'), static fn (array $s): bool => $s['cohortId'] === $h4b['uuid'] && str_starts_with($s['startsAt'], '2026-10-05')));
		usort($monday, static fn (array $a, array $b): int => strcmp($a['startsAt'], $b['startsAt']));
		$seen = [];
		foreach ($monday as $session) {
			$teacher = ($session['courseId'] === null) ? $h4b['teacherAssignments'][0]['teacherId'] : $teachers[$session['courseId']];
			$seen[]  = implode(' | ', [$session['title'], substr($session['startsAt'], 11, 5), substr($session['endsAt'], 11, 5), $rooms[$session['roomId']], $staff[$teacher]]);
		}

		self::assertSame(
			[
				'Nederlands | 08:30 | 09:20 | 1.12 | Sanne Kramer',
				'Wiskunde A | 09:20 | 10:10 | 2.14 | Emre Demir',
				'Economie | 10:30 | 11:20 | 0.21 | Thomas de Boer',
				'Engels | 11:20 | 12:10 | 1.05 | Ingrid Jansen',
				'Geschiedenis | 12:40 | 13:30 | 2.03 | Arjen Willems',
				'Mentoruur | 13:30 | 14:20 | 1.12 | Sanne Kramer',
				'Lichamelijke opvoeding | 14:30 | 15:20 | H-GYM | Youssef El Idrissi',
			],
			$seen
		);
		self::assertSame('room-unavailable', $monday[2]['changeReasonKind']);
		self::assertStringContainsString('1.08', $monday[2]['changeReason']);
		self::assertSame('cancelled', $monday[6]['lifecycle']);
		self::assertSame('teacher-absence', $monday[6]['changeReasonKind']);
		$tuesday = array_values(array_filter(self::of('session'), static fn (array $s): bool => $s['cohortId'] === $h4b['uuid'] && $s['startsAt'] === '2026-10-06T08:30:00+02:00'));
		self::assertSame(['Economie', '1.08'], [$tuesday[0]['title'], $rooms[$tuesday[0]['roomId']]]);
	}//end testNoorBakkersMondayInH4bComesOutOfTheData()

	/**
	 * Noor's grades, homework, absence, mentor talk and calendar give the
	 * numbers on the boards: the averages per subject and overall, the three
	 * newest grades, this week's work, 1 day ill and 2 times late, and the
	 * free times for the mentor talk.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-sets-are-the-four-schools/specs/example-sets/spec.md
	 */
	public function testNoorBakkersNumbersComeOutOfTheData(): void {
		$noor    = array_values(array_filter(self::pupils(), static fn (array $p): bool => ($p['givenName'] ?? '') === 'Noor' && ($p['familyName'] ?? '') === 'Bakker'))[0];
		$h4b     = array_values(array_filter(self::of('cohort'), static fn (array $c): bool => $c['name'] === 'H4b'))[0];
		$scales  = array_column(self::of('grade-scale'), 'kind', 'uuid');
		$grades  = array_values(array_filter(self::of('grade-entry'), static fn (array $g): bool => $g['learnerId'] === $noor['ncUserId'] && $g['cohortId'] === $h4b['uuid']));
		$numeric = [];
		foreach ($grades as $grade) {
			if ($scales[$grade['gradeScaleId']] === 'numeric') {
				$numeric[$grade['courseName']][] = $grade;
			}
		}

		$averages = array_map(static fn (array $rows): string => self::shown($rows), $numeric);
		ksort($averages);
		self::assertSame(
			[
				'Aardrijkskunde'   => '6,9',
				'Bedrijfseconomie' => '6,3',
				'Economie'         => '6,4',
				'Engels'           => '7,1',
				'Geschiedenis'     => '7,8',
				'Maatschappijleer' => '7,2',
				'Nederlands'       => '7,0',
				'Wiskunde A'       => '5,2',
			],
			$averages
		);
		$overall = array_sum(array_map(static fn (string $a): float => (float)str_replace(',', '.', $a), $averages)) / count($averages);
		self::assertSame('6,7', number_format(round($overall, 4), 1, ',', ''));

		usort($numeric['Wiskunde A'], static fn (array $a, array $b): int => strcmp($a['gradedAt'], $b['gradedAt']));
		self::assertSame(
			[['2026-09-10', 1, 6.1], ['2026-09-24', 3, 4.7], ['2026-10-01', 1, 5.8]],
			array_map(static fn (array $g): array => [substr($g['gradedAt'], 0, 10), $g['weight'], $g['value']], $numeric['Wiskunde A'])
		);
		usort($grades, static fn (array $a, array $b): int => strcmp($b['gradedAt'], $a['gradedAt']));
		self::assertSame(
			[['Engels', '2026-10-02', 6.9], ['Wiskunde A', '2026-10-01', 5.8], ['Economie', '2026-09-30', 6.6]],
			array_map(static fn (array $g): array => [$g['courseName'], substr($g['gradedAt'], 0, 10), $g['value']], array_slice($grades, 0, 3)),
			'the three newest grades'
		);

		$homework = array_values(array_filter(self::of('assignment'), static fn (array $a): bool => in_array($noor['uuid'], $a['learnerRefs'], true)));
		self::assertSame(
			[
				'Leesverslag inleveren 2026-10-05',
				'Paragraaf 3.2, opgave 14 tot en met 22 2026-10-05',
				'SO woordjes unit 2 2026-10-05',
				'Hoofdstuk 2, opgave 8 tot en met 15 2026-10-06',
				'Toets tijdvak 3 en 4 2026-10-08',
				'Paragraaf 3.3, opgave 23 tot en met 31 2026-10-08',
				'Toets hoofdstuk 3 en 4 2026-11-10',
			],
			array_map(static fn (array $a): string => $a['title'] . ' ' . substr($a['dueAt'], 0, 10), $homework)
		);

		$sessions = self::by(self::of('session'), 'uuid');
		$marks    = array_values(array_filter(self::of('attendance-record'), static fn (array $m): bool => $m['learnerId'] === $noor['ncUserId'] && $m['cohortId'] === $h4b['uuid']));
		$illDays  = [];
		$lates    = 0;
		foreach ($marks as $mark) {
			self::assertNotSame('absent-unexcused', $mark['status'], $mark['slug']);
			$lates += (int)($mark['status'] === 'late');
			if ($mark['status'] === 'absent-excused') {
				$illDays[substr($sessions[$mark['sessionId']]['startsAt'], 0, 10)] = true;
			}
		}

		self::assertSame(['2026-09-14'], array_keys($illDays));
		self::assertSame(2, $lates);
		$summary = array_values(array_filter(self::of('attendance-summary'), static fn (array $s): bool => $s['learnerId'] === $noor['ncUserId']))[0];
		self::assertSame(['2026-2027', 1, 0, 2], [$summary['schoolYear'], $summary['absentDays'], $summary['absentUnauthorisedDays'], $summary['lateCount']]);

		$slots = array_filter(self::of('conference-slot'), static fn (array $s): bool => in_array($noor['uuid'], $s['eligibleLearnerRefs'], true));
		$times = [];
		foreach ($slots as $slot) {
			$times[substr($slot['startsAt'], 5, 11)] = $slot['lifecycle'];
		}

		ksort($times);
		self::assertSame(
			[
				'10-13T16:10' => 'booked',
				'10-13T16:30' => 'free',
				'10-13T16:50' => 'free',
				'10-13T17:10' => 'free',
				'10-15T18:30' => 'free',
				'10-15T18:50' => 'booked',
				'10-15T19:10' => 'free',
				'10-15T19:30' => 'free',
			],
			$times
		);

		$events = array_column(self::of('school-event'), 'startsAt', 'title');
		self::assertSame('2026-10-17', $events['Herfstvakantie']);
		self::assertSame('2026-11-09', $events['Toetsweek 1, bovenbouw']);
		self::assertSame('2026-11-03T19:30:00+01:00', $events['Informatieavond profielkeuze, klas 3']);
		self::assertSame('2026-10-13', $events['Mentorgesprekken']);
	}//end testNoorBakkersNumbersComeOutOfTheData()

	/**
	 * Loads and removes cleanly: the service offers the set with its true
	 * count, and its removal list is every object exactly once, children
	 * before parents.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-secondary-school-set-loads-and-removes-cleanly
	 */
	public function testTheServiceOffersAndRemovesExactlyThisSet(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$demo = $this->createMock(DemoDataService::class);
		$demo->method('listChoices')->willReturn([]);
		$service = new SeedProfileService($appManager, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), $demo, $this->createMock(\OCA\Learniq\Service\SharedCodeFilter::class), $this->createMock(\OCA\Learniq\Service\LoadedExampleSets::class), $this->createMock(\OCA\Learniq\Portal\ExamplePortalProvisioner::class), $this->createMock(\OCA\Learniq\Service\ExampleSetDates::class));

		$offered = array_values(array_filter($service->listChoices(), static fn (array $c): bool => $c['id'] === 'vo'))[0];
		self::assertSame('Secondary school', $offered['label']);
		self::of('school');
		$all = [];
		foreach (array_keys((array)self::$objects) as $schema) {
			$all = array_merge($all, self::of($schema));
		}

		self::assertSame(count($all), $offered['objectCount']);

		$uuids = $service->uuidsFor('vo');
		self::assertCount(count($all), array_unique($uuids));
		self::assertSame(end($all)['uuid'], $uuids[0], 'the last-loaded object is removed first');
		self::assertSame(self::of('school')[0]['uuid'], end($uuids), 'the school is removed last');
	}//end testTheServiceOffersAndRemovesExactlyThisSet()

	/**
	 * The file is what the generator produces, so nobody edits the JSON by
	 * hand and the rules in scripts/example-sets/vo.py stay the truth.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-the-file-is-reproducible
	 */
	public function testTheFileIsWhatTheGeneratorProduces(): void {
		$python = trim((string)shell_exec('command -v python3 2>/dev/null'));
		if ($python === '') {
			self::markTestSkipped('python3 is not available here; run python3 scripts/example-sets/vo.py --check by hand.');
		}

		$script = dirname(__DIR__, 3) . '/scripts/example-sets/vo.py';
		exec(escapeshellarg($python) . ' ' . escapeshellarg($script) . ' --check 2>&1', $output, $exitCode);

		self::assertSame(0, $exitCode, implode("\n", $output));
	}//end testTheFileIsWhatTheGeneratorProduces()
}//end class
