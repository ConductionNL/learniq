<?php

/**
 * Unit tests for the confidential counsellor channel.
 *
 * A vertrouwenspersoon's notes must be structurally out of reach of every
 * school role (decision D18, recon E section 3). These tests pin the one
 * declared scope, the ConfidentialNote authorization block, the absence of
 * every other group from it, the isolation from other schemas, and the
 * menu gate that shows the notes to the function only.
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
 * @spec openspec/changes/archive/2026-09-28-confidential-counsellor-channel/tasks.md#task-4-register-test
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the confidential counsellor scope, schema and menu gate.
 */
class ConfidentialCounsellorChannelRegisterTest extends TestCase {

	private const GROUP = 'confidential-counsellors';

	/**
	 * Groups that must never reach a confidential note.
	 */
	private const FORBIDDEN = [
		'instructors',
		'hr',
		'compliance-officers',
		'team-leads',
		'learners',
		'coordinators',
		'guardians',
		'administration-managers',
	];

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
		$path         = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);

	}//end setUp()

	/**
	 * The ConfidentialNote schema.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		return $this->config['components']['schemas']['ConfidentialNote'];

	}//end schema()

	/**
	 * The scope is declared next to the other groups, and the eight existing
	 * groups are still there.
	 *
	 * @return void
	 */
	public function testTheScopeIsDeclared(): void {
		$scopes = $this->config['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes'];

		self::assertArrayHasKey(self::GROUP, $scopes);
		foreach (self::FORBIDDEN as $group) {
			self::assertArrayHasKey($group, $scopes);
		}

	}//end testTheScopeIsDeclared()

	/**
	 * The authorization block is exactly: author-in-group or named participant
	 * reads; the group creates; the author-in-group updates and deletes.
	 *
	 * @return void
	 */
	public function testAuthorizationIsAuthorAndParticipantsOnly(): void {
		$author = ['group' => self::GROUP, 'match' => ['authorId' => '$userId']];

		self::assertSame(
			[
				'read'   => [
					$author,
					['group' => 'authenticated', 'match' => ['participantIds' => ['$contains' => '$userId']]],
				],
				'create' => [self::GROUP],
				'update' => [$author],
				'delete' => [$author],
			],
			$this->schema()['authorization']
		);

	}//end testAuthorizationIsAuthorAndParticipantsOnly()

	/**
	 * No school group appears anywhere in the block, as a string or as the
	 * group of a conditional rule.
	 *
	 * @return void
	 */
	public function testNoSchoolGroupReachesANote(): void {
		foreach ($this->schema()['authorization'] as $action => $rules) {
			foreach ($rules as $rule) {
				$group = $rule;
				if (is_array($rule) === true) {
					$group = $rule['group'];
				}

				self::assertNotContains($group, self::FORBIDDEN, "ConfidentialNote.$action grants $group.");
				self::assertContains($group, [self::GROUP, 'authenticated']);
			}
		}

	}//end testNoSchoolGroupReachesANote()

	/**
	 * Matched fields exist, participantIds is an array (so $contains fits),
	 * and authorId is a plain user-id string.
	 *
	 * @return void
	 */
	public function testMatchedFieldsFitTheirOperators(): void {
		$properties = $this->schema()['properties'];

		self::assertSame('array', $properties['participantIds']['type']);
		self::assertSame('string', $properties['authorId']['type']);
		self::assertContains('authorId', $this->schema()['required']);

	}//end testMatchedFieldsFitTheirOperators()

	/**
	 * No reference leads to or from a confidential note, and the schema is
	 * unsearchable, hard-deleting and not append-only.
	 *
	 * @return void
	 */
	public function testTheSchemaIsStructurallyIsolated(): void {
		$schema = $this->schema();

		self::assertStringNotContainsString('"$ref"', (string)json_encode($schema['properties']));
		foreach ($this->config['components']['schemas'] as $name => $other) {
			$encoded = (string)json_encode(($other['properties'] ?? []));
			self::assertStringNotContainsString('"ConfidentialNote"', $encoded, "$name references ConfidentialNote.");
			self::assertStringNotContainsString('"confidential-note"', $encoded, "$name references confidential-note.");
		}

		self::assertFalse($schema['x-openregister']['searchable']);
		self::assertTrue($schema['x-openregister']['hardDelete']);
		self::assertArrayNotHasKey('appendOnly', $schema['x-openregister']);
		self::assertArrayNotHasKey('x-property-rbac', $schema);

	}//end testTheSchemaIsStructurallyIsolated()

	/**
	 * The seed row satisfies the schema's required fields and names no
	 * participants.
	 *
	 * @return void
	 */
	public function testTheSeedRowIsComplete(): void {
		$schema = $this->schema();
		// Found by id, not by position: another change may seed this schema too.
		$seed = $this->seedById(schema: $schema, id: '00000000-0000-0000-0000-0000000f0001');

		foreach ($schema['required'] as $field) {
			self::assertArrayHasKey($field, $seed);
		}

		self::assertSame([], $seed['participantIds']);
		self::assertContains($seed['status'], $schema['properties']['status']['enum']);

	}//end testTheSeedRowIsComplete()

	/**
	 * The menu entry gates on the confidential flag, not on a primary role,
	 * and both pages point at the schema.
	 *
	 * @return void
	 */
	public function testTheMenuIsGatedOnTheConfidentialFlag(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../src/manifest.d/confidential-counsel.json'),
			true
		);

		self::assertSame(['user.isConfidentialCounsellor' => ['eq' => true]], $fragment['menu'][0]['visibleIf']);
		foreach ($fragment['pages'] as $page) {
			if (str_starts_with($page['id'], 'ConfidentialNote') === false) {
				continue;
			}

			self::assertSame('confidential-note', $page['config']['schema']);
		}

		$main = (string)file_get_contents(__DIR__ . '/../../../src/main.js');
		self::assertStringContainsString("loadState('learniq', 'confidentialCounsellor'", $main);

	}//end testTheMenuIsGatedOnTheConfidentialFlag()

	/**
	 * The seed row with the given id, failing the test when there is none.
	 *
	 * @param array<string, mixed> $schema The schema.
	 * @param string $id The seed row's id.
	 *
	 * @return array<string, mixed>
	 */
	private function seedById(array $schema, string $id): array {
		foreach (($schema['x-openregister-seed'] ?? []) as $seed) {
			if (($seed['id'] ?? null) === $id) {
				return $seed;
			}
		}

		self::fail('No seed row with id ' . $id);
	}//end seedById()
}//end class
