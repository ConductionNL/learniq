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
		$path = dirname(__DIR__, 3) . '/lib/Settings/profiles/' . $set . '.json';
		return json_decode((string)file_get_contents($path), true)['x-openregister']['seedData']['objects'];
	}//end objects()

	/**
	 * The accounts the secondary school portal declaration creates on load.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function accounts(): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/portals/vo.json';
		return json_decode((string)file_get_contents($path), true)['accounts'];
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
		return array_values(array_filter($rows, static fn (array $row): bool => ($row[$field] ?? null) === $value));
	}//end where()

	/**
	 * LqCijferschaal: an active numeric scale with four bands.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function testTheGradeScaleBoardHasALiveScaleWithFourBands(): void {
		$scales = self::where(self::objects('vo')['grade-scale'], 'name', 'Havo/vwo bovenbouw, 1 tot 10');
		self::assertCount(1, $scales);
		self::assertSame('active', $scales[0]['lifecycle']);
		self::assertSame(5.5, (float)$scales[0]['passThreshold']);
		self::assertCount(4, $scales[0]['bands']);
	}//end testTheGradeScaleBoardHasALiveScaleWithFourBands()

	/**
	 * LqCoToetsweek: a toetsweek in planning over classes and subjects of the set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function testTheToetsweekBoardHasAToetsweekInPlanning(): void {
		$objects = self::objects('vo');
		$periods = self::where(($objects['exam-period'] ?? []), 'name', 'Toetsweek 2');
		self::assertCount(1, $periods);
		self::assertSame('planning', $periods[0]['lifecycle']);
		self::assertLessThan($periods[0]['endsOn'], $periods[0]['startsOn']);
		self::assertNotEmpty($periods[0]['cohortIds']);
		self::assertNotEmpty($periods[0]['courseIds']);
	}//end testTheToetsweekBoardHasAToetsweekInPlanning()

	/**
	 * LqExZaak: a decided fraud case that annuls one schoolexamen result of the accused pupil.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function testTheFraudCaseBoardHasADecidedCaseOnAGradeOfThePupil(): void {
		$objects = self::objects('vo');
		$cases   = ($objects['fraud-case'] ?? []);
		self::assertCount(1, $cases);
		$case = $cases[0];
		self::assertSame('decided', $case['lifecycle']);
		self::assertSame('fraud-proven', $case['verdict']);
		self::assertSame('grade-annulment', $case['sanctionType']);
		self::assertNotEmpty($case['hearingRecords']);

		$grade = self::where($objects['grade-entry'], 'uuid', $case['contestedGradeEntryId']);
		self::assertCount(1, $grade);
		self::assertSame($case['accusedLearnerUserId'], $grade[0]['learnerId']);
		self::assertSame($case['accusedLearnerId'], $grade[0]['learnerRef']);
	}//end testTheFraudCaseBoardHasADecidedCaseOnAGradeOfThePupil()

	/**
	 * LqSchoolkosten: an active voluntary school trip for a class of the set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function testTheSchoolCostBoardHasAVoluntaryTrip(): void {
		$objects = self::objects('vo');
		$items   = ($objects['fee-item'] ?? []);
		self::assertCount(1, $items);
		self::assertSame('school-trip', $items[0]['kind']);
		self::assertTrue($items[0]['voluntary']);
		self::assertSame('active', $items[0]['lifecycle']);
		self::assertCount(1, self::where($objects['cohort'], 'uuid', $items[0]['linkedCohortId']));
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
		$objects = self::objects('vo');
		$notes   = ($objects['confidential-note'] ?? []);
		self::assertCount(1, $notes);
		self::assertSame('open', $notes[0]['status']);
		self::assertNotEmpty($notes[0]['destroyAfter']);

		$author = self::where($objects['staff'], 'ncUserId', $notes[0]['authorId']);
		self::assertCount(1, $author);
		self::assertContains('confidential-counsellor', $author[0]['roles']);

		$account = self::where(self::accounts(), 'userId', $notes[0]['authorId']);
		self::assertCount(1, $account);
		self::assertContains('confidential-counsellors', $account[0]['groups']);
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
		$guardians = array_values(array_filter(self::accounts(), static fn (array $a): bool => in_array('guardians', ($a['groups'] ?? []), true)));
		self::assertCount(1, $guardians);

		$children = array_values(array_filter(
			self::objects('vo')['learner-profile'],
			static fn (array $p): bool => in_array($guardians[0]['userId'], ($p['parentIds'] ?? []), true)
		));
		self::assertNotEmpty($children);
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
		$objects = self::objects('he');
		self::assertNotEmpty(self::where($objects['bsa-trajectory'], 'lifecycle', 'active'));

		$negative = array_values(array_filter(
			$objects['bsa-decision'],
			static fn (array $d): bool => $d['decisionType'] === 'negative' && ($d['warningIds'] ?? []) !== [] && ($d['studentHeardAt'] ?? null) !== null
		));
		self::assertNotEmpty($negative);
	}//end testTheBsaBoardsFindTheirRecordsInTheHigherEducationSet()
}//end class
