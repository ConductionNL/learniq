<?php

/**
 * Learniq AttendanceSummaryCalculator unit tests.
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
 *
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-absence-and-lateness-are-counted-per-learner-per-school-year
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Attendance;

use OCA\Learniq\Service\Attendance\AttendanceSummaryCalculator;
use OCA\Learniq\Service\Calendar\SchoolYear as SchoolCalendarYear;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The counting rules of the attendance summary.
 */
class AttendanceSummaryCalculatorTest extends TestCase {

	/**
	 * A lesson row as OpenRegister returns it.
	 *
	 * @param string $id    Session uuid.
	 * @param string $start ISO start.
	 * @param string $end   ISO end.
	 *
	 * @return array<string, mixed>
	 */
	private static function session(string $id, string $start, string $end): array {
		return ['id' => $id, 'startsAt' => $start, 'endsAt' => $end];
	}//end session()

	/**
	 * An attendance record.
	 *
	 * @param string               $sessionId Session uuid.
	 * @param string               $status    Status.
	 * @param array<string, mixed> $extra     More fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function record(string $sessionId, string $status, array $extra=[]): array {
		return array_merge(
			['sessionId' => $sessionId, 'learnerId' => 'pupil-1', 'status' => $status, 'markedAt' => '2026-03-02T08:40:00+01:00'],
			$extra
		);
	}//end record()

	/**
	 * Days, permission and lateness of a primary school pupil.
	 *
	 * @return void
	 */
	public function testCountsDaysPermissionAndLateness(): void {
		$sessions = AttendanceSummaryCalculator::sessionDays(
			rows: [
				self::session(id: 's1', start: '2026-03-02T08:30:00+01:00', end: '2026-03-02T14:15:00+01:00'),
				self::session(id: 's2', start: '2026-03-03T08:30:00+01:00', end: '2026-03-03T14:15:00+01:00'),
				self::session(id: 's3', start: '2026-03-05T08:30:00+01:00', end: '2026-03-05T14:15:00+01:00'),
				self::session(id: 's4', start: '2026-03-06T08:30:00+01:00', end: '2026-03-06T14:15:00+01:00'),
				self::session(id: 's5', start: '2026-03-09T08:30:00+01:00', end: '2026-03-09T14:15:00+01:00'),
				self::session(id: 's6', start: '2026-03-10T08:30:00+01:00', end: '2026-03-10T14:15:00+01:00'),
				self::session(id: 's7', start: '2026-03-11T08:30:00+01:00', end: '2026-03-11T12:15:00+01:00'),
			]
		);
		$records = [
			self::record(sessionId: 's1', status: 'absent-excused', extra: ['absenceReasonKind' => 'illness']),
			self::record(sessionId: 's2', status: 'absent-excused'),
			self::record(sessionId: 's3', status: 'absent-unexcused'),
			self::record(sessionId: 's4', status: 'late', extra: ['lateMinutes' => 10, 'minutesAttended' => 335]),
			// Written before lateMinutes existed: 345 minutes long, 340 attended.
			self::record(sessionId: 's5', status: 'late', extra: ['minutesAttended' => 340]),
			self::record(sessionId: 's6', status: 'left-early', extra: ['minutesAttended' => 255]),
			self::record(sessionId: 's7', status: 'present'),
		];

		$years = (new AttendanceSummaryCalculator())->summarise(records: $records, sessions: $sessions);

		self::assertSame(
			expected: [
				'2025-2026' => [
					'absentDays' => 3,
					'absentAuthorisedDays' => 2,
					'absentUnauthorisedDays' => 1,
					'lateCount' => 2,
					'lateMinutes' => 15,
				],
			],
			actual: $years
		);
	}//end testCountsDaysPermissionAndLateness()

	/**
	 * Two absences on one day count as one day; one unexcused makes it unauthorised.
	 *
	 * @return void
	 */
	public function testADayWithMixedAbsencesCountsOnceWithoutPermission(): void {
		$sessions = AttendanceSummaryCalculator::sessionDays(
			rows: [
				self::session(id: 'l1', start: '2026-02-03T08:30:00+01:00', end: '2026-02-03T09:20:00+01:00'),
				self::session(id: 'l3', start: '2026-02-03T10:30:00+01:00', end: '2026-02-03T11:20:00+01:00'),
				self::session(id: 'l5', start: '2026-02-04T08:30:00+01:00', end: '2026-02-04T09:20:00+01:00'),
				self::session(id: 'l6', start: '2026-02-04T09:20:00+01:00', end: '2026-02-04T10:10:00+01:00'),
			]
		);
		$records = [
			self::record(sessionId: 'l1', status: 'absent-excused'),
			self::record(sessionId: 'l3', status: 'absent-unexcused'),
			self::record(sessionId: 'l5', status: 'absent-excused'),
			self::record(sessionId: 'l6', status: 'absent-excused'),
		];

		$years = (new AttendanceSummaryCalculator())->summarise(records: $records, sessions: $sessions);

		self::assertSame(expected: 2, actual: $years['2025-2026']['absentDays']);
		self::assertSame(expected: 1, actual: $years['2025-2026']['absentUnauthorisedDays']);
		self::assertSame(expected: 1, actual: $years['2025-2026']['absentAuthorisedDays']);
	}//end testADayWithMixedAbsencesCountsOnceWithoutPermission()

	/**
	 * A school that starts its year on 1 September counts late August in the year before.
	 *
	 * @return void
	 */
	public function testTheSchoolYearTurnsOnTheSchoolsOwnStart(): void {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default): string {
				if ($key === SchoolCalendarYear::KEY) {
					return '09-01';
				}

				return $default;
			}
		);
		$calculator = new AttendanceSummaryCalculator(schoolYear: new SchoolCalendarYear($config));

		self::assertSame(expected: '2025-2026', actual: $calculator->schoolYearOf(date: '2026-08-20'));
		self::assertSame(expected: '2026-2027', actual: $calculator->schoolYearOf(date: '2026-09-01'));

		$sessions = AttendanceSummaryCalculator::sessionDays(
			rows: [
				self::session(id: 'late-august', start: '2026-08-20T08:30:00+02:00', end: '2026-08-20T12:00:00+02:00'),
				self::session(id: 'september', start: '2026-09-02T08:30:00+02:00', end: '2026-09-02T12:00:00+02:00'),
			]
		);
		$years = $calculator->summarise(
			records: [self::record(sessionId: 'late-august', status: 'absent-unexcused'), self::record(sessionId: 'september', status: 'absent-excused')],
			sessions: $sessions
		);
		self::assertSame(expected: 1, actual: $years['2025-2026']['absentUnauthorisedDays']);
		self::assertSame(expected: 1, actual: $years['2026-2027']['absentAuthorisedDays']);
	}//end testTheSchoolYearTurnsOnTheSchoolsOwnStart()

	/**
	 * The school year turns on 1 August, in the offset stored on the lesson.
	 *
	 * @return void
	 */
	public function testTheSchoolYearTurnsOnTheFirstOfAugust(): void {
		self::assertSame(expected: '2025-2026', actual: (new AttendanceSummaryCalculator())->schoolYearOf(date: '2026-07-31'));
		self::assertSame(expected: '2026-2027', actual: (new AttendanceSummaryCalculator())->schoolYearOf(date: '2026-08-01'));
		self::assertSame(expected: '2025-2026', actual: (new AttendanceSummaryCalculator())->schoolYearOf(date: '2026-01-05'));
		self::assertNull(actual: (new AttendanceSummaryCalculator())->schoolYearOf(date: 'yesterday'));

		$sessions = AttendanceSummaryCalculator::sessionDays(
			rows: [
				self::session(id: 'july', start: '2026-07-10T08:30:00+02:00', end: '2026-07-10T12:00:00+02:00'),
				self::session(id: 'august', start: '2026-08-24T08:30:00+02:00', end: '2026-08-24T14:00:00+02:00'),
				// 00:30 on 1 August in Amsterdam is still 31 July in UTC: the stored offset decides.
				self::session(id: 'midnight', start: '2026-08-01T00:30:00+02:00', end: '2026-08-01T01:00:00+02:00'),
			]
		);
		$years = (new AttendanceSummaryCalculator())->summarise(
			records: [
				self::record(sessionId: 'july', status: 'absent-unexcused'),
				self::record(sessionId: 'august', status: 'absent-excused'),
				self::record(sessionId: 'midnight', status: 'late', extra: ['lateMinutes' => 4])
			],
			sessions: $sessions
		);

		self::assertSame(expected: 1, actual: $years['2025-2026']['absentUnauthorisedDays']);
		self::assertSame(expected: 1, actual: $years['2026-2027']['absentAuthorisedDays']);
		self::assertSame(expected: 4, actual: $years['2026-2027']['lateMinutes']);
	}//end testTheSchoolYearTurnsOnTheFirstOfAugust()

	/**
	 * A record whose lesson cannot be read counts on the day of markedAt.
	 *
	 * @return void
	 */
	public function testARecordWithoutItsLessonCountsOnItsMarkedDay(): void {
		$years = (new AttendanceSummaryCalculator())->summarise(
			records: [
				self::record(sessionId: 'gone', status: 'absent-unexcused', extra: ['markedAt' => '2025-09-15T08:40:00+02:00']),
				self::record(sessionId: 'gone', status: 'late')
			],
			sessions: []
		);

		self::assertSame(expected: 1, actual: $years['2025-2026']['absentUnauthorisedDays']);
		// No lateMinutes and no lesson length: counted, without minutes.
		self::assertSame(expected: 1, actual: $years['2025-2026']['lateCount']);
		self::assertSame(expected: 0, actual: $years['2025-2026']['lateMinutes']);
	}//end testARecordWithoutItsLessonCountsOnItsMarkedDay()

	/**
	 * A record without any usable date is left out.
	 *
	 * @return void
	 */
	public function testARecordWithoutADateIsLeftOut(): void {
		$years = (new AttendanceSummaryCalculator())->summarise(
			records: [['sessionId' => 'x', 'status' => 'absent-unexcused']],
			sessions: []
		);

		self::assertSame(expected: [], actual: $years);
	}//end testARecordWithoutADateIsLeftOut()

	/**
	 * The empty counts every new summary starts from.
	 *
	 * @return void
	 */
	public function testEmptyCounts(): void {
		self::assertSame(
			expected: ['absentDays' => 0, 'absentAuthorisedDays' => 0, 'absentUnauthorisedDays' => 0, 'lateCount' => 0, 'lateMinutes' => 0],
			actual: AttendanceSummaryCalculator::emptyCounts()
		);
	}//end testEmptyCounts()
	/**
	 * Each lesson keyed by id, uuid or `@self.id`, with its local date and
	 * whole minutes; lessons without an id or a start are left out, and a
	 * lesson without a later end has no length.
	 *
	 * @return void
	 */
	public function testSessionDaysKeysEveryDatedLesson(): void {
		$days = AttendanceSummaryCalculator::sessionDays(
			rows: [
				['id' => 'a', 'startsAt' => '2026-03-02T08:30:00+01:00', 'endsAt' => '2026-03-02T09:20:59+01:00'],
				['uuid' => 'b', 'startsAt' => '2026-03-03T23:30:00-05:00', 'endsAt' => '2026-03-03T23:00:00-05:00'],
				['@self' => ['id' => 'c'], 'startsAt' => '2026-03-04T08:30:00+01:00'],
				['id' => 'd', 'startsAt' => '2026-03-05T08:30:00+01:00', 'endsAt' => 'later'],
				['id' => 'e', 'startsAt' => 'soon'],
				['id' => '', 'startsAt' => '2026-03-06T08:30:00+01:00'],
				['startsAt' => '2026-03-06T08:30:00+01:00'],
				['id' => 'f', 'startsAt' => '2026-03-07T08:30:00+01:00', 'endsAt' => '2026-03-07T08:30:00+01:00'],
			]
		);

		self::assertSame(
			expected: [
				'a' => ['date' => '2026-03-02', 'minutes' => 50],
				'b' => ['date' => '2026-03-03', 'minutes' => null],
				'c' => ['date' => '2026-03-04', 'minutes' => null],
				'd' => ['date' => '2026-03-05', 'minutes' => null],
				'f' => ['date' => '2026-03-07', 'minutes' => null],
			],
			actual: $days
		);
	}//end testSessionDaysKeysEveryDatedLesson()

	/**
	 * Late minutes come from a whole-number `lateMinutes`, else the lesson
	 * length minus the minutes attended, never below zero.
	 *
	 * @return void
	 */
	public function testLateMinutesForEveryShapeOfRecord(): void {
		$sessions = ['s' => ['date' => '2026-03-02', 'minutes' => 50], 'open' => ['date' => '2026-03-02', 'minutes' => null]];
		$cases = [
			'int' => [['lateMinutes' => 7], 's', 7],
			'digit string' => [['lateMinutes' => '12'], 's', 12],
			'negative int' => [['lateMinutes' => -3], 's', 0],
			'not digits, attended' => [['lateMinutes' => '1.5', 'minutesAttended' => 45], 's', 5],
			'float, attended string' => [['lateMinutes' => 2.0, 'minutesAttended' => '40'], 's', 10],
			'attended longer' => [['minutesAttended' => 60], 's', 0],
			'attended not numeric' => [['minutesAttended' => 'most'], 's', 0],
			'lesson without length' => [['minutesAttended' => 10], 'open', 0],
			'no lesson' => [['minutesAttended' => 10], 'gone', 0],
		];

		foreach ($cases as $label => [$extra, $sessionId, $minutes]) {
			$years = (new AttendanceSummaryCalculator())->summarise(
				records: [self::record(sessionId: $sessionId, status: 'late', extra: $extra)],
				sessions: $sessions
			);
			self::assertSame(expected: $minutes, actual: $years['2025-2026']['lateMinutes'], message: $label);
			self::assertSame(expected: 1, actual: $years['2025-2026']['lateCount'], message: $label);
		}
	}//end testLateMinutesForEveryShapeOfRecord()

	/**
	 * School years come out in order, and uncounted or undated records are skipped.
	 *
	 * @return void
	 */
	public function testSchoolYearsComeOutSorted(): void {
		$years = (new AttendanceSummaryCalculator())->summarise(
			records: [
				self::record(sessionId: 'x', status: 'absent-excused', extra: ['markedAt' => '2027-01-10T08:00:00+01:00']),
				self::record(sessionId: 'x', status: 'present', extra: ['markedAt' => '2024-01-10T08:00:00+01:00']),
				self::record(sessionId: 'x', status: 'absent-unexcused', extra: ['markedAt' => '2025-01-10T08:00:00+01:00']),
				self::record(sessionId: 'x', status: 'absent-unexcused', extra: ['markedAt' => 'not a date']),
				['sessionId' => 'x', 'markedAt' => '2025-01-10T08:00:00+01:00'],
			],
			sessions: []
		);

		self::assertSame(expected: ['2024-2025', '2026-2027'], actual: array_keys($years));
		self::assertSame(expected: 1, actual: $years['2024-2025']['absentUnauthorisedDays']);
		self::assertSame(expected: 1, actual: $years['2026-2027']['absentAuthorisedDays']);
	}//end testSchoolYearsComeOutSorted()

	/**
	 * A stored row is unchanged when every recounted field matches: numbers
	 * also as numeric strings, teachers in any order.
	 *
	 * @return void
	 */
	public function testUnchangedComparesEveryRecountedField(): void {
		$calculator = new AttendanceSummaryCalculator();
		$data = ['absentDays' => 2, 'teacherIds' => ['jan', 'anna'], 'schoolYear' => '2025-2026'];

		self::assertTrue(
			condition: $calculator->unchanged(
				row: ['absentDays' => '2', 'teacherIds' => ['anna', 'jan'], 'schoolYear' => '2025-2026', 'extra' => 1],
				data: $data
			)
		);
		self::assertTrue(condition: $calculator->unchanged(row: [], data: []));
		self::assertFalse(
			condition: $calculator->unchanged(row: ['absentDays' => 3, 'teacherIds' => ['anna', 'jan'], 'schoolYear' => '2025-2026'], data: $data)
		);
		self::assertFalse(
			condition: $calculator->unchanged(row: ['absentDays' => 'two', 'teacherIds' => ['anna', 'jan'], 'schoolYear' => '2025-2026'], data: $data)
		);
		self::assertFalse(
			condition: $calculator->unchanged(row: ['absentDays' => 2, 'teacherIds' => ['jan'], 'schoolYear' => '2025-2026'], data: $data)
		);
		self::assertFalse(
			condition: $calculator->unchanged(row: ['absentDays' => 2, 'teacherIds' => 'jan', 'schoolYear' => '2025-2026'], data: $data)
		);
		self::assertFalse(condition: $calculator->unchanged(row: ['absentDays' => 2, 'teacherIds' => ['anna', 'jan']], data: $data));
		self::assertTrue(condition: $calculator->unchanged(row: ['teacherIds' => null], data: ['teacherIds' => []]));
		self::assertFalse(condition: $calculator->unchanged(row: ['lateMinutes' => null], data: ['lateMinutes' => 0]));
	}//end testUnchangedComparesEveryRecountedField()
}//end class
