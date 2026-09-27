<?php

/**
 * Learniq SsoAttributeMappingApplier unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/entree-surfconext-sso-contract/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\SsoAttributeMappingApplier;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SsoAttributeMappingApplier::apply() — the pure, side-effect-free
 * computation of LearnerProfile field updates from an SSO attribute bag.
 */
class SsoAttributeMappingApplierTest extends TestCase {
	/**
	 * Build an applier whose ObjectService::findAll() returns the given
	 * mapping rows for any query.
	 *
	 * @param array<int,array<string,mixed>> $mappings Mapping rows to return.
	 *
	 * @return SsoAttributeMappingApplier
	 */
	private function makeApplier(array $mappings): SsoAttributeMappingApplier {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn($mappings);

		return new SsoAttributeMappingApplier($objectService);
	}//end makeApplier()

	/**
	 * A direct field mapping (eckId) computes a field update from a matching
	 * attribute, without writing to LearnerProfile.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/entree-surfconext-sso-contract/specs/identity-federation/spec.md#scenario-attribute-mappings-compute-a-field-update-array-without-writing-anything
	 */
	public function testComputesFieldUpdateFromMatchingAttribute(): void {
		$applier = $this->makeApplier(
			[
				['externalAttribute' => 'eckId', 'learniqField' => 'eckId'],
			]
		);

		$result = $applier->apply(['eckId' => 'ABC123'], 'saml', 'tenant-1');

		self::assertSame(['eckId' => 'ABC123'], $result);

	}//end testComputesFieldUpdateFromMatchingAttribute()

	/**
	 * Two role mappings whose externalAttribute/externalValue both match
	 * accumulate into one roles array rather than overwriting each other.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/entree-surfconext-sso-contract/specs/identity-federation/spec.md#scenario-multiple-role-mappings-accumulate-into-one-roles-array
	 */
	public function testAccumulatesMultipleRoleMatches(): void {
		$applier = $this->makeApplier(
			[
				['externalAttribute' => 'group', 'learniqField' => 'role', 'externalValue' => 'docent', 'roleValue' => 'instructor'],
				['externalAttribute' => 'group2', 'learniqField' => 'role', 'externalValue' => 'mentor', 'roleValue' => 'mentor'],
			]
		);

		$result = $applier->apply(['group' => 'docent', 'group2' => 'mentor'], 'saml', 'tenant-1');

		self::assertSame(['roles' => ['instructor', 'mentor']], $result);

	}//end testAccumulatesMultipleRoleMatches()

	/**
	 * A role mapping whose externalValue does not match the attribute's
	 * actual value contributes nothing.
	 *
	 * @return void
	 */
	public function testRoleMappingRequiresExactValueMatch(): void {
		$applier = $this->makeApplier(
			[
				['externalAttribute' => 'group', 'learniqField' => 'role', 'externalValue' => 'docent', 'roleValue' => 'instructor'],
			]
		);

		$result = $applier->apply(['group' => 'leerling'], 'saml', 'tenant-1');

		self::assertSame([], $result);

	}//end testRoleMappingRequiresExactValueMatch()

	/**
	 * An attribute with no matching active mapping is silently ignored —
	 * fail-closed, never invents a field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/entree-surfconext-sso-contract/specs/identity-federation/spec.md#scenario-an-attribute-with-no-matching-mapping-is-silently-ignored
	 */
	public function testUnmappedAttributeIsIgnored(): void {
		$applier = $this->makeApplier(
			[
				['externalAttribute' => 'eckId', 'learniqField' => 'eckId'],
			]
		);

		$result = $applier->apply(['someOtherAttribute' => 'value'], 'saml', 'tenant-1');

		self::assertSame([], $result);

	}//end testUnmappedAttributeIsIgnored()

	/**
	 * An archived mapping is not returned by the loader (mocked here to
	 * simulate the filters ObjectService::findAll would have applied), so
	 * it never contributes to the computed updates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/entree-surfconext-sso-contract/specs/identity-federation/spec.md#scenario-only-active-mappings-are-eligible-for-application
	 */
	public function testArchivedMappingIsIgnored(): void {
		// Simulates the lifecycle:'active' filter already excluding archived rows —
		// this applier trusts ObjectService::findAll's filters, it does not
		// re-check lifecycle itself.
		$applier = $this->makeApplier([]);

		$result = $applier->apply(['eckId' => 'ABC123'], 'saml', 'tenant-1');

		self::assertSame([], $result);

	}//end testArchivedMappingIsIgnored()

	/**
	 * A mapping with no roleValue set is skipped even if the attribute
	 * matches — fail-closed, never adds a null/empty role.
	 *
	 * @return void
	 */
	public function testRoleMappingWithoutRoleValueIsSkipped(): void {
		$applier = $this->makeApplier(
			[
				['externalAttribute' => 'group', 'learniqField' => 'role', 'externalValue' => 'docent', 'roleValue' => null],
			]
		);

		$result = $applier->apply(['group' => 'docent'], 'saml', 'tenant-1');

		self::assertSame([], $result);

	}//end testRoleMappingWithoutRoleValueIsSkipped()
}//end class
