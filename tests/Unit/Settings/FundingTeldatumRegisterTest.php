<?php

/**
 * Unit tests for the `funding-and-teldatum-checks` register delta.
 *
 * Asserts the five additive DataExchangeJob teldatum-check properties and
 * the LearnerProfile.fundingWeightCode property this change introduces.
 * DataExchangeRunGuard's own behaviour is covered separately by
 * DataExchangeRunGuardTest.
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
 * @spec openspec/specs/data-exchange/spec.md
 * @spec openspec/specs/enrolment/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the funding-and-teldatum-checks register delta.
 */
class FundingTeldatumRegisterTest extends TestCase {

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
	 * The teldatum check left DataExchangeJob (data-exchange-to-integriq): a
	 * TeldatumCheck records the confirmed count per teldatum and target, with a
	 * stamped confirm transition, and the exchange gate reads it.
	 *
	 * @return void
	 * @spec   openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-the-gate-enforces-partner-approval-teldatum-confirmation-and-flag-handling
	 */
	public function testTeldatumCheckHoldsTheConfirmedCount(): void {
		$schema = $this->config['components']['schemas']['TeldatumCheck'] ?? null;
		$this->assertIsArray($schema, 'TeldatumCheck schema MUST exist');
		$this->assertArrayNotHasKey('DataExchangeJob', $this->config['components']['schemas']);

		$properties = $schema['properties'];
		$this->assertSame(['teldatumDate'], $schema['required']);
		$this->assertSame('date', $properties['teldatumDate']['format']);
		$this->assertSame('bron-rod', $properties['target']['default']);
		$this->assertSame(['pending', 'confirmed'], $properties['status']['enum']);
		$this->assertSame('pending', $properties['status']['default']);

		$confirm = $schema['x-openregister-lifecycle']['transitions']['confirm'];
		$this->assertSame('pending', $confirm['from']);
		$this->assertSame('confirmed', $confirm['to']);
		$this->assertSame(['actorField' => 'confirmedBy', 'timeField' => 'confirmedAt'], $confirm['actions'][0]['actionParameters']);
		$this->assertNotContains('guardians', $schema['authorization']['read']);

	}//end testTeldatumCheckHoldsTheConfirmedCount()

	/**
	 * LearnerProfile gains fundingWeightCode: a nullable enum, default null,
	 * not required.
	 *
	 * @return void
	 * @spec   openspec/specs/enrolment/spec.md#requirement-learnerprofile-records-the-noatcuminnca-funding-weight-classification
	 */
	public function testLearnerProfileGainsFundingWeightCode(): void {
		$schema = $this->config['components']['schemas']['LearnerProfile'] ?? null;
		$this->assertIsArray($schema, 'LearnerProfile schema MUST exist');

		$property = $schema['properties']['fundingWeightCode'] ?? null;
		$this->assertIsArray($property, 'LearnerProfile MUST declare fundingWeightCode');
		$this->assertEqualsCanonicalizing(['noat', 'cumi', 'nnca'], $property['enum'] ?? []);
		$this->assertTrue($property['nullable'] ?? false);
		$this->assertArrayHasKey('default', $property);
		$this->assertNull($property['default']);
		$this->assertNotContains('fundingWeightCode', $schema['required'] ?? []);
		$this->assertArrayHasKey('title', $property);
		$this->assertArrayHasKey('description', $property);

	}//end testLearnerProfileGainsFundingWeightCode()
}//end class
