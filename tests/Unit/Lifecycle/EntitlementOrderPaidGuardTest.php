<?php

/**
 * Learniq EntitlementOrderPaidGuard unit tests.
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
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-entitlement-activates-once-its-order-is-fully-paid
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-entitlement-cannot-activate-while-its-order-is-only-partially-paid
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Lifecycle\EntitlementOrderPaidGuard;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the EntitlementOrderPaidGuard (Entitlement pending -> active / grant).
 */
class EntitlementOrderPaidGuardTest extends TestCase {

	use GuardVerdicts;
	/**
	 * Build a guard whose ObjectService::find() resolves the given OrderLine/Order fixtures.
	 *
	 * @param array<string,mixed>|null $orderLine OrderLine data, or null (not found).
	 * @param array<string,mixed>|null $order Order data, or null (not found).
	 *
	 * @return EntitlementOrderPaidGuard
	 */
	private function makeGuard(?array $orderLine, ?array $order): EntitlementOrderPaidGuard {
		$objectService = $this->createMock(ObjectService::class);
		// OpenRegister's find() is find($id, $_extend, $files, $register, $schema, ...)
		// and returns ?ObjectEntity. willReturnCallback() hands the closure the
		// mock's arguments POSITIONALLY, so the closure must mirror that order.
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($orderLine, $order) {
				if ($schema === 'order-line' && $orderLine !== null) {
					return OrEntityFactory::make($orderLine, 'order-line');
				}

				if ($schema === 'order' && $order !== null) {
					return OrEntityFactory::make($order, 'order');
				}

				return null;
			}
		);

		return new EntitlementOrderPaidGuard($objectService, $this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * A paid Order allows the grant transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-entitlement-activates-once-its-order-is-fully-paid
	 */
	public function testPaidOrderAllowsGrant(): void {
		$guard = $this->makeGuard(
			orderLine: ['id' => 'line-1', 'orderId' => 'order-1'],
			order: ['id' => 'order-1', 'lifecycle' => 'paid']
		);
		$object = ['id' => 'ent-1', 'orderLineId' => 'line-1', 'lifecycle' => 'active'];

		self::assertAllowed($guard->check($object, 'grant', ''));

	}//end testPaidOrderAllowsGrant()

	/**
	 * A partially-paid/open/draft/cancelled/refunded Order refuses the grant transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-entitlement-cannot-activate-while-its-order-is-only-partially-paid
	 */
	public function testNonPaidOrderRefusesGrant(): void {
		foreach (['partially-paid', 'open', 'draft', 'cancelled', 'refunded'] as $state) {
			$guard = $this->makeGuard(
				orderLine: ['id' => 'line-1', 'orderId' => 'order-1'],
				order: ['id' => 'order-1', 'lifecycle' => $state]
			);
			$object = ['id' => 'ent-1', 'orderLineId' => 'line-1', 'lifecycle' => 'active'];

			self::assertDenied($guard->check($object, 'grant', ''), "state '{$state}' should refuse grant");
		}

	}//end testNonPaidOrderRefusesGrant()

	/**
	 * A missing orderLineId fails closed.
	 *
	 * @return void
	 */
	public function testMissingOrderLineIdFailsClosed(): void {
		$guard = $this->makeGuard(orderLine: null, order: null);
		$object = ['id' => 'ent-1', 'lifecycle' => 'active'];

		self::assertDenied($guard->check($object, 'grant', ''));

	}//end testMissingOrderLineIdFailsClosed()

	/**
	 * An unresolvable OrderLine fails closed.
	 *
	 * @return void
	 */
	public function testUnresolvableOrderLineFailsClosed(): void {
		$guard = $this->makeGuard(orderLine: null, order: null);
		$object = ['id' => 'ent-1', 'orderLineId' => 'missing-line', 'lifecycle' => 'active'];

		self::assertDenied($guard->check($object, 'grant', ''));

	}//end testUnresolvableOrderLineFailsClosed()

	/**
	 * An unresolvable Order fails closed.
	 *
	 * @return void
	 */
	public function testUnresolvableOrderFailsClosed(): void {
		$guard = $this->makeGuard(
			orderLine: ['id' => 'line-1', 'orderId' => 'missing-order'],
			order: null
		);
		$object = ['id' => 'ent-1', 'orderLineId' => 'line-1', 'lifecycle' => 'active'];

		self::assertDenied($guard->check($object, 'grant', ''));

	}//end testUnresolvableOrderFailsClosed()
}//end class
