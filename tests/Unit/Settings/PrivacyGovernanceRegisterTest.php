<?php

/**
 * Unit tests for the `privacy-governance-surfaces` register delta.
 *
 * Asserts the schema shape for the new Compliance singleton and
 * DataSubjectRequest schemas, and the five additive DataExchangeJob
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
	 * DataSubjectRequest carries the two AVG-right kinds, a staff-only create
	 * floor, and an unguarded requested -> in-review -> completed|rejected
	 * lifecycle with no PHP guard on any transition.
	 *
	 * @return void
	 * @spec   openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#requirement-staff-can-log-and-track-a-correction-or-deletion-request
	 */
	public function testDataSubjectRequestLifecycleAndCreateFloor(): void {
		$schema = $this->config['components']['schemas']['DataSubjectRequest'] ?? null;
		$this->assertIsArray($schema, 'DataSubjectRequest schema MUST exist');

		$this->assertEqualsCanonicalizing(['correction', 'deletion'], $schema['properties']['kind']['enum'] ?? []);

		foreach (['kind', 'learnerId', 'submittedBy', 'requestedAt', 'tenant_id'] as $field) {
			$this->assertContains($field, $schema['required'] ?? [], "DataSubjectRequest.required MUST include $field");
		}

		$lifecycle = $schema['x-openregister-lifecycle'] ?? null;
		$this->assertIsArray($lifecycle, 'DataSubjectRequest MUST declare x-openregister-lifecycle');
		$this->assertSame('requested', $lifecycle['initial'] ?? null);

		$transitions = $lifecycle['transitions'] ?? [];
		$this->assertSame('requested', $transitions['startReview']['from'] ?? null);
		$this->assertSame('in-review', $transitions['startReview']['to'] ?? null);
		$this->assertSame('in-review', $transitions['complete']['from'] ?? null);
		$this->assertSame('completed', $transitions['complete']['to'] ?? null);
		$this->assertSame('in-review', $transitions['reject']['from'] ?? null);
		$this->assertSame('rejected', $transitions['reject']['to'] ?? null);

		foreach ($transitions as $transition) {
			$this->assertArrayNotHasKey('requires', $transition, 'No DataSubjectRequest transition carries a PHP guard');
		}

		$createRoles = $schema['authorization']['create'] ?? [];
		$this->assertEqualsCanonicalizing(['instructors', 'compliance-officers'], $createRoles);

	}//end testDataSubjectRequestLifecycleAndCreateFloor()

	/**
	 * DataSubjectRequest.auditTrail is an append-only-shaped array defaulting
	 * to empty, whose entries require recordedBy/recordedAt/action — the same
	 * shape as BehaviourIncident.followUpActions.
	 *
	 * @return void
	 * @spec   openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#requirement-staff-can-log-and-track-a-correction-or-deletion-request
	 */
	public function testAuditTrailShapeMirrorsFollowUpActions(): void {
		$schema = $this->config['components']['schemas']['DataSubjectRequest'] ?? null;
		$auditTrail = $schema['properties']['auditTrail'] ?? [];

		$this->assertSame([], $auditTrail['default'] ?? null, 'auditTrail MUST default to an empty array');
		$this->assertEqualsCanonicalizing(
			['recordedBy', 'recordedAt', 'action'],
			$auditTrail['items']['required'] ?? [],
			'auditTrail entries MUST require recordedBy/recordedAt/action, same shape as BehaviourIncident.followUpActions'
		);

	}//end testAuditTrailShapeMirrorsFollowUpActions()

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
