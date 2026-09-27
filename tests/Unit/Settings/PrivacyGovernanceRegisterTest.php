<?php

/**
 * Unit tests for the `privacy-governance-surfaces` register delta.
 *
 * Asserts the schema shape for the new Compliance singleton, that the
 * retired DataSubjectRequest copy stays gone (D20), and the five additive DataExchangeJob
 * properties this change introduces. Mirrors PupilDossierNotesRegisterTest's
 * style — these are declarative OpenRegister schemas with no bespoke write
 * controller, so the enforceable surface this suite covers is the schema
 * shape itself; DataExchangeRunGuard's own behaviour is covered separately
 * by DataExchangeRunGuardTest.
 *
 * Assert calls here are POSITIONAL, not named: PHPUnit\Framework\Assert is
 * annotated `@no-named-arguments`, so phpstan hard-errors on a named call —
 * matches every other *RegisterTest.php in this suite.
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
 * @spec openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md
 * @spec openspec/changes/privacy-governance-surfaces/specs/data-exchange/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the privacy-governance-surfaces schema delta.
 */
class PrivacyGovernanceRegisterTest extends TestCase {

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
	 * Compliance is a flat singleton with the Privacyconvenant/privacybijsluiter
	 * fields and a compliance-officers-only RBAC floor — no lifecycle.
	 *
	 * @return void
	 * @spec   openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#requirement-the-school-records-its-privacyconvenant-agreement-and-privacybijsluiter
	 */
	public function testComplianceIsAFlatSingletonWithPrivacyFields(): void {
		$schema = $this->config['components']['schemas']['Compliance'] ?? null;
		$this->assertIsArray($schema, 'Compliance schema MUST exist');

		$this->assertArrayNotHasKey('x-openregister-lifecycle', $schema, 'Compliance MUST have no lifecycle — a flat settings record');

		foreach (
			[
				'privacyconvenantSigned',
				'privacyconvenantSignedAt',
				'verwerkersovereenkomstUrl',
				'privacybijsluiterUrl',
				'privacybijsluiterVersion',
				'lastReviewedAt',
				'lastReviewedBy',
			] as $field
		) {
			$this->assertArrayHasKey($field, $schema['properties'] ?? [], "Compliance MUST declare $field");
		}

		$this->assertContains('privacyconvenantSigned', $schema['required'] ?? []);
		$this->assertFalse($schema['properties']['privacyconvenantSigned']['default'] ?? null, 'privacyconvenantSigned MUST default to false');

		$authorization = $schema['authorization'] ?? [];
		foreach (['read', 'create', 'update'] as $action) {
			$this->assertEqualsCanonicalizing(
				['compliance-officers'],
				$authorization[$action] ?? [],
				"Compliance $action MUST be restricted to compliance-officers"
			);
		}

		foreach ($schema['properties'] as $name => $property) {
			$this->assertArrayHasKey('title', $property, "Compliance.$name MUST carry a title");
			$this->assertArrayHasKey('description', $property, "Compliance.$name MUST carry a description");
		}

	}//end testComplianceIsAFlatSingletonWithPrivacyFields()

	/**
	 * Learniq no longer ships its own DataSubjectRequest: privacy requests live
	 * in OpenRegister's shared data-subject-requests register (D20). The schema
	 * and its slot in the register's schema list are both gone, so the import
	 * cannot recreate the copy.
	 *
	 * @return void
	 * @spec   openspec/changes/privacy-reuse-openregister-register/specs/avg-verwerkingsregister/spec.md#requirement-privacy-requests-live-in-openregisters-data-subject-request-register
	 */
	public function testDataSubjectRequestIsRetiredInFavourOfOpenRegister(): void {
		$this->assertArrayNotHasKey('DataSubjectRequest', $this->config['components']['schemas']);
		foreach ($this->config['components']['schemas'] as $name => $schema) {
			$this->assertNotSame('data-subject-request', $schema['slug'] ?? null, "$name MUST NOT reuse the retired slug");
		}

		$this->assertNotContains('data-subject-request', $this->config['components']['registers']['learniq']['schemas'] ?? []);
		$this->assertTrue(
			version_compare((string)$this->config['info']['version'], '0.25.0', '>='),
			'Retiring a schema bumps the register version'
		);

	}//end testDataSubjectRequestIsRetiredInFavourOfOpenRegister()

	/**
	 * DataExchangeJob gains the five additive partner-approval properties,
	 * each with a backward-compatible default.
	 *
	 * @return void
	 * @spec   openspec/changes/privacy-governance-surfaces/specs/data-exchange/spec.md#requirement-a-dataexchangejob-target-can-require-standing-partner-approval-before-it-runs
	 */
	public function testDataExchangeJobGainsPartnerApprovalProperties(): void {
		$schema = $this->config['components']['schemas']['DataExchangeJob'] ?? null;
		$this->assertIsArray($schema, 'DataExchangeJob schema MUST exist');

		$properties = $schema['properties'] ?? [];
		$this->assertFalse($properties['requiresPartnerApproval']['default'] ?? null, 'requiresPartnerApproval MUST default to false');
		$this->assertSame('not-required', $properties['partnerApprovalStatus']['default'] ?? null);
		$this->assertEqualsCanonicalizing(
			['not-required', 'pending', 'approved', 'rejected'],
			$properties['partnerApprovalStatus']['enum'] ?? []
		);
		$this->assertArrayHasKey('default', $properties['partnerApprovedBy'] ?? []);
		$this->assertNull($properties['partnerApprovedBy']['default']);
		$this->assertArrayHasKey('default', $properties['partnerApprovedAt'] ?? []);
		$this->assertNull($properties['partnerApprovedAt']['default']);
		$this->assertSame([], $properties['dataSharedFields']['default'] ?? null);

		// None of the five are required — every existing and new job is unaffected by default.
		foreach (
			[
				'requiresPartnerApproval',
				'partnerApprovalStatus',
				'partnerApprovedBy',
				'partnerApprovedAt',
				'dataSharedFields',
			] as $field
		) {
			$this->assertNotContains($field, $schema['required'] ?? [], "$field MUST NOT be required");
		}

	}//end testDataExchangeJobGainsPartnerApprovalProperties()
}//end class
