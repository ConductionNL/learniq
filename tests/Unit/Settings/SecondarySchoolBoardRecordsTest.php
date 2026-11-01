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
		self::assertLessThan(maximum: $periods[0]['endsOn'], actual: $periods[0]['startsOn']);
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
		$guardians = array_values(array: array_filter(array: self::accounts(), callback: static fn (array $a): bool => in_array(needle: 'guardians', haystack: ($a['groups'] ?? []), strict: true)));
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
				callback: static fn (array $d): bool => $d['decisionType'] === 'negative' && ($d['warningIds'] ?? []) !== [] && ($d['studentHeardAt'] ?? null) !== null
			)
		);
		self::assertNotEmpty(actual: $negative);
	}//end testTheBsaBoardsFindTheirRecordsInTheHigherEducationSet()
}//end class
