<?php

/**
 * Today's state of every pupil of a group, read through the register's reader.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Attendance;

use DateTimeZone;
use OCA\Learniq\Service\Attendance\RollCallReader;
use OCA\Learniq\Service\Attendance\TodayState;
use OCA\Learniq\Service\Attendance\TodayStateService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Attendance\TodayStateService
 * @uses   \OCA\Learniq\Service\Attendance\TodayState
 */
class TodayStateServiceTest extends TestCase {

	/**
	 * Spec scenario "Groep 7 on Monday morning": Lina at the dentist and Hamza
	 * reported ill read so, and the absent-today count is 2.
	 *
	 * @return void
	 */
	public function testGroep7OnMondayMorning(): void {
		$zone = new DateTimeZone('Europe/Amsterdam');
		$reader = $this->createMock(RollCallReader::class);
		$reader->expects($this->once())->method('sessionsOn')->with('groep-7', '2026-10-05', $zone)
			->willReturn([['id' => 'lesson-1'], ['uuid' => 'lesson-2'], ['name' => 'no id']]);
		$reader->method('recordsOf')->willReturnCallback(
			static fn (?string $sessionId): array => match ($sessionId) {
				'lesson-1' => ['sem' => ['status' => 'late'], 'vera' => ['status' => 'present']],
				'lesson-2' => ['sem' => ['status' => 'present'], 'vera' => ['status' => 'present']],
				default => [],
			}
		);
		$reader->expects($this->once())->method('reportsOn')->with(['lina', 'hamza', 'sem', 'vera', 'milan'], '2026-10-05')->willReturn(
			[
				'lina' => ['reasonKind' => 'medical-appointment'],
				'hamza' => ['reasonKind' => 'illness'],
			]
		);

		$states = (new TodayStateService(reader: $reader))->forCohort(
			cohort: ['id' => 'groep-7', 'learnerIds' => ['lina', 'hamza', 'sem', 'vera', 'milan', '', 7]],
			date: '2026-10-05',
			zone: $zone
		);

		$this->assertSame(
			[
				'lina' => TodayState::APPOINTMENT,
				'hamza' => TodayState::REPORTED_ILL,
				'sem' => TodayState::LATE,
				'vera' => TodayState::IN_CLASS,
				'milan' => TodayState::NOT_REGISTERED,
			],
			$states
		);
		$this->assertSame(2, TodayStateService::absentCount(states: $states));
	}//end testGroep7OnMondayMorning()

	/**
	 * A group without pupils or without an id reads nothing.
	 *
	 * @return void
	 */
	public function testAGroupWithoutPupilsOrIdReadsNothing(): void {
		$reader = $this->createMock(RollCallReader::class);
		$reader->expects($this->never())->method('sessionsOn');
		$service = new TodayStateService(reader: $reader);
		$zone = new DateTimeZone('UTC');

		$this->assertSame([], $service->forCohort(cohort: ['id' => 'g', 'learnerIds' => []], date: '2026-10-05', zone: $zone));
		$this->assertSame([], $service->forCohort(cohort: ['learnerIds' => ['a']], date: '2026-10-05', zone: $zone));
	}//end testAGroupWithoutPupilsOrIdReadsNothing()
}//end class
