<?php

/**
 * Learniq BsaWarningSigningGuard unit tests.
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
 * @spec openspec/specs/study-progress/spec.md#requirement-the-formal-warning-captures-improvement-period-guidance-and-personal-circumstances-and-is-signed-evidence
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\TenantKeyService;
use OCA\Learniq\Lifecycle\BsaWarningSigningGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the BsaWarningSigningGuard lifecycle guard (drafted → issued).
 */
class BsaWarningSigningGuardTest extends TestCase {

	/**
	 * A valid draft warning fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function warningObject(): array {
		return [
			'learnerId' => 'learner-7',
			'programmeId' => 'programme-1',
			'academicYear' => '2026-2027',
			'warningDate' => '2026-01-15',
			'improvementPeriod' => [
				'startDate' => '2026-01-15',
				'endDate' => '2026-03-15',
			],
			'offeredGuidance' => 'Weekly study-advisor check-ins and a referral to the student dean.',
			'tenant_id' => 'tenant-a',
			'lifecycle' => 'issued',
		];

	}//end warningObject()

	/**
	 * Build a guard with mocked dependencies.
	 *
	 * @param TenantKeyService $tenantKeyService Tenant-key mock.
	 *
	 * @return BsaWarningSigningGuard
	 */
	private function makeGuard(TenantKeyService $tenantKeyService): BsaWarningSigningGuard {
		return new BsaWarningSigningGuard($tenantKeyService, $this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * OpenRegister's registry refuses a guard that does not implement its interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterGuardInterface(): void {
		self::assertInstanceOf(LifecycleGuardInterface::class, $this->makeGuard($this->createMock(TenantKeyService::class)));

	}//end testImplementsTheOpenRegisterGuardInterface()

	/**
	 * Happy path: valid improvementPeriod + offeredGuidance + tenant key present → allowed. The signature is
	 * TenantSignatureAction's write, see tests/Unit/Lifecycle/Action/TenantSignatureActionTest.php.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/study-progress/spec.md#scenario-issued-warning-carries-a-verifiable-signature
	 */
	public function testCompleteWarningMayBeIssued(): void {
		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->method('getCurrentTenantKey')->willReturn('super-secret-key');

		$object = $this->warningObject();

		self::assertTrue($this->makeGuard($tenantKeyService)->check($object, 'issue', 'advisor-1')->isAllowed());

	}//end testCompleteWarningMayBeIssued()

	/**
	 * Missing offeredGuidance blocks the issue transition.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/study-progress/spec.md#scenario-warning-cannot-be-issued-without-offered-guidance
	 */
	public function testMissingGuidanceBlocksIssue(): void {
		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->expects($this->never())->method('getCurrentTenantKey');

		$object = $this->warningObject();
		$object['offeredGuidance'] = '';

		self::assertFalse($this->makeGuard($tenantKeyService)->check($object, 'issue', 'advisor-1')->isAllowed());

	}//end testMissingGuidanceBlocksIssue()

	/**
	 * A whitespace-only offeredGuidance is treated as empty.
	 *
	 * @return void
	 */
	public function testWhitespaceOnlyGuidanceBlocksIssue(): void {
		$tenantKeyService = $this->createMock(TenantKeyService::class);

		$object = $this->warningObject();
		$object['offeredGuidance'] = "   \n\t  ";

		self::assertFalse($this->makeGuard($tenantKeyService)->check($object, 'issue', 'advisor-1')->isAllowed());

	}//end testWhitespaceOnlyGuidanceBlocksIssue()

	/**
	 * Missing improvementPeriod.startDate blocks the issue transition.
	 *
	 * @return void
	 */
	public function testMissingImprovementPeriodStartBlocksIssue(): void {
		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->expects($this->never())->method('getCurrentTenantKey');

		$object = $this->warningObject();
		$object['improvementPeriod']['startDate'] = null;

		self::assertFalse($this->makeGuard($tenantKeyService)->check($object, 'issue', 'advisor-1')->isAllowed());

	}//end testMissingImprovementPeriodStartBlocksIssue()

	/**
	 * A missing improvementPeriod object entirely blocks the issue transition.
	 *
	 * @return void
	 */
	public function testMissingImprovementPeriodBlocksIssue(): void {
		$tenantKeyService = $this->createMock(TenantKeyService::class);

		$object = $this->warningObject();
		unset($object['improvementPeriod']);

		self::assertFalse($this->makeGuard($tenantKeyService)->check($object, 'issue', 'advisor-1')->isAllowed());

	}//end testMissingImprovementPeriodBlocksIssue()

	/**
	 * Tenant key unavailable (empty string) → guard returns false even though
	 * pre-conditions are satisfied.
	 *
	 * @return void
	 */
	public function testUnavailableTenantKeyRejected(): void {
		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->method('getCurrentTenantKey')->willReturn('');

		$object = $this->warningObject();

		self::assertFalse($this->makeGuard($tenantKeyService)->check($object, 'issue', 'advisor-1')->isAllowed());

	}//end testUnavailableTenantKeyRejected()
}//end class
