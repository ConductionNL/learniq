<?php

/**
 * The example sets hold a record for every learniq detail board.
 *
 * The drawn boards on identity.conduction.nl/screens show a secondary school.
 * A detail board can only be compared with the live app when the loaded set
 * holds a record of that kind: a live grade scale (LqCijferschaal), a
 * toetsweek (LqCoToetsweek), a decided fraud case (LqExZaak), a voluntary
 * school cost (LqSchoolkosten) and a confidential note its counsellor can
 * read (LqZoNotitie) in the secondary school set, and a guardian account
 * linked to a pupil for the guardian boards (LqOu*). The bsa boards
 * (LqBsaBesluit, LqStudievoortgang) draw a university of applied sciences,
 * so the higher education set holds those.
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

use PHPUnit\Framework\TestCase;

/**
 * One record per detail board in lib/Settings/profiles/vo.json and he.json.
 */
class SecondarySchoolBoardRecordsTest extends TestCase {

	/**
	 * The seed buckets of one shipped set.
	 *
	 * @param string $set The set id.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function objects(string $set): array {
		$path = dirname(path: __DIR__, levels: 3) . '/lib/Settings/profiles/' . $set . '.json';
		return json_decode(json: (string)file_get_contents(filename: $path), associative: true)['x-openregister']['seedData']['objects'];
	}//end objects()

	/**
	 * The accounts the secondary school portal declaration creates on load.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function accounts(): array {
		$path = dirname(path: __DIR__, levels: 3) . '/lib/Settings/portals/vo.json';
		return json_decode(json: (string)file_get_contents(filename: $path), associative: true)['accounts'];
	}//end accounts()

	/**
	 * Rows of a bucket whose field equals a value.
	 *
	 * @param array<int, array<string, mixed>> $rows  The bucket.
	 * @param string                           $field The field.
	 * @param mixed                            $value The value.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function where(array $rows, string $field, mixed $value): array {
		return array_values(array: array_filter(array: $rows, callback: static fn (array $row): bool => ($row[$field] ?? null) === $value));
	}//end where()

	/**
	 * LqCijferschaal: an active numeric scale with four bands.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function testTheGradeScaleBoardHasALiveScaleWithFourBands(): void {
		$scales = self::where(rows: self::objects(set: 'vo')['grade-scale'], field: 'name', value: 'Havo/vwo bovenbouw, 1 tot 10');
		self::assertCount(expectedCount: 1, haystack: $scales);
		self::assertSame(expected: 'active', actual: $scales[0]['lifecycle']);
		self::assertSame(expected: 5.5, actual: (float)$scales[0]['passThreshold']);
		self::assertCount(expectedCount: 4, haystack: $scales[0]['bands']);
	}//end testTheGradeScaleBoardHasALiveScaleWithFourBands()

	/**
	 * LqCoToetsweek: a toetsweek in planning over classes and subjects of the set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function testTheToetsweekBoardHasAToetsweekInPlanning(): void {
		$objects = self::objects(set: 'vo');
		$periods = self::where(rows: ($objects['exam-period'] ?? []), field: 'name', value: 'Toetsweek 2');
		self::assertCount(expectedCount: 1, haystack: $periods);
		self::assertSame(expected: 'planning', actual: $periods[0]['lifecycle']);
		self::assertLessThan(expected: $periods[0]['endsOn'], actual: $periods[0]['startsOn']);
		self::assertNotEmpty(actual: $periods[0]['cohortIds']);
		self::assertNotEmpty(actual: $periods[0]['courseIds']);
	}//end testTheToetsweekBoardHasAToetsweekInPlanning()

	/**
	 * LqExZaak: a decided fraud case that annuls one schoolexamen result of the accused pupil.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function testTheFraudCaseBoardHasADecidedCaseOnAGradeOfThePupil(): void {
		$objects = self::objects(set: 'vo');
		$cases   = ($objects['fraud-case'] ?? []);
		self::assertCount(expectedCount: 1, haystack: $cases);
		$case = $cases[0];
		self::assertSame(expected: 'decided', actual: $case['lifecycle']);
		self::assertSame(expected: 'fraud-proven', actual: $case['verdict']);
		self::assertSame(expected: 'grade-annulment', actual: $case['sanctionType']);
		self::assertNotEmpty(actual: $case['hearingRecords']);

		$grade = self::where(rows: $objects['grade-entry'], field: 'uuid', value: $case['contestedGradeEntryId']);
		self::assertCount(expectedCount: 1, haystack: $grade);
		self::assertSame(expected: $case['accusedLearnerUserId'], actual: $grade[0]['learnerId']);
		self::assertSame(expected: $case['accusedLearnerId'], actual: $grade[0]['learnerRef']);
	}//end testTheFraudCaseBoardHasADecidedCaseOnAGradeOfThePupil()

	/**
	 * LqSchoolkosten: an active voluntary school trip for a class of the set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function testTheSchoolCostBoardHasAVoluntaryTrip(): void {
		$objects = self::objects(set: 'vo');
		$items   = ($objects['fee-item'] ?? []);
		self::assertCount(expectedCount: 1, haystack: $items);
		self::assertSame(expected: 'school-trip', actual: $items[0]['kind']);
		self::assertTrue(condition: $items[0]['voluntary']);
		self::assertSame(expected: 'active', actual: $items[0]['lifecycle']);
		self::assertCount(expectedCount: 1, haystack: self::where(rows: $objects['cohort'], field: 'uuid', value: $items[0]['linkedCohortId']));
	}//end testTheSchoolCostBoardHasAVoluntaryTrip()

	/**
	 * LqZoNotitie: an open note written by a confidential counsellor whose
	 * account the portal declaration creates in the counsellors' group, so the
	 * note's author can read it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function testTheConfidentialNoteBoardHasANoteItsCounsellorCanRead(): void {
		$objects = self::objects(set: 'vo');
		$notes   = ($objects['confidential-note'] ?? []);
		self::assertCount(expectedCount: 1, haystack: $notes);
		self::assertSame(expected: 'open', actual: $notes[0]['status']);
		self::assertNotEmpty(actual: $notes[0]['destroyAfter']);

		$author = self::where(rows: $objects['staff'], field: 'ncUserId', value: $notes[0]['authorId']);
		self::assertCount(expectedCount: 1, haystack: $author);
		self::assertContains(needle: 'confidential-counsellor', haystack: $author[0]['roles']);

		$account = self::where(rows: self::accounts(), field: 'userId', value: $notes[0]['authorId']);
		self::assertCount(expectedCount: 1, haystack: $account);
		self::assertContains(needle: 'confidential-counsellors', haystack: $account[0]['groups']);
	}//end testTheConfidentialNoteBoardHasANoteItsCounsellorCanRead()

	/**
	 * LqOu*: a guardian account in the guardians group, named as a parent of
	 * the story pupil, so the guardian dashboard has a child to show.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function testTheGuardianBoardsHaveAGuardianAccountOfAPupil(): void {
		$guardians = array_values(
			array: array_filter(
				array: self::accounts(),
				callback: static fn (array $a): bool => in_array(needle: 'guardians', haystack: ($a['groups'] ?? []), strict: true)
			)
		);
		self::assertCount(expectedCount: 1, haystack: $guardians);

		$children = array_values(
			array: array_filter(
				array: self::objects(set: 'vo')['learner-profile'],
				callback: static fn (array $p): bool => in_array(needle: $guardians[0]['userId'], haystack: ($p['parentIds'] ?? []), strict: true)
			)
		);
		self::assertNotEmpty(actual: $children);
	}//end testTheGuardianBoardsHaveAGuardianAccountOfAPupil()

	/**
	 * The names the boards draw: Zuiddrecht College, the pupil Fenna de Jong
	 * of class 3HV-B (havo/vwo leerjaar 3) with her mentor Pieter Jansen, her
	 * mother Marieke de Jong (the guardian account), her teachers and Sem
	 * Bakker in the fraud case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function testTheSetCarriesTheNamesTheBoardsDraw(): void {
		$objects = self::objects(set: 'vo');
		self::assertSame(expected: 'Zuiddrecht College', actual: $objects['school'][0]['name']);

		$names    = array_column(array: self::accounts(), column_key: 'displayName', index_key: 'userId');
		$expected = [
			'vo-leerling-121' => 'Fenna de Jong',
			'vo-ouder-227'    => 'Marieke de Jong',
			'vo-docent-01'    => 'Pieter Jansen',
			'vo-docent-03'    => 'Fatma Yildiz',
			'vo-docent-07'    => 'Sander Bos',
			'vo-docent-10'    => 'Joost Willems',
		];
		foreach ($expected as $userId => $name) {
			self::assertSame(expected: $name, actual: ($names[$userId] ?? null), message: $userId);
			$staff = self::where(rows: $objects['staff'], field: 'ncUserId', value: $userId);
			if ($staff !== []) {
				self::assertSame(expected: $name, actual: $staff[0]['name'], message: $userId);
			}
		}

		$fenna = array_values(
			array: array_filter(
				array: $objects['learner-profile'],
				callback: static fn (array $p): bool => ($p['givenName'] ?? '') === 'Fenna' && ($p['familyName'] ?? '') === 'de Jong'
			)
		);
		self::assertCount(expectedCount: 1, haystack: $fenna);
		self::assertSame(expected: '3HV-B · Pieter Jansen', actual: $fenna[0]['groupLabel']);
		self::assertContains(needle: 'vo-ouder-227', haystack: $fenna[0]['parentIds']);

		$class = self::where(rows: $objects['cohort'], field: 'name', value: '3HV-B');
		self::assertCount(expectedCount: 1, haystack: $class);
		self::assertSame(expected: 3, actual: $class[0]['programmeYear']);
		self::assertSame(expected: 'vo-docent-01', actual: $class[0]['teacherIds'][0]);
		self::assertSame(expected: [], actual: self::where(rows: $objects['cohort'], field: 'name', value: 'H4b'));

		$father = self::where(rows: $objects['learner-profile'], field: 'givenName', value: 'Erik');
		self::assertContains(needle: 'de Jong', haystack: array_column(array: $father, column_key: 'familyName'));

		$case    = $objects['fraud-case'][0];
		$accused = self::where(rows: $objects['learner-profile'], field: 'uuid', value: $case['accusedLearnerId']);
		self::assertSame(expected: ['Sem', 'Bakker'], actual: [$accused[0]['givenName'], $accused[0]['familyName']]);

		$trip = self::where(rows: $objects['cohort'], field: 'uuid', value: $objects['fee-item'][0]['linkedCohortId']);
		self::assertSame(expected: '4H1', actual: $trip[0]['name']);
	}//end testTheSetCarriesTheNamesTheBoardsDraw()

	/**
	 * LqBsaBesluit and LqStudievoortgang: the higher education set holds an
	 * active bsa trajectory and a negative decision after a warning and a hearing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function testTheBsaBoardsFindTheirRecordsInTheHigherEducationSet(): void {
		$objects = self::objects(set: 'he');
		self::assertNotEmpty(actual: self::where(rows: $objects['bsa-trajectory'], field: 'lifecycle', value: 'active'));

		$negative = array_values(
			array: array_filter(
				array: $objects['bsa-decision'],
				callback: static fn (array $d): bool => $d['decisionType'] === 'negative'
					&& ($d['warningIds'] ?? []) !== []
					&& ($d['studentHeardAt'] ?? null) !== null
			)
		);
		self::assertNotEmpty(actual: $negative);
	}//end testTheBsaBoardsFindTheirRecordsInTheHigherEducationSet()

	/**
	 * The pupil of the secondary school set with this name.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $objects The seed buckets.
	 * @param string                                          $given   The given name.
	 * @param string                                          $family  The family name.
	 *
	 * @return array<string, mixed>
	 */
	private static function pupil(array $objects, string $given, string $family): array {
		$pupils = array_values(
			array: array_filter(
				array: $objects['learner-profile'],
				callback: static fn (array $p): bool => ($p['givenName'] ?? '') === $given && ($p['familyName'] ?? '') === $family
			)
		);
		self::assertCount(expectedCount: 1, haystack: $pupils, message: $given . ' ' . $family);
		return $pupils[0];
	}//end pupil()

	/**
	 * The pupils the dossier, grade and attendance boards draw: Daan Visser,
	 * Julia Peters and Lucas van Dijk, each with an active enrolment in 3HV-B.
	 * Daan has the care note and the support request (LqNotitie,
	 * LqDossierTijdlijn, LqZoOndersteuning), Julia the concept 8,2 for
	 * Nederlands (LqCijfer) and Lucas the unexcused absence at Nederlands
	 * (LqRegistratie).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vo-board-records-for-the-pupil-boards/specs/example-sets/spec.md
	 */
	public function testThePupilBoardsFindDaanJuliaAndLucasIn3hvb(): void {
		$objects  = self::objects(set: 'vo');
		$class    = self::where(rows: $objects['cohort'], field: 'name', value: '3HV-B')[0];
		$courses  = array_column(array: $objects['course'], column_key: 'name', index_key: 'uuid');
		$sessions = array_column(array: $objects['session'], column_key: null, index_key: 'uuid');
		$pupils   = [];
		foreach ([['Daan', 'Visser'], ['Julia', 'Peters'], ['Lucas', 'van Dijk']] as [$given, $family]) {
			$pupil = self::pupil(objects: $objects, given: $given, family: $family);
			self::assertContains(needle: $pupil['ncUserId'], haystack: $class['learnerIds'], message: $given);
			$active = array_filter(
				array: self::where(rows: $objects['enrolment'], field: 'learnerId', value: $pupil['ncUserId']),
				callback: static fn (array $e): bool => $e['lifecycle'] === 'active' && $e['cohortId'] === $class['uuid']
			);
			self::assertCount(expectedCount: 1, haystack: $active, message: $given);
			$pupils[$given] = $pupil;
		}

		$notes = self::where(rows: $objects['dossier-note'], field: 'learnerId', value: $pupils['Daan']['ncUserId']);
		$care  = array_values(array: array_filter(array: $notes, callback: static fn (array $n): bool => $n['date'] === '2026-10-02'));
		self::assertCount(expectedCount: 1, haystack: $care);
		self::assertSame(expected: ['concern', 'care-team-only', 'vo-docent-01'], actual: [$care[0]['category'], $care[0]['confidentiality'], $care[0]['authorId']]);
		$requests = self::where(rows: $objects['support-request'], field: 'learnerId', value: $pupils['Daan']['ncUserId']);
		self::assertCount(expectedCount: 1, haystack: $requests);
		self::assertSame(expected: ['high', 'in-deliberation'], actual: [$requests[0]['urgency'], $requests[0]['lifecycle']]);

		$grades = array_values(
			array: array_filter(
				array: self::where(rows: $objects['grade-entry'], field: 'learnerId', value: $pupils['Julia']['ncUserId']),
				callback: static fn (array $g): bool => $g['cohortId'] === $class['uuid']
			)
		);
		self::assertCount(expectedCount: 1, haystack: $grades);
		self::assertSame(expected: [8.2, 2, 'concept', 'Nederlands'], actual: [$grades[0]['value'], $grades[0]['weight'], $grades[0]['lifecycle'], $courses[$grades[0]['courseId']]]);
		$plan = self::where(rows: $objects['curriculum-plan'], field: 'uuid', value: $grades[0]['curriculumPlanId'])[0];
		self::assertContains(needle: $grades[0]['componentId'], haystack: array_column(array: $plan['components'], column_key: 'componentId'));

		$marks = array_values(
			array: array_filter(
				array: self::where(rows: $objects['attendance-record'], field: 'learnerId', value: $pupils['Lucas']['ncUserId']),
				callback: static fn (array $m): bool => $m['status'] === 'absent-unexcused' && $m['cohortId'] === $class['uuid']
			)
		);
		self::assertCount(expectedCount: 1, haystack: $marks);
		$session = $sessions[$marks[0]['sessionId']];
		self::assertSame(expected: ['Nederlands', '2026-10-05', 'vo-docent-01'], actual: [$session['title'], substr(string: $session['startsAt'], offset: 0, length: 10), $marks[0]['markedBy']]);
	}//end testThePupilBoardsFindDaanJuliaAndLucasIn3hvb()

	/**
	 * The test boards (LqToetsInstellingen, LqLlToetsMaken, LqToetsNakijken):
	 * 3HV-B has Biologie tests in leerjaar 3, one open to take on the
	 * reference day, one published and not yet open with an access code, and a
	 * closed Nederlands test.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vo-board-records-for-the-pupil-boards/specs/example-sets/spec.md
	 */
	public function testTheTestBoardsFindTestsOf3hvb(): void {
		$objects = self::objects(set: 'vo');
		$class   = self::where(rows: $objects['cohort'], field: 'name', value: '3HV-B')[0];
		$courses = array_column(array: $objects['course'], column_key: 'name', index_key: 'uuid');
		$exams   = self::where(rows: $objects['exam'], field: 'cohortId', value: $class['uuid']);
		$today   = '2026-10-05T12:00:00+02:00';
		$open    = array_filter(
			array: $exams,
			callback: static fn (array $e): bool => $e['lifecycle'] === 'published'
				&& new \DateTimeImmutable($e['availableFrom']) <= new \DateTimeImmutable($today)
				&& new \DateTimeImmutable($today) <= new \DateTimeImmutable($e['availableUntil'])
		);
		self::assertCount(expectedCount: 1, haystack: $open);
		self::assertSame(expected: 'Biologie', actual: $courses[array_values(array: $open)[0]['courseId']]);

		$later = self::where(rows: $exams, field: 'title', value: 'Toets hoofdstuk 3: erfelijkheid');
		self::assertCount(expectedCount: 1, haystack: $later);
		self::assertSame(expected: 'published', actual: $later[0]['lifecycle']);
		self::assertGreaterThan(expected: new \DateTimeImmutable($today), actual: new \DateTimeImmutable($later[0]['availableFrom']));
		self::assertNotEmpty(actual: $later[0]['accessCode']);

		$closed = self::where(rows: $exams, field: 'lifecycle', value: 'closed');
		self::assertSame(expected: ['Nederlands'], actual: array_values(array: array_unique(array: array_map(static fn (array $e): string => $courses[$e['courseId']], $closed))));
	}//end testTheTestBoardsFindTestsOf3hvb()

	/**
	 * The assignment boards (LqOpdracht, LqBeoordelaars, LqInleveringNakijken):
	 * Betoog schrijven for 3HV-B with two markers per submission, 14 handed-in
	 * and 2 draft submissions, Fenna's among them, and Fatma Yildiz's mark on
	 * Fenna's work.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vo-board-records-for-the-pupil-boards/specs/example-sets/spec.md
	 */
	public function testTheAssignmentBoardsFindBetoogSchrijvenWithSubmissions(): void {
		$objects     = self::objects(set: 'vo');
		$class       = self::where(rows: $objects['cohort'], field: 'name', value: '3HV-B')[0];
		$assignments = self::where(rows: $objects['assignment'], field: 'title', value: 'Betoog schrijven');
		self::assertCount(expectedCount: 1, haystack: $assignments);
		$assignment = $assignments[0];
		self::assertSame(expected: [$class['uuid'], 2, 'published'], actual: [$assignment['cohortId'], $assignment['markersPerSubmission'], $assignment['lifecycle']]);

		$submissions = self::where(rows: ($objects['submission'] ?? []), field: 'assignmentId', value: $assignment['uuid']);
		$states      = array_count_values(array: array_column(array: $submissions, column_key: 'lifecycle'));
		self::assertSame(expected: ['draft' => 2, 'submitted' => 14], actual: [
			'draft'     => ($states['draft'] ?? 0),
			'submitted' => ($states['submitted'] ?? 0),
		]);
		foreach ($submissions as $submission) {
			self::assertEmpty(actual: array_diff($submission['learnerIds'], $class['learnerIds']), message: $submission['slug']);
		}

		$fenna  = self::pupil(objects: $objects, given: 'Fenna', family: 'de Jong');
		$hers   = array_values(
			array: array_filter(
				array: $submissions,
				callback: static fn (array $s): bool => in_array(needle: $fenna['ncUserId'], haystack: $s['learnerIds'], strict: true)
			)
		);
		self::assertCount(expectedCount: 1, haystack: $hers);
		self::assertSame(expected: 'submitted', actual: $hers[0]['lifecycle']);
		$marks = self::where(rows: ($objects['submission-mark'] ?? []), field: 'submissionId', value: $hers[0]['uuid']);
		self::assertSame(expected: ['vo-docent-03' => 'submitted', 'vo-docent-01' => 'draft'], actual: array_column(array: $marks, column_key: 'lifecycle', index_key: 'markerId'));
	}//end testTheAssignmentBoardsFindBetoogSchrijvenWithSubmissions()

	/**
	 * The admission, threshold, course and regulation boards: Emma de Vries
	 * after her intake in the 2027-2028 round (LqCoAanmelding), the school's
	 * own 8-hour threshold (LqVerzuimgrens), Biologie 3 tweetalig with its
	 * English name (LqCursusInstellingen), the Meldcode regulation
	 * (LqCfVerplichting) and the Nederlands lesson of 3HV-B on Tuesday 6
	 * October, 2nd hour (LqLes).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vo-board-records-for-the-pupil-boards/specs/example-sets/spec.md
	 */
	public function testTheRemainingBoardsFindTheirRecords(): void {
		$objects = self::objects(set: 'vo');
		$rounds  = self::where(rows: $objects['admissions-round'], field: 'academicYear', value: '2027-2028');
		self::assertCount(expectedCount: 1, haystack: $rounds);
		$emma = array_values(
			array: array_filter(
				array: $objects['admission'],
				callback: static fn (array $a): bool => $a['applicantGivenName'] === 'Emma' && $a['applicantFamilyName'] === 'de Vries'
			)
		);
		self::assertCount(expectedCount: 1, haystack: $emma);
		self::assertSame(expected: [$rounds[0]['uuid'], 'intake-completed', true], actual: [$emma[0]['admissionsRoundId'], $emma[0]['lifecycle'], $emma[0]['intakeCompleted']]);

		$own = self::where(rows: $objects['attendance-threshold'], field: 'name', value: 'Vroeg signaal bij verzuim');
		self::assertCount(expectedCount: 1, haystack: $own);
		self::assertSame(expected: ['generic', 'unexcused-lesuren', 8, 50], actual: [$own[0]['kind'], $own[0]['metric'], $own[0]['limit'], $own[0]['lessonHourMinutes']]);

		$course = self::where(rows: $objects['course'], field: 'name_nl', value: 'Biologie 3 tweetalig');
		self::assertCount(expectedCount: 1, haystack: $course);
		self::assertSame(expected: ['Biology 3 bilingual', 'en'], actual: [$course[0]['name'], $course[0]['language']]);

		$meldcode = self::where(rows: ($objects['regulation'] ?? []), field: 'slug', value: 'MELDCODE');
		self::assertCount(expectedCount: 1, haystack: $meldcode);
		self::assertSame(expected: ['Meldcode huiselijk geweld en kindermishandeling', 'published'], actual: [$meldcode[0]['name'], $meldcode[0]['lifecycle']]);

		$class   = self::where(rows: $objects['cohort'], field: 'name', value: '3HV-B')[0];
		$lessons = array_filter(
			array: self::where(rows: $objects['session'], field: 'cohortId', value: $class['uuid']),
			callback: static fn (array $s): bool => $s['title'] === 'Nederlands' && $s['startsAt'] === '2026-10-06T09:20:00+02:00'
		);
		self::assertCount(expectedCount: 1, haystack: $lessons);
	}//end testTheRemainingBoardsFindTheirRecords()
}//end class
