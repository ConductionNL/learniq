<?php

/**
 * Learniq AttestationSigningGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\AttestationSigningGuard;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TenantKeyService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the AttestationSigningGuard lifecycle guard (drafted → signed).
 *
 * Fixtures are the object as OpenRegister's LifecycleValidationListener hands it
 * to the guard: the flat object data with the lifecycle field at its target.
 * The signature is written by TenantSignatureAction (learniq#983), see
 * tests/Unit/Lifecycle/Action/TenantSignatureActionTest.php.
 */
class AttestationSigningGuardTest extends TestCase {
	/**
	 * The attestation as the guard sees it on the sign transition.
	 *
	 * @return array<string,mixed>
	 */
	private function attestationObject(): array {
		return [
			'id' => 'attestation-1',
			'learnerId' => 'learner-7',
			'lessonId' => 'lesson-3',
			'courseId' => 'course-1',
			'regulationSlug' => 'NIS2',
			'score' => 88,
			'tenant_id' => 'tenant-a',
			'lifecycle' => 'signed',
		];
	}//end attestationObject()

	/**
	 * Build a guard with mocked dependencies.
	 *
	 * @param ObjectService $objectService Object query mock.
	 * @param TenantKeyService $tenantKeyService Tenant-key mock.
	 *
	 * @return AttestationSigningGuard
	 */
	private function makeGuard(ObjectService $objectService, TenantKeyService $tenantKeyService): AttestationSigningGuard {
		return new AttestationSigningGuard($objectService, $tenantKeyService, $this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * OpenRegister's registry refuses a guard that does not implement its interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterGuardInterface(): void {
		$guard = $this->makeGuard($this->createMock(ObjectService::class), $this->createMock(TenantKeyService::class));

		$this->assertInstanceOf(LifecycleGuardInterface::class, $guard);
	}//end testImplementsTheOpenRegisterGuardInterface()

	/**
	 * Happy path: completion exists and a tenant key is available.
	 *
	 * @return void
	 */
	public function testCompletionAndKeyAllowSigning(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([['uuid' => 'xapi-1']]);

		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->method('getCurrentTenantKey')->willReturn('super-secret-key');

		$result = $this->makeGuard($objectService, $tenantKeyService)->check($this->attestationObject(), 'sign', 'learner-7');

		$this->assertTrue($result->isAllowed());
	}//end testCompletionAndKeyAllowSigning()

	/**
	 * No matching xAPI completion: denied, and the key is never fetched.
	 *
	 * @return void
	 */
	public function testMissingCompletionIsDenied(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->expects($this->never())->method('getCurrentTenantKey');

		$result = $this->makeGuard($objectService, $tenantKeyService)->check($this->attestationObject(), 'sign', 'learner-7');

		$this->assertFalse($result->isAllowed());
		$this->assertNotSame('', (string)$result->getMessage());
	}//end testMissingCompletionIsDenied()

	/**
	 * The completion lookup is scoped to the learner, the lesson and the tenant.
	 *
	 * @return void
	 */
	public function testCompletionLookupIsTenantScoped(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->atLeastOnce())
			->method('findAll')
			->with(
				$this->callback(
					static fn (array $config): bool => ($config['filters']['actor.id'] ?? null) === 'learner-7'
						&& ($config['filters']['object.id'] ?? null) === 'lesson-3'
						&& ($config['filters']['tenant_id'] ?? null) === 'tenant-a'
				)
			)
			->willReturn([['uuid' => 'xapi-1']]);

		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->method('getCurrentTenantKey')->willReturn('k');

		$this->makeGuard($objectService, $tenantKeyService)->check($this->attestationObject(), 'sign', 'learner-7');
	}//end testCompletionLookupIsTenantScoped()

	/**
	 * Tenant key unavailable: denied even though the completion exists.
	 *
	 * @return void
	 */
	public function testUnavailableTenantKeyIsDenied(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([['uuid' => 'xapi-1']]);

		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->method('getCurrentTenantKey')->willReturn('');

		$result = $this->makeGuard($objectService, $tenantKeyService)->check($this->attestationObject(), 'sign', 'learner-7');

		$this->assertFalse($result->isAllowed());
	}//end testUnavailableTenantKeyIsDenied()

	/**
	 * Missing learnerId or lessonId: denied without querying.
	 *
	 * @return void
	 */
	public function testMissingIdentifiersAreDenied(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('findAll');

		$object = ['id' => 'attestation-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'signed'];

		$result = $this->makeGuard($objectService, $this->createMock(TenantKeyService::class))->check($object, 'sign', 'learner-7');

		$this->assertFalse($result->isAllowed());
	}//end testMissingIdentifiersAreDenied()
}//end class
