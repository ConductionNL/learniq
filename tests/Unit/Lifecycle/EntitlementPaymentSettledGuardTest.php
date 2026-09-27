<?php

/**
 * Tests for EntitlementPaymentSettledGuard (D19, payments-to-shillinq-migration).
 *
 * @category Test
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
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\EntitlementPaymentSettledGuard;
use OCA\Learniq\Service\ContributionBeneficiaryResolver;
use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for EntitlementPaymentSettledGuard::check().
 */
class EntitlementPaymentSettledGuardTest extends TestCase {
	use GuardVerdicts;

	/**
	 * Every find() on shillinq's register: id, register, schema, rbac.
	 *
	 * @var array<int, array{id: string, register: mixed, schema: mixed, rbac: bool}>
	 */
	private array $finds = [];

	/**
	 * Build the guard.
	 *
	 * @param array<string,mixed>|null $request   The PaymentRequest shillinq holds, or null.
	 * @param bool                     $installed Whether shillinq is installed.
	 * @param bool                     $readFails Whether reading shillinq throws.
	 *
	 * @return EntitlementPaymentSettledGuard
	 */
	private function makeGuard(?array $request, bool $installed = true, bool $readFails = false): EntitlementPaymentSettledGuard {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true) use ($request, $readFails) {
				if ($schema === 'learner-profile') {
					return ($id === 'lp-1') ? OrEntityFactory::make(['id' => 'lp-1', 'ncUserId' => 'leerling-001'], 'learner-profile') : null;
				}

				$this->finds[] = ['id' => (string)$id, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac];
				if ($readFails === true) {
					throw new RuntimeException('shillinq register missing');
				}

				if ($request === null || $register !== 'shillinq' || $schema !== 'PaymentRequest' || $id !== ($request['id'] ?? null)) {
					return null;
				}

				return OrEntityFactory::make($request, 'PaymentRequest', 'shillinq');
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $appId): bool => $installed === true && $appId === 'shillinq'
		);

		return new EntitlementPaymentSettledGuard($objectService, $appManager, new ContributionBeneficiaryResolver($objectService), new NullLogger());
	}//end makeGuard()

	/**
	 * A contribution request for fee-1 and leerling-001 (profile lp-1).
	 *
	 * @param array<string,mixed> $override Fields to override.
	 *
	 * @return array<string,mixed>
	 */
	private function request(array $override = []): array {
		return array_merge(
			[
				'id' => 'pr-1',
				'subjectKind' => 'object',
				'subject' => ['app' => 'learniq', 'type' => 'fee-item', 'register' => 'learniq', 'schema' => 'fee-item', 'id' => 'fee-1'],
				'beneficiary' => ['type' => 'learner', 'register' => 'learniq', 'schema' => 'learner-profile', 'id' => 'lp-1'],
				'requestType' => 'contribution',
				'state' => 'captured',
				'settledAt' => '2026-10-02T09:15:00+00:00',
				'settledVia' => 'provider',
			],
			$override
		);
	}//end request()

	/**
	 * The Entitlement being granted.
	 *
	 * @param string|null $ref Its paymentRequestRef.
	 *
	 * @return array<string,mixed>
	 */
	private function entitlement(?string $ref = 'pr-1'): array {
		return ['id' => 'ent-1', 'feeItemId' => 'fee-1', 'learnerId' => 'leerling-001', 'paymentRequestRef' => $ref, 'lifecycle' => 'active'];
	}//end entitlement()

	/**
	 * A settled request for this fee and learner allows the grant, read without RBAC from shillinq.
	 *
	 * @return void
	 */
	public function testASettledRequestForThisFeeAndLearnerAllowsTheGrant(): void {
		self::assertAllowed($this->makeGuard(request: $this->request())->check($this->entitlement(), 'grant', 'admin'));
		self::assertSame(['id' => 'pr-1', 'register' => 'shillinq', 'schema' => 'PaymentRequest', 'rbac' => false], $this->finds[0]);
	}//end testASettledRequestForThisFeeAndLearnerAllowsTheGrant()

	/**
	 * Without settledAt nothing counts, whatever the state says.
	 *
	 * @return void
	 */
	public function testNoSettledAtRefuses(): void {
		foreach (['pending', 'authorized', 'captured', 'captured_unapplied'] as $state) {
			self::assertDenied(
				$this->makeGuard(request: $this->request(['state' => $state, 'settledAt' => null]))->check($this->entitlement(), 'grant', 'admin'),
				$state
			);
		}
	}//end testNoSettledAtRefuses()

	/**
	 * A settled request for another fee, another learner or another app refuses.
	 *
	 * @return void
	 */
	public function testASettledRequestForSomethingElseRefuses(): void {
		$others = [
			['subject' => ['app' => 'learniq', 'register' => 'learniq', 'schema' => 'fee-item', 'id' => 'fee-9']],
			['subject' => ['app' => 'portaliq', 'register' => 'portaliq', 'schema' => 'fee-item', 'id' => 'fee-1']],
			['beneficiary' => ['type' => 'learner', 'id' => 'leerling-009']],
			['beneficiary' => null],
		];
		foreach ($others as $override) {
			self::assertDenied($this->makeGuard(request: $this->request($override))->check($this->entitlement(), 'grant', 'admin'));
		}

		self::assertAllowed($this->makeGuard(request: $this->request(['beneficiary' => ['type' => 'learner', 'id' => 'leerling-001']]))->check($this->entitlement(), 'grant', 'admin'));
	}//end testASettledRequestForSomethingElseRefuses()

	/**
	 * Without shillinq installed the guard fails closed and reads nothing.
	 *
	 * @return void
	 */
	public function testWithoutShillinqTheGuardFailsClosed(): void {
		self::assertDenied($this->makeGuard(request: $this->request(), installed: false)->check($this->entitlement(), 'grant', 'admin'));
		self::assertSame([], $this->finds);
	}//end testWithoutShillinqTheGuardFailsClosed()

	/**
	 * No reference, an unknown request or a read error refuse.
	 *
	 * @return void
	 */
	public function testMissingOrUnreadableRequestsRefuse(): void {
		self::assertDenied($this->makeGuard(request: $this->request())->check($this->entitlement(ref: null), 'grant', 'admin'));
		self::assertDenied($this->makeGuard(request: $this->request())->check($this->entitlement(ref: 'pr-unknown'), 'grant', 'admin'));
		self::assertDenied($this->makeGuard(request: $this->request(), readFails: true)->check($this->entitlement(), 'grant', 'admin'));
	}//end testMissingOrUnreadableRequestsRefuse()
}//end class
