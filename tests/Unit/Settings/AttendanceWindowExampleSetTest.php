<?php

/**
 * Learniq attendance window example set tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/attendance-warns-before-the-threshold/specs/attendance/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Service\Attendance\AttendanceSummaryCalculator;
use OCA\Learniq\Service\Attendance\UnauthorisedHoursWindow;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The pupils the po and mbo example sets show nearing the leerplicht limit
 * carry the window count the server computes from their own records.
 */
#[CoversNothing]
#[UsesClass(UnauthorisedHoursWindow::class)]
#[UsesClass(AttendanceSummaryCalculator::class)]
class AttendanceWindowExampleSetTest extends TestCase {

	/**
	 * The day the stories are pinned to.
	 *
	 * @var string
	 */
	private const TODAY = '2026-10-05';

	/**
	 * Finn and Ryan in the primary school set are nearing the limit; Vera, Sami, Lina and Hamza (excused) are at zero.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-warns-before-the-threshold/specs/attendance/spec.md#requirement-staff-see-the-unauthorised-hours-against-the-limit-before-a-flag-exists
	 */
	public function testThePrimarySchoolWarnsAboutFinnAndRyan(): void {
		$rows = self::windowRows(set: 'po');

		self::assertSame(
			expected: [
				'po-leerling-147' => ['0 van 16 uur', false],
				'po-leerling-199' => ['0 van 16 uur', false],
				'po-leerling-167' => ['5,8 van 16 uur', true],
				'po-leerling-200' => ['11,5 van 16 uur', true],
				'po-leerling-201' => ['0 van 16 uur', false],
				'po-leerling-202' => ['0 van 16 uur', false],
			],
			actual: $rows
		);
	}//end testThePrimarySchoolWarnsAboutFinnAndRyan()

	/**
	 * Sem Visser in the college set is at 14 of 16, with no flag for him.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-warns-before-the-threshold/specs/attendance/spec.md#scenario-sem-at-14-of-16
	 */
	public function testTheCollegeWarnsAboutSemAtFourteen(): void {
		self::assertSame(expected: ['mbo-student-253' => ['14 van 16 uur', true]], actual: self::windowRows(set: 'mbo'));

		$flags = array_column(self::objects(set: 'mbo')['attendance-flag'], 'learnerId');
		self::assertNotContains(needle: 'mbo-student-253', haystack: $flags);
	}//end testTheCollegeWarnsAboutSemAtFourteen()

	/**
	 * Per 2026-2027 summary: its label and nearing flag, after asserting that
	 * every window field is what UnauthorisedHoursWindow computes from the
	 * learner's records against the set's leerplicht threshold.
	 *
	 * @param string $set The example set.
	 *
	 * @return array<string, array{0: string, 1: bool}> By learner id.
	 */
	private static function windowRows(string $set): array {
		$objects = self::objects(set: $set);
		$threshold = null;
		foreach ($objects['attendance-threshold'] as $row) {
			if ($row['kind'] === 'leerplicht-16uur') {
				$threshold = $row;
			}
		}

		self::assertNotNull(actual: $threshold);
		$sessions = AttendanceSummaryCalculator::sessionDays(
			rows: array_map(static fn (array $s): array => ['id' => $s['uuid']] + $s, $objects['session'])
		);
		$records = [];
		foreach ($objects['attendance-record'] as $record) {
			$records[$record['learnerId']][] = $record;
		}

		$window = new UnauthorisedHoursWindow();
		$rows = [];
		foreach ($objects['attendance-summary'] as $summary) {
			if ($summary['schoolYear'] !== '2026-2027') {
				continue;
			}

			$expected = $window->fields(records: ($records[$summary['learnerId']] ?? []), sessions: $sessions, threshold: $threshold, today: self::TODAY);
			self::assertEquals(
				expected: $expected,
				actual: array_intersect_key($summary, $expected),
				message: $set . ' ' . $summary['learnerId']
			);
			$rows[$summary['learnerId']] = [$summary['windowLabel'], $summary['thresholdNearing']];
		}

		return $rows;
	}//end windowRows()

	/**
	 * The seed objects of an example set, by schema.
	 *
	 * @param string $set The example set.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function objects(string $set): array {
		$data = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/profiles/' . $set . '.json'), true);

		return $data['x-openregister']['seedData']['objects'];
	}//end objects()
}//end class
