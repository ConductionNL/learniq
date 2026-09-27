<?php

/**
 * Learniq FeeItemVoluntaryEntitlementGuard unit tests.
 *
 * Covers the structural Wet vrijwillige ouderbijdrage guarantee: a voluntary
 * FeeItem's Entitlement must never be able to reach `active`, regardless of
 * the payment state shillinq reports, including a captured payment (a
 * guardian who does pay is recorded as having paid, but that fact must never
 * gate anything).
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
 * @spec openspec/specs/payments/spec.md#scenario-an-entitlement-referencing-a-voluntary-feeitem-can-never-activate
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Lifecycle\EntitlementPaymentSettledGuard;
use OCA\Learniq\Lifecycle\FeeItemVoluntaryEntitlementGuard;
use OCA\Learniq\Service\ContributionBeneficiaryResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the FeeItemVoluntaryEntitlementGuard (Entitlement pending -> active / grant).
 */
class FeeItemVoluntaryEntitlementGuardTest extends TestCase {

	use GuardVerdicts;
	/**
	 * Build a guard whose ObjectService::find() resolves the given FeeItem
	 * fixture, composing a real EntitlementPaymentSettledGuard whose reads of
	 * shillinq's register resolve the given PaymentRequest fixture.
	 *
	 * @param array<string,mixed>|null $feeItem FeeItem data, or null (not found).
	 * @param array<string,mixed>|null $paymentRequest Shillinq PaymentRequest data, or null.
	 *
	 * @return FeeItemVoluntaryEntitlementGuard
	 */
	private function makeGuard(?array $feeItem, ?array $paymentRequest = null): FeeItemVoluntaryEntitlementGuard {
		$objectService = $this->createMock(ObjectService::class);
		// OpenRegister's find() is find($id, $_extend, $files, $register, $schema, ...)
		// and returns ?ObjectEntity. willReturnCallback() hands the closure the
		// mock's arguments POSITIONALLY, so the closure must mirror that order.
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($feeItem, $paymentRequest) {
				if ($schema === 'fee-item' && $feeItem !== null) {
					return OrEntityFactory::make($feeItem, 'fee-item');
				}

				if ($register === 'shillinq' && $schema === 'PaymentRequest' && $paymentRequest !== null) {
					return OrEntityFactory::make($paymentRequest, 'PaymentRequest');
				}

				return null;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$settledGuard = new EntitlementPaymentSettledGuard(
			$objectService,
			$appManager,
			new ContributionBeneficiaryResolver($objectService),
			$this->createMock(LoggerInterface::class)
		);

		return new FeeItemVoluntaryEntitlementGuard($objectService, $settledGuard, $this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * A shillinq contribution request for fee-1 and leerling-001, settled or not.
	 *
	 * @param string $state The request state; `settled` sets settledAt.
	 *
	 * @return array<string,mixed>
	 */
	private function request(string $state): array {
		$request = [
			'id' => 'pr-1',
			'subjectKind' => 'object',
			'subject' => ['app' => 'learniq', 'type' => 'fee-item', 'register' => 'learniq', 'schema' => 'fee-item', 'id' => 'fee-1'],
			'beneficiary' => ['type' => 'learner', 'id' => 'leerling-001'],
			'requestType' => 'contribution',
			'state' => $state,
		];
		if ($state === 'settled') {
			$request['state'] = 'captured';
			$request['settledAt'] = '2026-10-02T09:15:00+00:00';
		}

		return $request;
	}//end request()

	/**
	 * A voluntary FeeItem's Entitlement can never activate, even when shillinq
	 * reports its payment captured.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payments/spec.md#scenario-an-entitlement-referencing-a-voluntary-feeitem-can-never-activate
	 */
	public function testVoluntaryFeeItemBlocksGrantRegardlessOfPaymentState(): void {
		foreach (['pending', 'authorized', 'captured', 'captured_unapplied', 'failed', 'voided', 'settled'] as $state) {
			$guard = $this->makeGuard(
				feeItem: ['id' => 'fee-1', 'voluntary' => true],
				paymentRequest: $this->request(state: $state)
			);
			$object = ['id' => 'ent-1', 'feeItemId' => 'fee-1', 'learnerId' => 'leerling-001', 'paymentRequestRef' => 'pr-1', 'lifecycle' => 'active'];

			self::assertDenied(
				$guard->check($object, 'grant', ''),
				"voluntary FeeItem must block grant even when the payment is '{$state}'"
			);
		}

	}//end testVoluntaryFeeItemBlocksGrantRegardlessOfPaymentState()

	/**
	 * A non-voluntary FeeItem is unaffected by this guard: the composed
	 * EntitlementPaymentSettledGuard allows once shillinq reports it settled.
	 *
	 * @return void
	 */
	public function testNonVoluntaryFeeItemAllowsGrantWhenPaymentSettled(): void {
		$guard = $this->makeGuard(
			feeItem: ['id' => 'fee-1', 'voluntary' => false],
			paymentRequest: $this->request(state: 'settled')
		);
		$object = ['id' => 'ent-1', 'feeItemId' => 'fee-1', 'learnerId' => 'leerling-001', 'paymentRequestRef' => 'pr-1', 'lifecycle' => 'active'];

		self::assertAllowed($guard->check($object, 'grant', ''));

	}//end testNonVoluntaryFeeItemAllowsGrantWhenPaymentSettled()

	/**
	 * A non-voluntary FeeItem still refuses while the payment is not settled, even when captured.
	 *
	 * @return void
	 */
	public function testNonVoluntaryFeeItemRefusesGrantWhenPaymentNotSettled(): void {
		$guard = $this->makeGuard(
			feeItem: ['id' => 'fee-1', 'voluntary' => false],
			paymentRequest: $this->request(state: 'captured')
		);
		$object = ['id' => 'ent-1', 'feeItemId' => 'fee-1', 'learnerId' => 'leerling-001', 'paymentRequestRef' => 'pr-1', 'lifecycle' => 'active'];

		self::assertDenied($guard->check($object, 'grant', ''));

	}//end testNonVoluntaryFeeItemRefusesGrantWhenPaymentNotSettled()

	/**
	 * A missing feeItemId fails closed.
	 *
	 * @return void
	 */
	public function testMissingFeeItemIdFailsClosed(): void {
		$guard = $this->makeGuard(feeItem: null);
		$object = ['id' => 'ent-1', 'lifecycle' => 'active'];

		self::assertDenied($guard->check($object, 'grant', ''));

	}//end testMissingFeeItemIdFailsClosed()

	/**
	 * An unresolvable FeeItem fails closed.
	 *
	 * @return void
	 */
	public function testUnresolvableFeeItemFailsClosed(): void {
		$guard = $this->makeGuard(feeItem: null);
		$object = ['id' => 'ent-1', 'feeItemId' => 'missing-fee', 'lifecycle' => 'active'];

		self::assertDenied($guard->check($object, 'grant', ''));

	}//end testUnresolvableFeeItemFailsClosed()
}//end class
