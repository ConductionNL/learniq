<?php

/**
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CourseDay;

use DateTimeImmutable;
use OCA\Learniq\Service\CourseDay\SessionPartAttendance;
use PHPUnit\Framework\TestCase;

/**
 * Attendance per part of the day.
 *
 * @covers \OCA\Learniq\Service\CourseDay\SessionPartAttendance
 */
class SessionPartAttendanceTest extends TestCase {

	/**
	 * Storingen zoeken aan warmtepompen: morning from 09.00, afternoon from 13.00.
	 *
	 * @return array<string, mixed>
	 */
	private static function session(): array {
		return [
			'id' => 's-1',
			'parts' => [
				['key' => 'afternoon', 'startsAt' => '2026-10-05T13:00:00+02:00'],
				['key' => 'morning', 'startsAt' => '2026-10-05T09:00:00+02:00'],
			],
		];
	}//end session()

	/**
	 * The morning register: 8 of 9 there, Mehmet Kaya in at 10.15.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function morning(): array {
		$records = [];
		for ($i = 1; $i <= 7; $i++) {
			$records[] = ['learnerId' => 'p-' . $i, 'part' => 'morning', 'status' => 'present'];
		}

		$records[] = ['learnerId' => 'mehmet', 'part' => 'morning', 'status' => 'late', 'arrivedAt' => '10:15'];
		$records[] = ['learnerId' => 'p-9', 'part' => 'morning', 'status' => 'absent-unexcused'];

		return $records;
	}//end morning()

	public function testTheAfternoonHasBegunAndWaitsForItsRegister(): void {
		$summary = SessionPartAttendance::summarise(
			session: self::session(),
			records: self::morning(),
			participants: 9,
			now: new DateTimeImmutable('2026-10-05T13:05:00+02:00')
		);

		self::assertSame('afternoon', $summary['due']);
		self::assertSame(['morning', 'afternoon'], array_column($summary['parts'], 'key'));
		self::assertSame(
			['key' => 'morning', 'begun' => true, 'registered' => true, 'there' => 8, 'of' => 9, 'late' => [['learnerId' => 'mehmet', 'arrivedAt' => '10:15']]],
			$summary['parts'][0]
		);
		self::assertSame(['key' => 'afternoon', 'begun' => true, 'registered' => false, 'there' => 0, 'of' => 9, 'late' => []], $summary['parts'][1]);
	}//end testTheAfternoonHasBegunAndWaitsForItsRegister()

	public function testBeforeTheAfternoonNothingIsDue(): void {
		$summary = SessionPartAttendance::summarise(
			session: self::session(),
			records: self::morning(),
			participants: 9,
			now: new DateTimeImmutable('2026-10-05T12:30:00+02:00')
		);

		self::assertNull($summary['due']);
		self::assertFalse($summary['parts'][1]['begun']);
	}//end testBeforeTheAfternoonNothingIsDue()

	public function testTheMorningIsDueWhenNobodyRegisteredIt(): void {
		$summary = SessionPartAttendance::summarise(
			session: self::session(),
			records: [['learnerId' => 'p-1', 'status' => 'present']],
			participants: 9,
			now: new DateTimeImmutable('2026-10-05T09:30:00+02:00')
		);

		self::assertSame('morning', $summary['due']);
		self::assertSame(0, $summary['parts'][0]['there'], 'A record without a part registers no part.');
	}//end testTheMorningIsDueWhenNobodyRegisteredIt()

	public function testASessionWithoutPartsHasNone(): void {
		$summary = SessionPartAttendance::summarise(
			session: ['parts' => [['key' => 'evening'], 'morning', ['startsAt' => '2026-10-05T09:00:00+02:00']]],
			records: [],
			participants: 3,
			now: new DateTimeImmutable('2026-10-05T13:05:00+02:00')
		);

		self::assertSame(['parts' => [], 'due' => null], $summary);
	}//end testASessionWithoutPartsHasNone()

	public function testAnInvalidArrivalTimeIsDroppedAndAParticipantCountsOnce(): void {
		$summary = SessionPartAttendance::summarise(
			session: self::session(),
			records: [
				['learnerId' => 'mehmet', 'part' => 'morning', 'status' => 'late', 'arrivedAt' => '25:99'],
				['learnerId' => 'mehmet', 'part' => 'morning', 'status' => 'present'],
			],
			participants: 9,
			now: new DateTimeImmutable('2026-10-05T10:00:00+02:00')
		);

		self::assertSame(1, $summary['parts'][0]['there']);
		self::assertSame([['learnerId' => 'mehmet', 'arrivedAt' => null]], $summary['parts'][0]['late']);
	}//end testAnInvalidArrivalTimeIsDroppedAndAParticipantCountsOnce()
}//end class
