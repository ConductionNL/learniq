<?php

/**
 * Golden test for the roll-call page row of one pupil.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Attendance
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Attendance;

use OCA\Learniq\Service\Attendance\RollCallMarks;
use PHPUnit\Framework\TestCase;

/**
 * Pins RollCallMarks::pupilRow() over profiles, saved records and reports
 * against golden/RollCallPupilRows.json. With RECORD_GOLDEN=1 the file is
 * written instead of compared.
 *
 * @covers \OCA\Learniq\Service\Attendance\RollCallMarks
 */
class RollCallMarksGoldenTest extends TestCase {

	/**
	 * Recorded rows.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__ . '/golden/RollCallPupilRows.json';

	/**
	 * Every combination of profile, record and report gives the recorded row.
	 *
	 * @return void
	 */
	public function testRowsMatchGolden(): void {
		$profiles = [
			'no profile' => null,
			'profile id' => ['id' => 'p-1'],
			'profile uuid' => ['uuid' => 'p-2'],
			'profile self' => ['@self' => ['id' => 'p-3']],
			'profile empty' => [],
		];
		$records = [
			'no record' => null,
			'late' => ['id' => 'r-1', 'status' => 'late', 'lateMinutes' => 12, 'reason' => 'bus', 'markedVia' => 'kiosk'],
			'absent text minutes' => ['uuid' => 'r-2', 'status' => 'absent-excused', 'lateMinutes' => '7', 'absenceReasonKind' => 'illness'],
			'bad values' => ['status' => 'left-early', 'lateMinutes' => '7a', 'absenceReasonKind' => 'holiday'],
			'bare record' => [],
		];
		$reports = [
			'no report' => null,
			'approved illness' => ['id' => 'x-1', 'lifecycle' => 'approved', 'reasonKind' => 'illness', 'reason' => 'flu', 'dateFrom' => '2026-01-05T00:00:00Z', 'dateTo' => '2026-01-06'],
			'approved medical' => ['uuid' => 'x-2', 'lifecycle' => 'approved', 'reasonKind' => 'medical-appointment'],
			'approved other' => ['lifecycle' => 'approved', 'reasonKind' => 'family', 'reason' => 'wedding'],
			'pending' => ['id' => 'x-3', 'lifecycle' => 'submitted', 'reasonKind' => 'illness', 'reason' => 'cold'],
			'no lifecycle' => ['id' => 'x-4', 'reasonKind' => 'illness'],
		];
		$marks = new RollCallMarks();
		$actual = [];
		foreach ($profiles as $profileName => $profile) {
			foreach ($records as $recordName => $record) {
				foreach ($reports as $reportName => $report) {
					$actual[$profileName . ' / ' . $recordName . ' / ' . $reportName] = $marks->pupilRow(
						pupil: ['learnerId' => 'learner-1', 'name' => 'Noor', 'profile' => $profile],
						day: ['record' => $record, 'report' => $report]
					);
				}
			}
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		foreach (json_decode((string)file_get_contents(self::GOLDEN), true) as $name => $expected) {
			self::assertSame($expected, $actual[$name], $name);
		}
	}//end testRowsMatchGolden()
}//end class
