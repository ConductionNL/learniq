<?php

/**
 * Unit tests for the AttendanceThreshold calculation/aggregation/transition declarations.
 *
 * IMPORTANT SCOPE NOTE: `x-openregister-calculations`/`x-openregister-aggregations`
 * are evaluated by OpenRegister core at runtime, which does not live in this
 * repository. This test verifies the declared SHAPE is correct, mirroring
 * ReportCardComposerRegisterTest's established pattern.
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
 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#requirement-threshold-crossing-is-a-declared-calculation-trigger
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the AttendanceThreshold declarations added by attendance-threshold-calculation.
 */
class AttendanceThresholdRegisterTest extends TestCase {

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
	 * unexcusedRecordCount aggregates absent-unexcused AttendanceRecords for
	 * this threshold's own cohort.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-threshold-crossing-fires-via-declared-calculation-trigger
	 */
	public function testUnexcusedRecordCountAggregation(): void {
		$agg = $this->config['components']['schemas']['AttendanceThreshold']['x-openregister-aggregations']['unexcusedRecordCount'];

		self::assertSame('attendance-record', $agg['from']);
		self::assertSame('count', $agg['metric']);
		self::assertSame('@self.cohortId', $agg['where']['cohortId']);
		self::assertSame('absent-unexcused', $agg['where']['status']);

	}//end testUnexcusedRecordCountAggregation()

	/**
	 * unexcusedLesuren and isThresholdCrossed are materialised calculations.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-threshold-crossing-fires-via-declared-calculation-trigger
	 */
	public function testCalculationsAreMaterialised(): void {
		$calc = $this->config['components']['schemas']['AttendanceThreshold']['x-openregister-calculations'];

		self::assertTrue($calc['unexcusedLesuren']['materialise']);
		self::assertSame('number', $calc['unexcusedLesuren']['type']);
		self::assertSame('@aggregate.unexcusedRecordCount', $calc['unexcusedLesuren']['expression']['*'][0]['prop']);

		self::assertTrue($calc['isThresholdCrossed']['materialise']);
		self::assertSame('boolean', $calc['isThresholdCrossed']['type']);
		self::assertSame('unexcusedLesuren', $calc['isThresholdCrossed']['expression']['gte'][0]['prop']);
		self::assertSame('limit', $calc['isThresholdCrossed']['expression']['gte'][1]['prop']);

	}//end testCalculationsAreMaterialised()

	/**
	 * The existing thresholdCrossed notification still triggers on
	 * unexcusedLesuren (now a real field) and now also notifies coordinator.
	 *
	 * @return void
	 */
	public function testThresholdCrossedNotificationNotifiesMentorAndCoordinator(): void {
		$rule = $this->config['components']['schemas']['AttendanceThreshold']['x-openregister-notifications']['thresholdCrossed'];

		self::assertSame('calculatedChange', $rule['trigger']['type']);
		self::assertSame('unexcusedLesuren', $rule['trigger']['field']);
		self::assertSame(['mentor', 'coordinator'], $rule['recipients'][0]['groups']);

	}//end testThresholdCrossedNotificationNotifiesMentorAndCoordinator()

	/**
	 * check-threshold is a guarded active->active self-loop with the
	 * transient checked* inputs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-records-a-real-per-learner-crossing-and-creates-an-attendanceflag
	 */
	public function testCheckThresholdTransitionShape(): void {
		$transition = $this->config['components']['schemas']['AttendanceThreshold']['x-openregister-lifecycle']['transitions']['check-threshold'];

		self::assertSame('active', $transition['from']);
		self::assertSame('active', $transition['to']);
		self::assertSame('OCA\\Learniq\\Lifecycle\\AttendanceThresholdCrossingGuard', $transition['requires']);

		$inputFields = array_column($transition['inputs'], 'required', 'field');
		self::assertTrue($inputFields['checkedLearnerId']);
		self::assertTrue($inputFields['checkedMetricValue']);
		self::assertFalse($inputFields['checkedWindowStart']);
		self::assertFalse($inputFields['checkedWindowEnd']);
		self::assertFalse($inputFields['checkedBreachingRecordIds']);

	}//end testCheckThresholdTransitionShape()

	/**
	 * The five transient checked* properties are declared, nullable.
	 *
	 * @return void
	 */
	public function testCheckedPropertiesDeclaredNullable(): void {
		$properties = $this->config['components']['schemas']['AttendanceThreshold']['properties'];

		foreach (['checkedLearnerId', 'checkedMetricValue', 'checkedWindowStart', 'checkedWindowEnd', 'checkedBreachingRecordIds'] as $field) {
			self::assertArrayHasKey($field, $properties, "AttendanceThreshold must declare $field");
			self::assertTrue($properties[$field]['nullable'] ?? false, "$field must be nullable");
		}

	}//end testCheckedPropertiesDeclaredNullable()

	/**
	 * The register's info.version was bumped for this change.
	 *
	 * @return void
	 */
	public function testRegisterVersionBumped(): void {
		self::assertTrue(
			version_compare($this->config['info']['version'], '0.22.0', '>='),
			'info.version MUST be at least 0.22.0 (attendance-threshold-calculation\'s own bump) — got ' . $this->config['info']['version']
		);

	}//end testRegisterVersionBumped()
}//end class
