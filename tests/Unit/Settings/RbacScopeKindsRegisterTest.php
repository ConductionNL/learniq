<?php

/**
 * Unit tests for the `rbac-scope-kinds-extension` register delta.
 *
 * Asserts two additive scope-kind grants on the REAL, enforced
 * `authorization[action]` conditional-match grammar (never `x-property-rbac`
 * — confirmed by direct inspection of OpenRegister's lib/ to be unread by any
 * code path, see design.md): Cohort's own-group read entry, and
 * DossierNote's care-team read entry. Both consume already-shipped
 * OpenRegister operators (`$in`, `$contains`) — no OpenRegister change, no
 * new PHP guard.
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
 * @spec openspec/changes/rbac-scope-kinds-extension/specs/rbac-groups/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the rbac-scope-kinds-extension register delta.
 */
class RbacScopeKindsRegisterTest extends TestCase {

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
	 * Cohort's authorization block reproduces the register cascade's exact
	 * grantee list for read/create/update, and read carries one additional
	 * own-group conditional entry.
	 *
	 * @return void
	 * @spec   openspec/changes/rbac-scope-kinds-extension/specs/rbac-groups/spec.md#requirement-an-own-group-scope-kind-grants-read-access-via-a-callers-nextcloud-group-membership
	 */
	public function testCohortAuthorizationReproducesCascadePlusOwnGroupRead(): void {
		$schema = $this->config['components']['schemas']['Cohort'] ?? null;
		$this->assertIsArray($schema, 'Cohort schema MUST exist');

		$cascade = ['instructors', 'hr', 'compliance-officers', 'team-leads'];

		$authorization = $schema['authorization'] ?? [];
		$this->assertArrayNotHasKey('delete', $authorization, 'Cohort MUST NOT declare a delete key — admin-only-by-omission preserved');

		foreach (['create', 'update'] as $action) {
			$this->assertEqualsCanonicalizing($cascade, $authorization[$action] ?? [], "Cohort.$action MUST exactly reproduce the register cascade");
		}

		$readEntries = $authorization['read'] ?? [];
		$literalGroups = array_filter($readEntries, 'is_string');
		$this->assertEqualsCanonicalizing($cascade, array_values($literalGroups), 'Cohort.read MUST include every cascade group as a literal entry');

		$conditionalEntries = array_values(array_filter($readEntries, 'is_array'));
		$this->assertCount(1, $conditionalEntries, 'Cohort.read MUST carry exactly one conditional (own-group) entry');

		$entry = $conditionalEntries[0];
		$this->assertSame('authenticated', $entry['group'] ?? null);
		$this->assertEqualsCanonicalizing(['ncGroupId'], array_keys($entry['match'] ?? []));
		$this->assertEqualsCanonicalizing(['$in'], array_keys($entry['match']['ncGroupId'] ?? []));
		$this->assertSame('$user.groups', $entry['match']['ncGroupId']['$in'] ?? null);

	}//end testCohortAuthorizationReproducesCascadePlusOwnGroupRead()

	/**
	 * DossierNote gains careTeamUserIds (array, default []) and its
	 * authorization.read keeps the two existing literal entries plus one new
	 * care-team conditional entry. create/update are unchanged.
	 *
	 * @return void
	 * @spec   openspec/changes/rbac-scope-kinds-extension/specs/rbac-groups/spec.md#requirement-a-care-team-scope-kind-grants-read-access-via-an-array-of-user-ids-property
	 */
	public function testDossierNoteGainsCareTeamPropertyAndReadEntry(): void {
		$schema = $this->config['components']['schemas']['DossierNote'] ?? null;
		$this->assertIsArray($schema, 'DossierNote schema MUST exist');

		$careTeamProp = $schema['properties']['careTeamUserIds'] ?? null;
		$this->assertIsArray($careTeamProp, 'DossierNote MUST declare careTeamUserIds');
		$this->assertSame('array', $careTeamProp['type'] ?? null);
		$this->assertSame('string', $careTeamProp['items']['type'] ?? null);
		$this->assertSame([], $careTeamProp['default'] ?? null);
		$this->assertArrayHasKey('title', $careTeamProp);
		$this->assertArrayHasKey('description', $careTeamProp);

		$readEntries = $schema['authorization']['read'] ?? [];
		$literalGroups = array_values(array_filter($readEntries, 'is_string'));
		$this->assertEqualsCanonicalizing(['instructors', 'compliance-officers'], $literalGroups, 'The pre-existing read floor MUST be unchanged');

		$conditionalEntries = array_values(array_filter($readEntries, 'is_array'));
		$this->assertCount(1, $conditionalEntries, 'DossierNote.read MUST carry exactly one conditional (care-team) entry');

		$entry = $conditionalEntries[0];
		$this->assertSame('authenticated', $entry['group'] ?? null);
		$this->assertEqualsCanonicalizing(['careTeamUserIds'], array_keys($entry['match'] ?? []));
		$this->assertEqualsCanonicalizing(['$contains'], array_keys($entry['match']['careTeamUserIds'] ?? []));
		$this->assertSame('$userId', $entry['match']['careTeamUserIds']['$contains'] ?? null);

		// Create/update unchanged from DossierNote's existing staff-only floor.
		$this->assertEqualsCanonicalizing(['instructors', 'compliance-officers'], $schema['authorization']['create'] ?? []);
		$this->assertEqualsCanonicalizing(['instructors', 'compliance-officers'], $schema['authorization']['update'] ?? []);

	}//end testDossierNoteGainsCareTeamPropertyAndReadEntry()

	/**
	 * Neither scope-kind grant touches x-property-rbac — this change extends
	 * only the real, enforced authorization grammar (design.md's central
	 * finding: x-property-rbac is unread by any OpenRegister code path).
	 *
	 * @return void
	 * @spec   openspec/changes/rbac-scope-kinds-extension/specs/rbac-groups/spec.md
	 */
	public function testNeitherSchemaGainsAnXPropertyRbacEntry(): void {
		$cohort = $this->config['components']['schemas']['Cohort'] ?? [];
		$this->assertArrayNotHasKey('x-property-rbac', $cohort, 'Cohort MUST NOT gain an x-property-rbac block — it is a confirmed-dead key');

		// DossierNote already carries a pre-existing (inherited, not extended
		// by this change) x-property-rbac block; assert this change did not
		// add a care-team clause to it, keeping the finding scoped to the
		// real authorization key only.
		$dossierNote = $this->config['components']['schemas']['DossierNote'] ?? [];
		$xPropertyRbacReadAnyOf = $dossierNote['x-property-rbac']['read']['anyOf'] ?? [];
		$matches = array_filter(array_column($xPropertyRbacReadAnyOf, 'match'));
		foreach ($matches as $match) {
			$this->assertNotSame('careTeamUserIds', $match['field'] ?? null, 'This change MUST NOT extend the decoy x-property-rbac key');
		}

	}//end testNeitherSchemaGainsAnXPropertyRbacEntry()
}//end class
