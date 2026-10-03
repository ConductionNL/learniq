<?php

/**
 * Learniq ReportPeriodComposeGuard unit tests.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/report-card/spec.md#scenario-compose-is-blocked-before-the-lock-date
 * @spec openspec/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\Learniq\Lifecycle\ReportPeriodComposeGuard;
use OCA\Learniq\Service\Grading\ReportPeriodLocks;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReportPeriodComposeGuard (ReportPeriod open -> composed).
 */
class ReportPeriodComposeGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * Build a guard with a mocked logger.
	 *
	 * @return ReportPeriodComposeGuard
	 */
	private function makeGuard(): ReportPeriodComposeGuard {
		return new ReportPeriodComposeGuard(new ReportPeriodLocks(objects: $this->createMock(ObjectService::class)), $this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * A materialised `isLocked: true` allows compose.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
	 */
	public function testMaterialisedIsLockedTrueAllowsCompose(): void {
		$guard = $this->makeGuard();
		$object = ['id' => 'period-1', 'isLocked' => true, 'lockDate' => '2020-01-01T00:00:00+00:00', 'lifecycle' => 'composed'];

		self::assertAllowed($guard->check($object, 'compose', ''));

	}//end testMaterialisedIsLockedTrueAllowsCompose()

	/**
	 * A materialised `isLocked: false` blocks compose.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-compose-is-blocked-before-the-lock-date
	 */
	public function testMaterialisedIsLockedFalseBlocksCompose(): void {
		$guard = $this->makeGuard();
		$object = ['id' => 'period-1', 'isLocked' => false, 'lockDate' => '2099-01-01T00:00:00+00:00', 'lifecycle' => 'composed'];

		self::assertDenied($guard->check($object, 'compose', ''));

	}//end testMaterialisedIsLockedFalseBlocksCompose()

	/**
	 * When the materialised value is absent, the guard falls back to computing
	 * isLocked from lockDate directly — a past lockDate allows compose.
	 *
	 * @return void
	 */
	public function testMissingMaterialisedValueFallsBackToPastLockDate(): void {
		$guard = $this->makeGuard();
		$object = ['id' => 'period-1', 'lockDate' => '2020-01-01T00:00:00+00:00', 'lifecycle' => 'composed'];

		self::assertAllowed($guard->check($object, 'compose', ''));

	}//end testMissingMaterialisedValueFallsBackToPastLockDate()

	/**
	 * When the materialised value is absent and lockDate is in the future,
	 * the fallback blocks compose.
	 *
	 * @return void
	 */
	public function testMissingMaterialisedValueFallsBackToFutureLockDate(): void {
		$guard = $this->makeGuard();
		$object = ['id' => 'period-1', 'lockDate' => '2099-01-01T00:00:00+00:00', 'lifecycle' => 'composed'];

		self::assertDenied($guard->check($object, 'compose', ''));

	}//end testMissingMaterialisedValueFallsBackToFutureLockDate()

	/**
	 * A null lockDate never locks — compose is blocked.
	 *
	 * @return void
	 */
	public function testNullLockDateBlocksCompose(): void {
		$guard = $this->makeGuard();
		$object = ['id' => 'period-1', 'lockDate' => null, 'lifecycle' => 'composed'];

		self::assertDenied($guard->check($object, 'compose', ''));

	}//end testNullLockDateBlocksCompose()
}//end class
