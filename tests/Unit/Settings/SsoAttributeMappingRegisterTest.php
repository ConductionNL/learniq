<?php

/**
 * Unit tests for the `entree-surfconext-sso-contract` register-JSON declarations.
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
 * @spec openspec/changes/entree-surfconext-sso-contract/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the SsoAttributeMapping schema declaration and its
 * draft/active/archived lifecycle.
 */
class SsoAttributeMappingRegisterTest extends TestCase {

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
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);

	}//end setUp()

	/**
	 * Required fields and the provider/learniqField enums are correct.
	 *
	 * @return void
	 */
	public function testRequiredFieldsAndEnums(): void {
		$schema = $this->config['components']['schemas']['SsoAttributeMapping'];

		self::assertSame(
			['provider', 'externalAttribute', 'learniqField', 'tenant_id'],
			$schema['required']
		);

		$props = $schema['properties'];
		self::assertSame(['saml', 'oidc'], $props['provider']['enum']);
		self::assertSame(['eckId', 'schoolId', 'givenName', 'familyName', 'role'], $props['learniqField']['enum']);

	}//end testRequiredFieldsAndEnums()

	/**
	 * A role mapping carries both externalValue (the match value) and
	 * roleValue (the target LearnerProfile.roles member), both nullable for
	 * direct field mappings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/entree-surfconext-sso-contract/specs/identity-federation/spec.md#scenario-a-role-mapping-names-both-the-external-value-and-the-target-role
	 */
	public function testRoleMappingCarriesRoleValue(): void {
		$props = $this->config['components']['schemas']['SsoAttributeMapping']['properties'];

		self::assertTrue($props['externalValue']['nullable']);
		self::assertNull($props['externalValue']['default']);
		self::assertTrue($props['roleValue']['nullable']);
		self::assertNull($props['roleValue']['default']);

	}//end testRoleMappingCarriesRoleValue()

	/**
	 * Lifecycle mirrors DataMappingProfile's own draft/active/archived shape.
	 *
	 * @return void
	 */
	public function testLifecycleMirrorsDataMappingProfile(): void {
		$lifecycle = $this->config['components']['schemas']['SsoAttributeMapping']['x-openregister-lifecycle'];
		$dmpLifecycle = $this->config['components']['schemas']['DataMappingProfile']['x-openregister-lifecycle'];

		self::assertSame($dmpLifecycle['property'], $lifecycle['property']);
		self::assertSame($dmpLifecycle['initial'], $lifecycle['initial']);
		self::assertSame(array_keys($dmpLifecycle['transitions']), array_keys($lifecycle['transitions']));

	}//end testLifecycleMirrorsDataMappingProfile()

	/**
	 * active defaults to true.
	 *
	 * @return void
	 */
	public function testActiveDefaultsTrue(): void {
		$prop = $this->config['components']['schemas']['SsoAttributeMapping']['properties']['active'];

		self::assertTrue($prop['default']);

	}//end testActiveDefaultsTrue()

	/**
	 * No custom Vue view or manifest entry is required for this schema — it
	 * relies on the generic OpenRegister index+detail surface, per ADR-024/031
	 * (same posture as DataMappingProfile).
	 *
	 * @return void
	 */
	public function testNoBespokeManifestEntryRequired(): void {
		$manifestPath = __DIR__ . '/../../../src/manifest.json';
		$manifest = json_decode((string)file_get_contents($manifestPath), true);

		$hasCustomSsoPage = false;
		foreach (($manifest['pages'] ?? []) as $page) {
			if (stripos((string)($page['id'] ?? ''), 'sso') !== false) {
				$hasCustomSsoPage = true;
			}
		}

		self::assertFalse($hasCustomSsoPage, 'SsoAttributeMapping must not need a bespoke manifest page');

	}//end testNoBespokeManifestEntryRequired()

	/**
	 * Every SsoAttributeMapping property carries a title and description
	 * (gate-28 discipline).
	 *
	 * @return void
	 */
	public function testEveryPropertyHasTitleAndDescription(): void {
		$props = $this->config['components']['schemas']['SsoAttributeMapping']['properties'];
		foreach ($props as $name => $prop) {
			self::assertArrayHasKey('title', $prop, "SsoAttributeMapping.{$name} missing title");
			self::assertArrayHasKey('description', $prop, "SsoAttributeMapping.{$name} missing description");
		}

	}//end testEveryPropertyHasTitleAndDescription()
}//end class
