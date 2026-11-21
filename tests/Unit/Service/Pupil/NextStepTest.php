<?php

/**
 * The pupil's one next step on the "Wat nu?" card.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Pupil;

use DateTimeImmutable;
use OCA\Learniq\Service\Pupil\NextStep;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Pupil\NextStep
 */
class NextStepTest extends TestCase {

	/**
	 * Monday 26 October 2026, 08.15, the morning Daan opens Vera's page.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-26T08:15:00+01:00');
	}//end now()

	/**
	 * A booked conference within 14 days comes first, with who chose and confirmed the time.
	 *
	 * @return void
	 */
	public function testVerasBookedConferenceIsTheNextStep(): void {
		$step = (new NextStep())->first(
			slots: [
				['id' => 's-later', 'lifecycle' => 'booked', 'startsAt' => '2026-11-12T18:00:00+01:00'],
				['id' => 's-vera', 'lifecycle' => 'acknowledged', 'startsAt' => '2026-10-29T18:00:00+01:00', 'acknowledgedAt' => '2026-10-20T09:00:00+02:00', 'guardianName' => 'Fatima Hulstkamp', 'location' => 'Lokaal 7'],
				['id' => 's-free', 'lifecycle' => 'free', 'startsAt' => '2026-10-27T18:00:00+01:00'],
			],
			visits: [['id' => 'v-1', 'lifecycle' => 'planned', 'plannedAt' => '2026-10-27T10:00:00+01:00']],
			flags: [['id' => 'f-1', 'lifecycle' => 'open']],
			now: self::now()
		);

		$this->assertSame(
			[
				'kind' => NextStep::CONFERENCE,
				'at' => '2026-10-29T18:00:00+01:00',
				'sourceId' => 's-vera',
				'chosenBy' => 'Fatima Hulstkamp',
				'confirmed' => true,
				'detail' => 'Lokaal 7',
				'lines' => ['review-results', 'review-attendance', 'review-notes'],
				'action' => 'record-conference',
			],
			$step
		);

	}//end testVerasBookedConferenceIsTheNextStep()

	/**
	 * A conference past the horizon, in the past or not booked does not count; a planned visit follows.
	 *
	 * @return void
	 */
	public function testAPlannedPlacementVisitFollowsWhenNoConferenceIsNear(): void {
		$step = (new NextStep())->first(
			slots: [
				['id' => 's-far', 'lifecycle' => 'booked', 'startsAt' => '2026-11-10T08:16:00+01:00'],
				['id' => 's-past', 'lifecycle' => 'booked', 'startsAt' => '2026-10-25T18:00:00+01:00'],
				['id' => 's-declined', 'lifecycle' => 'declined', 'startsAt' => '2026-10-28T18:00:00+01:00'],
				['id' => 's-garbled', 'lifecycle' => 'booked', 'startsAt' => 'soon'],
			],
			visits: [
				['id' => 'v-done', 'lifecycle' => 'done', 'plannedAt' => '2026-10-27T10:00:00+01:00'],
				['id' => 'v-late', 'lifecycle' => 'planned', 'plannedAt' => '2026-12-01T10:00:00+01:00', 'visitKind' => 'eindgesprek'],
				['id' => 'v-milan', 'lifecycle' => 'planned', 'plannedAt' => '2026-11-03T13:30:00+01:00', 'visitKind' => 'voortgangsbezoek'],
			],
			flags: [['id' => 'f-1', 'lifecycle' => 'open']],
			now: self::now()
		);

		$this->assertSame(NextStep::PLACEMENT_VISIT, $step['kind']);
		$this->assertSame('v-milan', $step['sourceId']);
		$this->assertSame('2026-11-03T13:30:00+01:00', $step['at']);
		$this->assertSame('voortgangsbezoek', $step['detail']);
		$this->assertSame('prepare-visit', $step['action']);
		$this->assertCount(3, $step['lines']);
		$this->assertFalse($step['confirmed']);

	}//end testAPlannedPlacementVisitFollowsWhenNoConferenceIsNear()

	/**
	 * The edge of the horizon counts; an unconfirmed booking says so.
	 *
	 * @return void
	 */
	public function testAConferenceExactlyFourteenDaysAheadCountsAndMayBeUnconfirmed(): void {
		$step = (new NextStep())->first(
			slots: [['id' => 's-edge', 'lifecycle' => 'booked', 'startsAt' => '2026-11-09T08:15:00+01:00']],
			visits: [],
			flags: [],
			now: self::now()
		);

		$this->assertSame('s-edge', $step['sourceId']);
		$this->assertFalse($step['confirmed']);
		$this->assertSame('', $step['chosenBy']);

	}//end testAConferenceExactlyFourteenDaysAheadCountsAndMayBeUnconfirmed()

	/**
	 * The oldest open signal is the step when nothing is planned; a report duty adds a line.
	 *
	 * @return void
	 */
	public function testTheOldestOpenSignalIsTheStepWithoutAnAppointment(): void {
		$step = (new NextStep())->first(
			slots: [],
			visits: [],
			flags: [
				['id' => 'f-resolved', 'lifecycle' => 'resolved', 'windowEnd' => '2026-09-01'],
				['id' => 'f-new', 'lifecycle' => 'open', 'windowEnd' => '2026-10-23', 'flagKind' => 'signal-verzuim'],
				['id' => 'f-old', 'lifecycle' => 'in-handling', 'windowEnd' => '2026-10-09', 'flagKind' => 'langdurig-relatief-verzuim'],
			],
			now: self::now()
		);

		$this->assertSame(
			[
				'kind' => NextStep::SIGNAL,
				'at' => null,
				'sourceId' => 'f-old',
				'chosenBy' => '',
				'confirmed' => false,
				'detail' => 'langdurig-relatief-verzuim',
				'lines' => ['contact-guardians', 'report-to-attendance-officer'],
				'action' => 'open-signal',
			],
			$step
		);

		$plain = (new NextStep())->first(slots: [], visits: [], flags: [['id' => 'f-new', 'lifecycle' => 'open', 'flagKind' => 'signal-verzuim']], now: self::now());
		$this->assertSame(['contact-guardians'], $plain['lines']);

	}//end testTheOldestOpenSignalIsTheStepWithoutAnAppointment()

	/**
	 * Nothing planned and nothing open: no next step.
	 *
	 * @return void
	 */
	public function testNothingApplicableGivesNoStep(): void {
		$this->assertNull(
			(new NextStep())->first(
				slots: [['id' => 's', 'lifecycle' => 'completed', 'startsAt' => '2026-10-27T18:00:00+01:00']],
				visits: [['id' => 'v', 'lifecycle' => 'proposed', 'plannedAt' => '2026-10-27T10:00:00+01:00']],
				flags: [['id' => 'f', 'lifecycle' => 'reported']],
				now: self::now()
			)
		);

	}//end testNothingApplicableGivesNoStep()
}//end class
