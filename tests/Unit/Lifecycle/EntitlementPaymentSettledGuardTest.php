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
	 * Every find() call: id, register, schema, rbac.
	 *
	 * @var array<int, array{id: string, register: mixed, schema: mixed, rbac: bool}>
	 */
	private array $finds = [];

	/**
	 * Build the guard.
	 *
	 * @param array<string,mixed>|null $request     The PaymentRequest shillinq holds, or null.
	 * @param bool                     $installed   Whether shillinq is installed.
	 * @param bool                     $readFails   Whether reading shillinq throws.
	 *
	 * @return EntitlementPaymentSettledGuard
	 */
	private function makeGuard(?array $request, bool $installed = true, bool $readFails = false): EntitlementPaymentSettledGuard {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true) use ($request, $readFails) {
				$this->finds[] = ['id' => (string)$id, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac];
				if ($readFails === true) {
					throw new RuntimeException('shillinq register missing');
				}

				if ($request === null || $register !== 'shillinq' || $schema !== 'PaymentRequest' || $id !== ($request['id'] ?? null)) {
					return null;
				}

				return OrEntityFactory::make($request, 'PaymentRequest');
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $appId): bool => $installed === true && $appId === 'shillinq'
		);

		return new EntitlementPaymentSettledGuard($objectService, $appManager, new NullLogger());
	}//end makeGuard()

	/**
	 * A PaymentRequest on an object.
	 *
	 * @param string $state        Request state.
	 * @param string $entitlement  The Entitlement id its subject names.
	 * @param string $schema       The subject schema.
	 *
	 * @return array<string,mixed>
	 */
	private function request(string $state = 'captured', string $entitlement = 'ent-1', string $schema = 'entitlement'): array {
		return [
			'id' => 'pr-1',
			'subjectKind' => 'object',
			'subject' => ['type' => 'entitlement', 'register' => 'learniq', 'schema' => $schema, 'id' => $entitlement],
			'requestType' => 'other',
			'paymentGateway' => 'mollie',
			'amount' => 45.0,
			'currency' => 'EUR',
			'state' => $state,
		];
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
	 * A captured request on this Entitlement allows the grant, read without RBAC from shillinq's register.
	 *
	 * @return void
	 */
	public function testACapturedRequestOnThisEntitlementAllowsTheGrant(): void {
		self::assertAllowed($this->makeGuard(request: $this->request())->check($this->entitlement(), 'grant', 'admin'));
		self::assertSame(['id' => 'pr-1', 'register' => 'shillinq', 'schema' => 'PaymentRequest', 'rbac' => false], $this->finds[0]);
	}//end testACapturedRequestOnThisEntitlementAllowsTheGrant()

	/**
	 * Every state other than captured refuses, including captured_unapplied.
	 *
	 * @return void
	 */
	public function testEveryOtherStateRefuses(): void {
		foreach (['pending', 'authorized', 'captured_unapplied', 'failed', 'expired', 'voided'] as $state) {
			self::assertDenied(
				$this->makeGuard(request: $this->request(state: $state))->check($this->entitlement(), 'grant', 'admin'),
				$state
			);
		}
	}//end testEveryOtherStateRefuses()

	/**
	 * A captured request on another object refuses.
	 *
	 * @return void
	 */
	public function testACapturedRequestOnAnotherObjectRefuses(): void {
		self::assertDenied($this->makeGuard(request: $this->request(entitlement: 'ent-9'))->check($this->entitlement(), 'grant', 'admin'));
		self::assertDenied($this->makeGuard(request: $this->request(schema: 'fee-item'))->check($this->entitlement(), 'grant', 'admin'));
	}//end testACapturedRequestOnAnotherObjectRefuses()

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

	/**
	 * The subject helper recognises only an object request on a learniq Entitlement.
	 *
	 * @return void
	 */
	public function testTheSubjectHelperReadsOnlyLearniqEntitlements(): void {
		$guard = $this->makeGuard(request: null);
		self::assertSame('ent-1', $guard->subjectEntitlementId(request: $this->request()));
		self::assertNull($guard->subjectEntitlementId(request: ['subjectKind' => 'invoice', 'invoiceReference' => 'inv-1']));
		self::assertNull($guard->subjectEntitlementId(request: $this->request(schema: 'Zaak')));
		self::assertTrue($guard->isSettledRequestFor(request: $this->request(), entitlementId: ''));
	}//end testTheSubjectHelperReadsOnlyLearniqEntitlements()
}//end class
