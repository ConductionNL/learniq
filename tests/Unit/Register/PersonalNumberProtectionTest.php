<?php

/**
 * LearnerProfile.personalNumber is encrypted at rest and readable only by
 * administration managers and compliance officers.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-a-learners-personal-number-is-readable-only-by-administration-and-compliance
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped register.
 */
class PersonalNumberProtectionTest extends TestCase {

	private const AUDIENCE = ['administration-managers', 'compliance-officers'];

	/**
	 * The LearnerProfile properties of the shipped register.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function properties(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas']['LearnerProfile']['properties'];
	}//end properties()

	/**
	 * The register declares the protection.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#scenario-the-register-declares-the-protection
	 * @spec openspec/specs/data-exchange/spec.md#scenario-a-teacher-reads-a-learner-profile
	 */
	public function testTheRegisterDeclaresTheProtection(): void {
		$properties = $this->properties();
		$number = $properties['personalNumber'];

		// DECISIONS row 54 (live pass D6): stored in plain text so an upload row can be matched on it;
		// an encrypted property gets no filter in OpenRegister.
		$this->assertArrayNotHasKey('x-openregister-encrypted', $number, 'Stored and filterable, not encrypted.');
		$this->assertTrue($number['authorization']['audit'] ?? false, 'Every reveal is recorded.');
		$this->assertArrayNotHasKey('pattern', $number, 'A pattern would validate the stored envelope.');
		$this->assertFalse($number['facetable'] ?? false);

		foreach (['personalNumber', 'personalNumberType'] as $name) {
			foreach (['read', 'update'] as $action) {
				$this->assertSame(self::AUDIENCE, $properties[$name]['authorization'][$action], $name . ' ' . $action);
				$this->assertNotContains('instructors', $properties[$name]['authorization'][$action]);
				$this->assertNotContains('authenticated', $properties[$name]['authorization'][$action]);
			}
		}

		$this->assertSame(['bsn', 'onderwijsnummer'], $properties['personalNumberType']['enum']);
	}//end testTheRegisterDeclaresTheProtection()
}//end class
