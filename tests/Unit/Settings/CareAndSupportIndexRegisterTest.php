<?php

/**
 * Unit tests for the `care-and-support-index` register-JSON declarations.
 *
 * Verifies LearningPlan's new six-week-clock calculations and notification,
 * the ObservationInstrument schema shape, and the Trajectory/
 * TrajectoryStatusUpdate lifecycle/appendOnly shapes — mirroring
 * ReportCardComposerRegisterTest's established pattern for this register.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/care-and-support-index/specs/learning-plan/spec.md#requirement-learningplan-declares-a-materialised-six-week-activation-clock
 * @spec openspec/changes/care-and-support-index/specs/learning-plan/spec.md#requirement-observationinstrument-records-structured-kleuter-leerlijn-observations
 * @spec openspec/changes/care-and-support-index/specs/learning-plan/spec.md#requirement-trajectory-tracks-a-bovenschoolse-voorziening-placement-lifecycle-with-an-append-only-status-log
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the care-and-support-index register declarations.
 */
class CareAndSupportIndexRegisterTest extends TestCase {

	/**
	 * Decoded register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Load the register configuration once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);

	}//end setUp()

	/**
	 * LearningPlan's six-week-clock calculations are materialised and match
	 * the declared expressions.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/care-and-support-index/specs/learning-plan/spec.md#scenario-isoverdueforactivation-is-true-once-the-deadline-has-passed-on-a-still-draft-plan
	 */
	public function testLearningPlanSixWeekClockCalculationShapes(): void {
		$calcs = $this->config['components']['schemas']['LearningPlan']['x-openregister-calculations'];

		self::assertTrue($calcs['daysUntilSixWeekDeadline']['materialise']);
		self::assertTrue($calcs['isOverdueForActivation']['materialise']);
		self::assertTrue($calcs['sixWeekDeadlineApproaching']['materialise']);

		$overdue = $calcs['isOverdueForActivation']['expression']['and'];
		self::assertSame('lifecycle', $overdue[0]['eq'][0]['prop']);
		self::assertSame('draft', $overdue[0]['eq'][1]);
		self::assertSame('sixWeekDeadline', $overdue[1]['ne'][0]['prop']);
		self::assertNull($overdue[1]['ne'][1]);
		self::assertSame('sixWeekDeadline', $overdue[2]['lte'][0]['prop']);
		self::assertArrayHasKey('now', $overdue[2]['lte'][1]);

	}//end testLearningPlanSixWeekClockCalculationShapes()

	/**
	 * LearningPlan declares the sixWeekDeadlineApproaching notification as a
	 * calculatedChange trigger (false -> true) to coordinatorId, mirroring
	 * TlvApplication.tlvExpiringSoon's idiom.
	 *
	 * @return void
	 */
	public function testSixWeekDeadlineApproachingNotificationShape(): void {
		$notification = $this->config['components']['schemas']['LearningPlan']['x-openregister-notifications']['sixWeekDeadlineApproaching'];

		self::assertSame('calculatedChange', $notification['trigger']['type']);
		self::assertSame('sixWeekDeadlineApproaching', $notification['trigger']['field']);
		self::assertTrue($notification['trigger']['condition']['eq']);
		self::assertFalse($notification['trigger']['previously']['eq']);
		self::assertSame('coordinatorId', $notification['recipients'][0]['field']);

	}//end testSixWeekDeadlineApproachingNotificationShape()

	/**
	 * LearningPlan's outflowBandwidth/trackedGrowth/uitstroombestemming
	 * fields are declared, nullable, and additive.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/care-and-support-index/specs/learning-plan/spec.md#scenario-a-tracked-growth-entry-persists-against-the-declared-bandwidth
	 */
	public function testOutflowBandwidthAndTrackedGrowthShape(): void {
		$props = $this->config['components']['schemas']['LearningPlan']['properties'];

		self::assertTrue($props['uitstroombestemming']['nullable']);
		self::assertTrue($props['outflowBandwidth']['nullable']);
		self::assertSame(
			['lowerVaardigheidsscore', 'upperVaardigheidsscore', 'scale'],
			array_keys($props['outflowBandwidth']['properties'])
		);
		self::assertSame(['recordedAt', 'vaardigheidsscore'], $props['trackedGrowth']['items']['required']);

	}//end testOutflowBandwidthAndTrackedGrowthShape()

	/**
	 * ObservationInstrument is registered with the full six-leerlijn and
	 * four-level scale enums.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/care-and-support-index/specs/learning-plan/spec.md#scenario-an-observationinstrument-persists-one-entry-per-leerlijn-per-observation-moment
	 */
	public function testObservationInstrumentRegisteredWithFullEnums(): void {
		$schemas = $this->config['components']['schemas'];
		self::assertArrayHasKey('ObservationInstrument', $schemas);

		$entryProps = $schemas['ObservationInstrument']['properties']['entries']['items']['properties'];
		self::assertSame(
			['taal', 'rekenen', 'sociaal-emotioneel', 'motoriek', 'spel', 'leren-leren'],
			$entryProps['leerlijn']['enum']
		);
		self::assertSame(
			['toont-geen-interesse', 'is-aan-het-ontdekken', 'kan-het-met-hulp', 'kan-het-zelfstandig'],
			$entryProps['level']['enum']
		);

	}//end testObservationInstrumentRegisteredWithFullEnums()

	/**
	 * Trajectory's declared lifecycle transition table matches sera's
	 * documented traject-status sequence exactly, and TrajectoryStatusUpdate
	 * is appendOnly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/care-and-support-index/specs/learning-plan/spec.md#scenario-a-trajectory-moves-through-its-full-lifecycle-with-a-status-update-at-each-stage
	 */
	public function testTrajectoryLifecycleAndStatusUpdateAppendOnly(): void {
		$schemas = $this->config['components']['schemas'];
		self::assertArrayHasKey('Trajectory', $schemas);
		self::assertArrayHasKey('TrajectoryStatusUpdate', $schemas);

		$transitions = $schemas['Trajectory']['x-openregister-lifecycle']['transitions'];
		self::assertSame('referral', $schemas['Trajectory']['x-openregister-lifecycle']['initial']);
		self::assertSame(['referral', 'preparation'], [$transitions['prepare']['from'], $transitions['prepare']['to']]);
		self::assertSame(['preparation', 'scheduled'], [$transitions['schedule']['from'], $transitions['schedule']['to']]);
		self::assertSame(['scheduled', 'running'], [$transitions['start']['from'], $transitions['start']['to']]);
		self::assertSame(['preparation', 'scheduled', 'running'], $transitions['stop']['from']);
		self::assertSame('stopped', $transitions['stop']['to']);

		self::assertTrue($schemas['TrajectoryStatusUpdate']['appendOnly']);
		self::assertSame('Trajectory', $schemas['TrajectoryStatusUpdate']['properties']['trajectoryId']['$ref']);

	}//end testTrajectoryLifecycleAndStatusUpdateAppendOnly()

	/**
	 * The register's info.version was bumped for this change (at least
	 * 0.22.0), following the established "at least" pattern.
	 *
	 * @return void
	 */
	public function testRegisterVersionBumped(): void {
		self::assertTrue(
			version_compare($this->config['info']['version'], '0.22.0', '>='),
			'info.version MUST be at least 0.22.0 (care-and-support-index\'s own bump) — got ' . $this->config['info']['version']
		);

	}//end testRegisterVersionBumped()
}//end class
