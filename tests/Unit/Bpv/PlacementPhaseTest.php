<?php

/**
 * Learniq BPV placement phase tests
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Bpv
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Bpv;

use DateTimeImmutable;
use OCA\Learniq\Bpv\PlacementPhase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlacementPhase::class)]
final class PlacementPhaseTest extends TestCase {

	/**
	 * A placement of MT2A: company, active POK, period 1 September 2026 to 29 January 2027.
	 *
	 * @param array<string, mixed> $extra The fields that differ.
	 *
	 * @return array<string, mixed>
	 */
	private static function placement(array $extra=[]): array {
		return $extra + [
			'trainingCompanyName' => 'Bakker Techniek BV',
			'lifecycle' => 'active',
			'periodFrom' => '2026-09-01',
			'periodTo' => '2027-01-29',
			'hoursWaitingTotal' => 0,
		];

	}//end placement()

	/**
	 * The phase on a day.
	 *
	 * @param array<string, mixed>             $placement The placement.
	 * @param string                           $today     The day.
	 * @param array<int, array<string, mixed>> $visits    The visits.
	 *
	 * @return array{phase: string, phaseDueAt: ?string, lateLabel: string}
	 */
	private static function on(array $placement, string $today, array $visits=[]): array {
		return (new PlacementPhase())->derive($placement, $visits, new DateTimeImmutable($today));

	}//end on()

	public function testAPlacementWithoutACompanyIsSearchingAndLateInWeeks(): void {
		$searching = self::on(self::placement(['trainingCompanyName' => '', 'lifecycle' => 'proposed']), '2026-09-15');

		$this->assertSame(['phase' => 'searching', 'phaseDueAt' => '2026-09-01', 'lateLabel' => '2 weken te laat'], $searching);
		$this->assertSame('searching', self::on(self::placement(['trainingCompanyName' => ' ', 'lifecycle' => 'active']), '2026-08-01')['phase']);
		$this->assertSame('', self::on(self::placement(['trainingCompanyName' => '', 'lifecycle' => 'proposed']), '2026-08-01')['lateLabel']);

	}//end testAPlacementWithoutACompanyIsSearchingAndLateInWeeks()

	public function testAKvkNumberCountsAsACompany(): void {
		$this->assertSame('agreement', self::on(self::placement(['trainingCompanyName' => '', 'trainingCompanyKvkNumber' => '12345678', 'lifecycle' => 'confirmed']), '2026-08-20')['phase']);

	}//end testAKvkNumberCountsAsACompany()

	public function testTheSignedPokMovesAgreementToRunning(): void {
		$this->assertSame('agreement', self::on(self::placement(['lifecycle' => 'sbb-verification-pending']), '2026-09-10')['phase']);
		$this->assertSame(['phase' => 'running', 'phaseDueAt' => null, 'lateLabel' => ''], self::on(self::placement(), '2026-09-10'));

	}//end testTheSignedPokMovesAgreementToRunning()

	public function testAnAssessmentWithinFourteenDaysOrOverdueIsTheAssessmentPhase(): void {
		$visit = ['visitKind' => 'tussentijds-gesprek', 'lifecycle' => 'planned', 'plannedAt' => '2026-10-13T10:00:00+02:00'];

		$this->assertSame('running', self::on(self::placement(), '2026-09-28', [$visit])['phase']);
		$this->assertSame(['phase' => 'assessment', 'phaseDueAt' => '2026-10-13', 'lateLabel' => ''], self::on(self::placement(), '2026-09-29', [$visit]));
		$this->assertSame('te laat sinds 13 oktober', self::on(self::placement(), '2026-10-15', [$visit])['lateLabel']);
		$this->assertSame('1 week te laat', self::on(self::placement(), '2026-10-22', [$visit])['lateLabel']);

		$this->assertSame('running', self::on(self::placement(), '2026-10-15', [['lifecycle' => 'done'] + $visit])['phase']);
		$this->assertSame('running', self::on(self::placement(), '2026-10-15', [['visitKind' => 'voortgangsbezoek'] + $visit])['phase']);
		$this->assertSame('running', self::on(self::placement(), '2026-10-15', [['plannedAt' => null] + $visit])['phase']);

	}//end testAnAssessmentWithinFourteenDaysOrOverdueIsTheAssessmentPhase()

	public function testTheEarliestOpenAssessmentSetsTheDueDate(): void {
		$visits = [
			['visitKind' => 'eindgesprek', 'lifecycle' => 'proposed', 'plannedAt' => '2026-10-20'],
			['visitKind' => 'tussentijds-gesprek', 'lifecycle' => 'planned', 'plannedAt' => '2026-10-13'],
		];

		$this->assertSame('2026-10-13', self::on(self::placement(), '2026-10-01', $visits)['phaseDueAt']);

	}//end testTheEarliestOpenAssessmentSetsTheDueDate()

	public function testAnEndedPeriodRoundsOffUntilTheFinalAssessmentAndTheHoursAreDone(): void {
		$final = ['visitKind' => 'eindgesprek', 'lifecycle' => 'done', 'plannedAt' => '2027-01-25'];

		$this->assertSame(['phase' => 'rounding-off', 'phaseDueAt' => '2027-01-29', 'lateLabel' => '2 weken te laat'], self::on(self::placement(), '2027-02-12'));
		$this->assertSame('rounding-off', self::on(self::placement(['hoursWaitingTotal' => 8]), '2027-02-01', [$final])['phase']);
		$this->assertSame(['phase' => 'done', 'phaseDueAt' => null, 'lateLabel' => ''], self::on(self::placement(), '2027-02-01', [$final]));
		$this->assertSame('done', self::on(self::placement(['lifecycle' => 'completed']), '2026-10-01')['phase']);
		$this->assertSame('done', self::on(self::placement(['lifecycle' => 'terminated', 'trainingCompanyName' => '']), '2026-10-01')['phase']);

	}//end testAnEndedPeriodRoundsOffUntilTheFinalAssessmentAndTheHoursAreDone()

	public function testTheFactsKeyChangesWithEveryFactAndNothingElse(): void {
		$phases = new PlacementPhase();
		$visit  = ['visitKind' => 'tussentijds-gesprek', 'lifecycle' => 'proposed', 'plannedAt' => '2026-10-13'];
		$key    = $phases->factsKey(self::placement(), [$visit]);

		$this->assertSame($key, $phases->factsKey(self::placement(['phase' => 'done', 'lateLabel' => 'x', 'learnerRef' => 'other']), [$visit + ['schoolCoachId' => 'c']]));
		$this->assertNotSame($key, $phases->factsKey(self::placement(['lifecycle' => 'completed']), [$visit]));
		$this->assertNotSame($key, $phases->factsKey(self::placement(['hoursWaitingTotal' => 4]), [$visit]));
		$this->assertNotSame($key, $phases->factsKey(self::placement(), [['lifecycle' => 'planned'] + $visit]));
		$this->assertNotSame($key, $phases->factsKey(self::placement(), []));

	}//end testTheFactsKeyChangesWithEveryFactAndNothingElse()
}//end class
