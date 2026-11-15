<?php

/**
 * Unit tests for the `createStates` lists in the register's lifecycles.
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
 * @spec openspec/specs/parent-conferences/spec.md#requirement-teachers-publish-free-times-from-their-availability
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies which lifecycle states a create may start an object in.
 *
 * OpenRegister starts a created object in its lifecycle's initial state, unless
 * the schema lists the other allowed start states in `createStates`.
 */
class LifecycleCreateStatesRegisterTest extends TestCase {

	/**
	 * The decoded register.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Load the register once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
	}//end setUp()

	/**
	 * The expected initial state and extra start states per schema.
	 *
	 * @return array<string, array{0: string, 1: string, 2: list<string>, 3: string}>
	 */
	public static function createStatesProvider(): array {
		return [
			'a teacher publishes a free conference time' => ['ConferenceSlot', 'proposed', ['free'], '0.3.0'],
			'an enrolment made by an administrator starts active' => ['Enrolment', 'pending', ['active'], '0.4.0'],
			'a werkproces assessment is recorded as submitted' => ['WerkprocesAssessment', 'draft', ['submitted'], '0.4.0'],
			'an import report is written once the import resolves' => ['CoursePackageImportReport', 'running', ['succeeded', 'partial', 'failed'], '0.2.0'],
		];
	}//end createStatesProvider()

	/**
	 * Each schema lists its extra start states, and each one is a state of its lifecycle.
	 *
	 * @param string       $schemaName   The schema key.
	 * @param string       $initial      The lifecycle's initial state.
	 * @param list<string> $createStates The extra states a create may start in.
	 * @param string       $minVersion   The schema version that declares them.
	 *
	 * @dataProvider createStatesProvider
	 *
	 * @return void
	 */
	public function testCreateStates(string $schemaName, string $initial, array $createStates, string $minVersion): void {
		$schema    = $this->config['components']['schemas'][$schemaName];
		$lifecycle = $schema['x-openregister-lifecycle'];
		$field     = $lifecycle['field'] ?? $lifecycle['property'];
		$states    = $schema['properties'][$field]['enum'];

		self::assertSame(expected: $initial, actual: $lifecycle['initial']);
		self::assertSame(expected: $createStates, actual: $lifecycle['createStates'] ?? null);
		foreach ($createStates as $state) {
			self::assertContains(needle: $state, haystack: $states, message: $schemaName.' start state '.$state.' is not a lifecycle state');
		}

		$declared = version_compare(version1: (string)$schema['version'], version2: $minVersion, operator: '>=');
		self::assertTrue(condition: $declared, message: $schemaName.' version below '.$minVersion);
	}//end testCreateStates()

	/**
	 * The register version moved with the new declarations.
	 *
	 * @return void
	 */
	public function testRegisterVersion(): void {
		self::assertTrue(condition: version_compare(version1: (string)$this->config['info']['version'], version2: '0.44.0', operator: '>='));
	}//end testRegisterVersion()
}//end class
