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
 * @spec openspec/changes/funding-and-teldatum-checks/specs/data-exchange/spec.md
 * @spec openspec/changes/funding-and-teldatum-checks/specs/enrolment/spec.md
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
	 * DataExchangeJob gains the five additive teldatum-check properties,
	 * each with a backward-compatible default.
	 *
	 * @return void
	 * @spec   openspec/changes/funding-and-teldatum-checks/specs/data-exchange/spec.md#requirement-a-dataexchangejob-target-can-require-a-confirmed-teldatum-pre-flight-check-before-it-runs
	 */
	public function testDataExchangeJobGainsTeldatumCheckProperties(): void {
		$schema = $this->config['components']['schemas']['DataExchangeJob'] ?? null;
		$this->assertIsArray($schema, 'DataExchangeJob schema MUST exist');

		$properties = $schema['properties'] ?? [];
		$this->assertFalse($properties['requiresTeldatumCheck']['default'] ?? null, 'requiresTeldatumCheck MUST default to false');
		$this->assertSame('not-required', $properties['teldatumCheckStatus']['default'] ?? null);
		$this->assertEqualsCanonicalizing(
			['not-required', 'pending', 'confirmed'],
			$properties['teldatumCheckStatus']['enum'] ?? []
		);

		foreach (['teldatumCheckDate', 'teldatumCheckedBy', 'teldatumCheckedAt'] as $field) {
			$this->assertArrayHasKey('default', $properties[$field] ?? [], "$field MUST declare a default");
			$this->assertNull($properties[$field]['default'], "$field MUST default to null");
			$this->assertTrue($properties[$field]['nullable'] ?? false, "$field MUST be nullable");
		}

		foreach (
			[
				'requiresTeldatumCheck',
				'teldatumCheckStatus',
				'teldatumCheckDate',
				'teldatumCheckedBy',
				'teldatumCheckedAt',
			] as $field
		) {
			$this->assertNotContains($field, $schema['required'] ?? [], "$field MUST NOT be required");
			$this->assertArrayHasKey('title', $properties[$field]);
			$this->assertArrayHasKey('description', $properties[$field]);
		}

	}//end testDataExchangeJobGainsTeldatumCheckProperties()

	/**
	 * LearnerProfile gains fundingWeightCode: a nullable enum, default null,
	 * not required.
	 *
	 * @return void
	 * @spec   openspec/changes/funding-and-teldatum-checks/specs/enrolment/spec.md#requirement-learnerprofile-records-the-noatcuminnca-funding-weight-classification
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
