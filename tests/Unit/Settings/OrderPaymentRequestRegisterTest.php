<?php

/**
 * Unit tests for the `payment-request-ux` register delta.
 *
 * Asserts the two additive Order properties this change introduces:
 * paymentRequestSentAt/paymentRequestSentBy, both nullable and defaulted so
 * no existing Order is affected. The presentational OrderPaymentPanel.vue
 * change is verified by code review — no Vue component-test infrastructure
 * exists in this app (see design.md), a named gap, not claimed covered.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payment-request-ux/specs/payments/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the payment-request-ux register delta.
 */
class OrderPaymentRequestRegisterTest extends TestCase {

	/**
	 * Decoded register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Load the register configuration once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$path = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$raw = file_get_contents($path);
		$this->assertNotFalse($raw, 'learniq_register.json must be readable');

		$decoded = json_decode($raw, true);
		$this->assertIsArray($decoded, 'learniq_register.json must be valid JSON');
		$this->config = $decoded;

	}//end setUp()

	/**
	 * Order gains the two additive payment-request properties, both nullable
	 * and defaulted, and neither is required.
	 *
	 * @return void
	 * @spec   openspec/changes/payment-request-ux/specs/payments/spec.md#requirement-a-staff-recorded-payment-request-lets-a-payer-reach-checkout-in-one-tap
	 */
	public function testOrderGainsPaymentRequestProperties(): void {
		$schema = $this->config['components']['schemas']['Order'] ?? null;
		$this->assertIsArray($schema, 'Order schema MUST exist');

		$properties = $schema['properties'] ?? [];

		$sentAt = $properties['paymentRequestSentAt'] ?? null;
		$this->assertIsArray($sentAt, 'Order MUST declare paymentRequestSentAt');
		$this->assertSame('string', $sentAt['type'] ?? null);
		$this->assertSame('date-time', $sentAt['format'] ?? null);
		$this->assertTrue($sentAt['nullable'] ?? false);
		$this->assertArrayHasKey('default', $sentAt);
		$this->assertNull($sentAt['default']);

		$sentBy = $properties['paymentRequestSentBy'] ?? null;
		$this->assertIsArray($sentBy, 'Order MUST declare paymentRequestSentBy');
		$this->assertSame('string', $sentBy['type'] ?? null);
		$this->assertTrue($sentBy['nullable'] ?? false);
		$this->assertArrayHasKey('default', $sentBy);
		$this->assertNull($sentBy['default']);

		foreach (['paymentRequestSentAt', 'paymentRequestSentBy'] as $field) {
			$this->assertNotContains($field, $schema['required'] ?? [], "$field MUST NOT be required");
			$this->assertArrayHasKey('title', $properties[$field]);
			$this->assertArrayHasKey('description', $properties[$field]);
		}

	}//end testOrderGainsPaymentRequestProperties()
}//end class
