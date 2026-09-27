<?php

/**
 * Register and manifest tests for payments-to-shillinq-migration (D19).
 *
 * Learniq keeps FeeItem and Entitlement and retires Order, OrderLine and
 * PaymentTransaction with their pages, menu group, controller and routes.
 * These tests keep the retired surface from coming back.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-learniq-keeps-feeitem-and-entitlement-and-no-pay-screen-of-its-own
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the reduced payments surface.
 */
class PaymentsToShillinqRegisterTest extends TestCase {

	private const RETIRED = ['Order' => 'order', 'OrderLine' => 'order-line', 'PaymentTransaction' => 'payment-transaction'];

	/**
	 * A JSON file from the repository.
	 *
	 * @param string $path Relative path.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $path): array {
		return json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/' . $path), true);
	}//end json()

	/**
	 * The three retired schemas are gone from the register and its mock copy.
	 *
	 * @return void
	 */
	public function testTheRetiredSchemasAreGone(): void {
		foreach (['lib/Settings/learniq_register.json', 'lib/Settings/learniq_mock_register.json'] as $file) {
			$register = self::json($file);
			foreach (self::RETIRED as $name => $slug) {
				self::assertArrayNotHasKey($name, $register['components']['schemas'], "$file $name");
				foreach (($register['components']['registers'] ?? []) as $declared) {
					self::assertNotContains($slug, ($declared['schemas'] ?? []), "$file register list $slug");
				}

				foreach (($register['components']['objects'] ?? []) as $object) {
					self::assertNotSame($slug, ($object['@self']['schema'] ?? null), "$file seed $slug");
				}
			}
		}

		$register = self::json('lib/Settings/learniq_register.json');
		self::assertTrue(version_compare((string)$register['info']['version'], '0.26.0', '>='));
		self::assertArrayHasKey('FeeItem', $register['components']['schemas']);
	}//end testTheRetiredSchemasAreGone()

	/**
	 * Entitlement no longer points at an OrderLine; it names the shillinq request that paid it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
	 */
	public function testEntitlementNamesTheShillinqPaymentRequest(): void {
		$entitlement = self::json('lib/Settings/learniq_register.json')['components']['schemas']['Entitlement'];

		self::assertArrayNotHasKey('orderLineId', $entitlement['properties']);
		self::assertNotContains('orderLineId', $entitlement['required']);
		self::assertTrue($entitlement['properties']['paymentRequestRef']['nullable']);
		self::assertArrayNotHasKey('$ref', $entitlement['properties']['paymentRequestRef'], 'shillinq is duck-typed: no cross-app $ref');
		self::assertSame('date-time', $entitlement['properties']['paymentSettledAt']['format']);
		self::assertTrue($entitlement['properties']['paymentSettledVia']['nullable']);
		self::assertArrayNotHasKey('enum', $entitlement['properties']['paymentSettledVia'], 'the contract may add settledVia values');
		self::assertSame(
			'OCA\\Learniq\\Lifecycle\\FeeItemVoluntaryEntitlementGuard',
			$entitlement['x-openregister-lifecycle']['transitions']['grant']['requires']
		);
		self::assertTrue(version_compare((string)$entitlement['version'], '0.2.0', '>='));
	}//end testEntitlementNamesTheShillinqPaymentRequest()

	/**
	 * Only the FeeItem and Entitlement pages remain, and no Payments group.
	 *
	 * @return void
	 */
	public function testOnlyFeeItemAndEntitlementPagesRemain(): void {
		$payments = self::json('src/manifest.d/payments.json');
		$ids = array_column($payments['pages'], 'id');
		sort($ids);
		self::assertSame(['EntitlementDetail', 'Entitlements', 'FeeItemDetail', 'FeeItems'], $ids);

		foreach (glob(dirname(__DIR__, 3) . '/src/manifest.d/*.json') as $fragment) {
			$manifest = json_decode((string)file_get_contents($fragment), true);
			foreach (($manifest['menu'] ?? []) as $entry) {
				self::assertNotSame('GroupPayments', $entry['id'], basename($fragment));
			}
		}

		$layout = self::json('src/menu-layout.json');
		self::assertSame('GroupPeople', $layout['relocations']['FeeItemsMenu']);
		self::assertSame('GroupPeople', $layout['relocations']['EntitlementsMenu']);
	}//end testOnlyFeeItemAndEntitlementPagesRemain()

	/**
	 * An active fee offers the raise, which posts to the routed endpoint.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
	 */
	public function testAnActiveFeeOffersTheRaise(): void {
		$payments = self::json('src/manifest.d/payments.json');
		$detail = array_values(array_filter($payments['pages'], static fn (array $p): bool => $p['id'] === 'FeeItemDetail'))[0];
		$action = $detail['config']['headerActions'][0];

		self::assertSame('api-call', $action['type']);
		self::assertSame('/apps/learniq/api/fee-items/@objectId/contributions', $action['url']);
		self::assertTrue($action['confirm']);
		self::assertSame(['field' => 'lifecycle', 'op' => 'eq', 'value' => 'active'], $action['visibleWhen']);

		$routes = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		self::assertStringContainsString("['name' => 'contribution#raise', 'url' => '/api/fee-items/{id}/contributions', 'verb' => 'POST']", $routes);
		self::assertContains('admin', self::json('lib/actions.seed.json')['actions']['fee-item.raise-contributions']);
	}//end testAnActiveFeeOffersTheRaise()

	/**
	 * No pay screen, no payment routes.
	 *
	 * @return void
	 */
	public function testThereIsNoPayScreenOrPaymentRoute(): void {
		$root = dirname(__DIR__, 3);
		self::assertFileDoesNotExist($root . '/src/views/OrderPaymentPanel.vue');
		self::assertStringNotContainsString('OrderPaymentPanel', (string)file_get_contents($root . '/src/registry.js'));
		self::assertStringNotContainsString("'/api/payments", (string)file_get_contents($root . '/appinfo/routes.php'));
		self::assertFileDoesNotExist($root . '/lib/Controller/PaymentTransactionController.php');
	}//end testThereIsNoPayScreenOrPaymentRoute()
}//end class
