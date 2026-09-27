<?php

/**
 * Learniq AttendanceThresholdCrossingGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-below-the-limit-is-refused
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\Learniq\Lifecycle\AttendanceThresholdCrossingGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the AttendanceThresholdCrossingGuard lifecycle guard (check-threshold).
 */
class AttendanceThresholdCrossingGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * Build a guard with a null logger.
	 *
	 * @return AttendanceThresholdCrossingGuard
	 */
	private function makeGuard(): AttendanceThresholdCrossingGuard {
		return new AttendanceThresholdCrossingGuard(new NullLogger());

	}//end makeGuard()

	/**
	 * A checkedMetricValue at or above limit, with a learner id set, is allowed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-records-a-real-per-learner-crossing-and-creates-an-attendanceflag
	 */
	public function testCrossingAtOrAboveLimitIsAllowed(): void {
		$guard = $this->makeGuard();

		$object = [
				'checkedLearnerId' => 'learner-1',
				'checkedMetricValue' => 16,
				'limit' => 16,
				'lifecycle' => 'active',
			];

		self::assertAllowed($guard->check($object, 'check-threshold', ''));

	}//end testCrossingAtOrAboveLimitIsAllowed()

	/**
	 * A checkedMetricValue below limit is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-below-the-limit-is-refused
	 */
	public function testBelowLimitIsRefused(): void {
		$guard = $this->makeGuard();

		$object = [
				'checkedLearnerId' => 'learner-1',
				'checkedMetricValue' => 10,
				'limit' => 16,
				'lifecycle' => 'active',
			];

		self::assertDenied($guard->check($object, 'check-threshold', ''));

	}//end testBelowLimitIsRefused()

	/**
	 * A missing checkedLearnerId is refused even when the value crosses the limit.
	 *
	 * @return void
	 */
	public function testMissingLearnerIdIsRefused(): void {
		$guard = $this->makeGuard();

		$object = [
				'checkedLearnerId' => '',
				'checkedMetricValue' => 20,
				'limit' => 16,
				'lifecycle' => 'active',
			];

		self::assertDenied($guard->check($object, 'check-threshold', ''));

	}//end testMissingLearnerIdIsRefused()

	/**
	 * A missing checkedMetricValue is refused.
	 *
	 * @return void
	 */
	public function testMissingMetricValueIsRefused(): void {
		$guard = $this->makeGuard();

		$object = [
				'checkedLearnerId' => 'learner-1',
				'limit' => 16,
				'lifecycle' => 'active',
			];

		self::assertDenied($guard->check($object, 'check-threshold', ''));

	}//end testMissingMetricValueIsRefused()

	/**
	 * A threshold with no numeric limit is refused (fail-closed).
	 *
	 * @return void
	 */
	public function testMissingLimitIsRefused(): void {
		$guard = $this->makeGuard();

		$object = [
				'checkedLearnerId' => 'learner-1',
				'checkedMetricValue' => 20,
				'lifecycle' => 'active',
			];

		self::assertDenied($guard->check($object, 'check-threshold', ''));

	}//end testMissingLimitIsRefused()
}//end class
