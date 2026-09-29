<?php

/**
 * Learniq BsaDecisionGuard unit tests.
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
 * @spec openspec/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TenantKeyService;
use OCA\Learniq\Lifecycle\BsaDecisionGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the BsaDecisionGuard lifecycle guard (drafted → decided).
 */
class BsaDecisionGuardTest extends TestCase {

	/**
	 * Base decision fixture. Override decisionType/rationale per test.
	 *
	 * @param string $decisionType Decision type.
	 * @param string $rationale Rationale text.
	 *
	 * @return array<string,mixed>
	 */
	private function decisionObject(string $decisionType, string $rationale = ''): array {
		return [
			'learnerId' => 'learner-7',
			'programmeId' => 'programme-1',
			'academicYear' => '2026-2027',
			'decisionType' => $decisionType,
			'rationale' => $rationale,
			'decidedBy' => 'advisor-1',
			'decisionDate' => '2026-07-01T10:00:00+02:00',
			'tenant_id' => 'tenant-a',
			'lifecycle' => 'decided',
		];

	}//end decisionObject()

	/**
	 * Build a guard with mocked dependencies.
	 *
	 * @param ObjectService $objectService Object query mock.
	 * @param TenantKeyService $tenantKeyService Tenant-key mock.
	 *
	 * @return BsaDecisionGuard
	 */
	private function makeGuard(ObjectService $objectService, TenantKeyService $tenantKeyService): BsaDecisionGuard {
		return new BsaDecisionGuard($objectService, $tenantKeyService, $this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * OpenRegister's registry refuses a guard that does not implement its interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterGuardInterface(): void {
		$guard = $this->makeGuard($this->createMock(ObjectService::class), $this->createMock(TenantKeyService::class));

		self::assertInstanceOf(LifecycleGuardInterface::class, $guard);

	}//end testImplementsTheOpenRegisterGuardInterface()

	/**
	 * A negative decision without any issued BsaWarning is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/study-progress/spec.md#scenario-negative-decision-without-a-warning-is-refused
	 */
	public function testNegativeWithoutWarningRefused(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->expects($this->never())->method('getCurrentTenantKey');

		$object = $this->decisionObject('negative', 'Insufficient progress despite guidance.');

		self::assertFalse($this->makeGuard($objectService, $tenantKeyService)->check($object, 'decide', 'advisor-1')->isAllowed());

	}//end testNegativeWithoutWarningRefused()

	/**
	 * A negative decision with a matching issued BsaWarning is allowed. The signature is TenantSignatureAction's write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/study-progress/spec.md#scenario-negative-decision-with-a-logged-warning-is-allowed
	 */
	public function testNegativeWithIssuedWarningAllowed(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([['id' => 'warning-1', 'lifecycle' => 'issued']]);

		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->method('getCurrentTenantKey')->willReturn('super-secret-key');

		$object = $this->decisionObject('negative', 'Insufficient progress despite guidance.');

		self::assertTrue($this->makeGuard($objectService, $tenantKeyService)->check($object, 'decide', 'advisor-1')->isAllowed());

	}//end testNegativeWithIssuedWarningAllowed()

	/**
	 * negative-with-recommendation is subject to the same warning requirement as negative.
	 *
	 * @return void
	 */
	public function testNegativeWithRecommendationWithoutWarningRefused(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		$tenantKeyService = $this->createMock(TenantKeyService::class);

		$object = $this->decisionObject('negative-with-recommendation', 'Some progress but below norm.');

		self::assertFalse($this->makeGuard($objectService, $tenantKeyService)->check($object, 'decide', 'advisor-1')->isAllowed());

	}//end testNegativeWithRecommendationWithoutWarningRefused()

	/**
	 * A negative decision with an issued warning but empty rationale is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/study-progress/spec.md#scenario-negative-decision-without-rationale-is-refused
	 */
	public function testNegativeWithoutRationaleRefused(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([['id' => 'warning-1', 'lifecycle' => 'issued']]);

		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->expects($this->never())->method('getCurrentTenantKey');

		$object = $this->decisionObject('negative-with-recommendation', '');

		self::assertFalse($this->makeGuard($objectService, $tenantKeyService)->check($object, 'decide', 'advisor-1')->isAllowed());

	}//end testNegativeWithoutRationaleRefused()

	/**
	 * A positive decision is unaffected by the warning check (no BsaWarning query needed).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
	 */
	public function testPositiveDecisionUnaffectedByWarningCheck(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('findAll');

		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->method('getCurrentTenantKey')->willReturn('super-secret-key');

		$object = $this->decisionObject('positive');

		self::assertTrue($this->makeGuard($objectService, $tenantKeyService)->check($object, 'decide', 'advisor-1')->isAllowed());

	}//end testPositiveDecisionUnaffectedByWarningCheck()

	/**
	 * A postponed decision is unaffected by the warning check.
	 *
	 * @return void
	 */
	public function testPostponedDecisionUnaffectedByWarningCheck(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('findAll');

		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->method('getCurrentTenantKey')->willReturn('super-secret-key');

		$object = $this->decisionObject('postponed');

		self::assertTrue($this->makeGuard($objectService, $tenantKeyService)->check($object, 'decide', 'advisor-1')->isAllowed());

	}//end testPostponedDecisionUnaffectedByWarningCheck()

	/**
	 * Tenant key unavailable blocks even an otherwise-valid negative decision.
	 *
	 * @return void
	 */
	public function testUnavailableTenantKeyRejected(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([['id' => 'warning-1', 'lifecycle' => 'issued']]);

		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->method('getCurrentTenantKey')->willReturn('');

		$object = $this->decisionObject('negative', 'Rationale present.');

		self::assertFalse($this->makeGuard($objectService, $tenantKeyService)->check($object, 'decide', 'advisor-1')->isAllowed());

	}//end testUnavailableTenantKeyRejected()
}//end class
