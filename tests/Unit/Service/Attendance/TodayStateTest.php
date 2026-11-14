<?php

/**
 * A pupil's state today, from the day's marks and the report that covers it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Attendance;

use OCA\Learniq\Service\Attendance\TodayState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Attendance\TodayState
 */
class TodayStateTest extends TestCase {

	/**
	 * Each day reads as one state.
	 *
	 * @param array<int, array<string, mixed>> $marks    The marks of the day.
	 * @param array<string, mixed>|null        $report   The report covering the day.
	 * @param string                           $expected The state.
	 *
	 * @return void
	 */
	#[DataProvider('days')]
	public function testEachDayReadsAsOneState(array $marks, ?array $report, string $expected): void {
		$this->assertSame($expected, TodayState::of(marks: $marks, report: $report));
	}//end testEachDayReadsAsOneState()

	/**
	 * The days.
	 *
	 * @return array<string, array{0: array<int, array<string, mixed>>, 1: array<string, mixed>|null, 2: string}>
	 */
	public static function days(): array {
		$present = ['status' => 'present'];
		return [
			'nobody marked yet' => [[], null, TodayState::NOT_REGISTERED],
			'a mark without a status' => [[['status' => null]], null, TodayState::NOT_REGISTERED],
			'in class' => [[$present, $present], null, TodayState::IN_CLASS],
			'late this morning, in now' => [[['status' => 'late'], $present], null, TodayState::LATE],
			'Hamza reported ill' => [[], ['reasonKind' => 'illness'], TodayState::REPORTED_ILL],
			'the report wins over a present mark' => [[$present], ['reasonKind' => 'illness'], TodayState::REPORTED_ILL],
			'Lina at the dentist' => [[], ['reasonKind' => 'medical-appointment'], TodayState::APPOINTMENT],
			'reported for a funeral' => [[], ['reasonKind' => 'bereavement'], TodayState::REPORTED_ABSENT],
			'marked absent ill by the teacher' => [[$present, ['status' => 'absent-excused', 'absenceReasonKind' => 'illness']], null, TodayState::REPORTED_ILL],
			'marked absent for an appointment' => [[['status' => 'absent-excused', 'absenceReasonKind' => 'appointment']], null, TodayState::APPOINTMENT],
			'absent without a word' => [[['status' => 'absent-unexcused']], null, TodayState::ABSENT],
			'went home early' => [[$present, ['status' => 'left-early']], null, TodayState::ABSENT],
			'back after being absent' => [[['status' => 'absent-unexcused'], $present], null, TodayState::IN_CLASS],
		];
	}//end days()
}//end class
