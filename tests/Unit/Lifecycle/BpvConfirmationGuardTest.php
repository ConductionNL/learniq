<?php

/**
 * Learniq BpvConfirmationGuard unit tests.
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
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-bpvplacement-confirmation-is-gated-on-verified-leerbedrijf-status
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\Learniq\Lifecycle\BpvConfirmationGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the BpvConfirmationGuard lifecycle guard (sbb-verification-pending → confirmed).
 */
class BpvConfirmationGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * Build a guard with a stub logger.
	 *
	 * @return BpvConfirmationGuard
	 */
	private function makeGuard(): BpvConfirmationGuard {
		return new BpvConfirmationGuard($this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * `verified` status allows the `confirm` transition.
	 *
	 * @return void
	 */
	public function testVerifiedStatusAllowsConfirm(): void {
		$object = [
				'id' => 'placement-1',
				'trainingCompanyVerification' => ['status' => 'verified', 'erkenningNumber' => 'SBB-123'],
				'lifecycle' => 'confirmed',
			];

		self::assertAllowed($this->makeGuard()->check($object, 'confirm', ''));

	}//end testVerifiedStatusAllowsConfirm()

	/**
	 * Every non-verified status blocks the transition.
	 *
	 * @return void
	 */
	public function testNonVerifiedStatusesBlockConfirm(): void {
		foreach (['unverified', 'pending', 'rejected', 'expired'] as $status) {
			$object = [
					'id' => 'placement-1',
					'trainingCompanyVerification' => ['status' => $status],
					'lifecycle' => 'confirmed',
				];

			self::assertDenied($this->makeGuard()->check($object, 'confirm', ''), "status '{$status}' should block confirm");
		}

	}//end testNonVerifiedStatusesBlockConfirm()

	/**
	 * A missing leerbedrijfVerification block fails closed.
	 *
	 * @return void
	 */
	public function testMissingVerificationBlockFailsClosed(): void {
		$object = ['id' => 'placement-1', 'lifecycle' => 'confirmed'];

		self::assertDenied($this->makeGuard()->check($object, 'confirm', ''));

	}//end testMissingVerificationBlockFailsClosed()

	/**
	 * A non-array leerbedrijfVerification value also fails closed (defensive).
	 *
	 * @return void
	 */
	public function testNonArrayVerificationBlockFailsClosed(): void {
		$object = ['id' => 'placement-1', 'trainingCompanyVerification' => 'verified', 'lifecycle' => 'confirmed'];

		self::assertDenied($this->makeGuard()->check($object, 'confirm', ''));

	}//end testNonArrayVerificationBlockFailsClosed()
}//end class
