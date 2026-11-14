<?php

/**
 * Learniq Regulation register test.
 *
 * A compliance rule is a Regulation: its slug, name, audience, its own red and
 * amber limits, its renewal cycle, and a draft, published, archived lifecycle.
 * This pins the shipped schema to what compliance-rule-coverage says a rule holds.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/compliance-rule-coverage/spec.md#requirement-a-compliance-officer-registers-the-rules-that-need-mandatory-training
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the Regulation schema's properties, defaults and lifecycle.
 */
class RegulationSettingsRegisterTest extends TestCase {

	/**
	 * The Regulation schema as shipped.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);

		return $register['components']['schemas']['Regulation'];
	}//end schema()

	/**
	 * A rule declares its slug, name and audience, and requires all three.
	 *
	 * @return void
	 */
	public function testARuleHasASlugANameAndAnAudience(): void {
		$schema = $this->schema();

		self::assertSame('^[A-Z0-9_-]+$', $schema['properties']['slug']['pattern']);
		self::assertSame('string', $schema['properties']['name']['type']);
		self::assertSame(
			['all-employees', 'board', 'role-specific', 'department'],
			$schema['properties']['audienceScope']['enum']
		);
		self::assertSame([], array_diff(['slug', 'name', 'audienceScope'], $schema['required']));
	}//end testARuleHasASlugANameAndAnAudience()

	/**
	 * The rule's own limits and its renewal cycle carry the defaults the spec names.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-rule-coverage/spec.md#requirement-each-rule-has-its-own-red-and-amber-limits
	 */
	public function testTheLimitsAndTheCycleHaveTheirDefaults(): void {
		$properties = $this->schema()['properties'];

		self::assertSame(70, $properties['ragRedThreshold']['default']);
		self::assertSame(90, $properties['ragAmberThreshold']['default']);
		self::assertSame('integer', $properties['renewalCycleMonths']['type']);
		self::assertSame(12, $properties['renewalCycleMonths']['default']);
	}//end testTheLimitsAndTheCycleHaveTheirDefaults()

	/**
	 * A rule starts as a draft, is published, and is archived from published.
	 *
	 * @return void
	 */
	public function testARuleIsDraftedPublishedAndArchived(): void {
		$lifecycle = $this->schema()['x-openregister-lifecycle'];

		self::assertSame('lifecycle', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initial']);
		self::assertSame(['from' => 'draft', 'to' => 'published'], $lifecycle['transitions']['publish']);
		self::assertSame(['from' => 'published', 'to' => 'archived'], $lifecycle['transitions']['archive']);
	}//end testARuleIsDraftedPublishedAndArchived()
}//end class
