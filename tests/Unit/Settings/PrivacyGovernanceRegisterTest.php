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
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 * @spec openspec/specs/data-exchange/spec.md
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
	 * @spec   openspec/specs/avg-verwerkingsregister/spec.md#requirement-the-school-records-its-privacyconvenant-agreement-and-privacybijsluiter
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
	 * The partner approval left DataExchangeJob (data-exchange-to-integriq): an
	 * ExchangePartnerApproval is a standing link per target with its own
	 * approve and reject lifecycle, and its seed never blocks a real exchange.
	 *
	 * @return void
	 * @spec   openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-the-gate-enforces-partner-approval-teldatum-confirmation-and-flag-handling
	 */
	public function testPartnerApprovalIsAStandingRecordPerTarget(): void {
		$schema = $this->config['components']['schemas']['ExchangePartnerApproval'] ?? null;
		$this->assertIsArray($schema, 'ExchangePartnerApproval schema MUST exist');

		$properties = $schema['properties'];
		$this->assertSame(['target'], $schema['required']);
		$this->assertContains('swv', $properties['target']['enum']);
		$this->assertSame(['pending', 'approved', 'rejected'], $properties['status']['enum']);
		$this->assertSame([], $properties['dataSharedFields']['default']);

		$transitions = $schema['x-openregister-lifecycle']['transitions'];
		$this->assertSame(['field' => 'note', 'required' => true], $transitions['reject']['inputs'][0]);
		foreach (['approve', 'reject'] as $action) {
			$this->assertSame(['actorField' => 'decidedBy', 'timeField' => 'decidedAt'], $transitions[$action]['actions'][0]['actionParameters']);
		}

		// A seeded row opts its target into the gate, so the seed uses a target without a handler.
		foreach ($schema['x-openregister-seed'] as $row) {
			$this->assertNotContains($row['target'], ['bron-rod', 'oso', 'leerplicht', 'swv', 'uwlr', 'edu-v', 'basispoort', 'entree-content']);
		}

	}//end testPartnerApprovalIsAStandingRecordPerTarget()
}//end class
