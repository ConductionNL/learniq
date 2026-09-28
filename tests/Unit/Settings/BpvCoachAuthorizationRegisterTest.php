<?php

/**
 * Unit tests for the enforced authorization blocks on BpvPlacement and
 * Praktijkopleider.
 *
 * Both schemas fell through to the register cascade, which grants the four
 * staff groups every row and leaves out `coordinators`, the group of the
 * stagecoördinator the BPV menu is shown to. These tests pin the explicit
 * blocks: cascade groups kept, the menu audience added, and on BpvPlacement
 * the named school coach and the learner as same-row read scopes.
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
 * @spec openspec/changes/bpv-coach-authorization/tasks.md#task-2-register-tests
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the BPV authorization blocks.
 */
class BpvCoachAuthorizationRegisterTest extends TestCase {

	/**
	 * Groups that read BPV records: the cascade four plus the menu audience.
	 */
	private const READERS = [
		'instructors',
		'hr',
		'compliance-officers',
		'team-leads',
		'coordinators',
		'administration-managers',
	];

	/**
	 * Groups that create and update BPV records: the cascade four plus coordinators.
	 */
	private const WRITERS = ['instructors', 'hr', 'compliance-officers', 'team-leads', 'coordinators'];

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
	 * A same-row read entry for the user in `$field`.
	 *
	 * @param string $field Object field holding a Nextcloud user id.
	 *
	 * @return array<string, mixed>
	 */
	private static function selfMatch(string $field): array {
		return ['group' => 'authenticated', 'match' => [$field => '$userId']];

	}//end selfMatch()

	/**
	 * BpvPlacement reads: the six groups, then the coach, then the learner.
	 *
	 * @return void
	 */
	public function testBpvPlacementReadIsScopedToGroupsCoachAndLearner(): void {
		$authorization = $this->config['components']['schemas']['BpvPlacement']['authorization'];

		self::assertSame(
			[...self::READERS, self::selfMatch('schoolCoachId'), self::selfMatch('learnerId')],
			$authorization['read']
		);

	}//end testBpvPlacementReadIsScopedToGroupsCoachAndLearner()

	/**
	 * Praktijkopleider reads: the six groups, and no self-match, since a
	 * praktijkopleider has no Nextcloud account.
	 *
	 * @return void
	 */
	public function testPraktijkopleiderReadIsGroupOnly(): void {
		$authorization = $this->config['components']['schemas']['Praktijkopleider']['authorization'];

		self::assertSame(self::READERS, $authorization['read']);

	}//end testPraktijkopleiderReadIsGroupOnly()

	/**
	 * Both schemas: writers are the five groups, and delete stays admin-only.
	 *
	 * @return void
	 */
	public function testWritesGoToTheWriterGroupsAndNobodyDeletes(): void {
		foreach (['BpvPlacement', 'Praktijkopleider'] as $name) {
			$authorization = $this->config['components']['schemas'][$name]['authorization'];

			self::assertSame(self::WRITERS, $authorization['create'], $name . ' create');
			self::assertSame(self::WRITERS, $authorization['update'], $name . ' update');
			self::assertArrayNotHasKey('delete', $authorization, $name . ' delete');
		}

	}//end testWritesGoToTheWriterGroupsAndNobodyDeletes()

	/**
	 * Every group is declared, and every matched field is a user-id property
	 * of the schema.
	 *
	 * @return void
	 */
	public function testGroupsAreDeclaredAndMatchedFieldsExist(): void {
		$declared = array_keys($this->config['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes']);

		foreach (['BpvPlacement', 'Praktijkopleider'] as $name) {
			$schema = $this->config['components']['schemas'][$name];
			foreach ($schema['authorization'] as $action => $rules) {
				foreach ($rules as $rule) {
					if (is_string($rule) === true) {
						self::assertContains($rule, $declared, "$name.$action names an undeclared group.");
						continue;
					}

					self::assertSame('authenticated', $rule['group']);
					foreach (array_keys($rule['match']) as $field) {
						self::assertArrayHasKey($field, $schema['properties'], "$name.$action matches unknown field $field.");
						self::assertSame('string', $schema['properties'][$field]['type']);
						self::assertArrayNotHasKey('format', $schema['properties'][$field], "$name.$field is not a user id.");
					}
				}
			}
		}

	}//end testGroupsAreDeclaredAndMatchedFieldsExist()

	/**
	 * The changed schemas carry a version bump.
	 *
	 * @return void
	 */
	public function testChangedSchemasAreVersionBumped(): void {
		$schemas = $this->config['components']['schemas'];

		// A floor, not an exact value: later changes bump these schemas again.
		self::assertTrue(version_compare($schemas['BpvPlacement']['version'], '0.2.0', '>='));
		self::assertTrue(version_compare($schemas['Praktijkopleider']['version'], '0.2.0', '>='));

	}//end testChangedSchemasAreVersionBumped()
}//end class
