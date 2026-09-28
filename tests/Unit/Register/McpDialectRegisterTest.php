<?php

/**
 * Learniq MCP dialect register test.
 *
 * Learniq's agent tools are derived by OpenRegister from the
 * `x-openregister-mcp` blocks in the register (ADR-063), not written in PHP.
 * This test pins the curation: exactly five non-personal schemas opt in, only
 * read verbs, filters that are real properties, and a lifecycle read rule that
 * keeps drafts away from anyone outside the staff groups, since the deleted
 * hand-written provider enforced that in code.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/scholiq-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-exactly-five-curated-schemas-declare-the-mcp-dialect-req-001
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the curated, read-only MCP surface declared in the register.
 */
class McpDialectRegisterTest extends TestCase {

	/**
	 * The curated schemas and their live lifecycle match.
	 */
	private const CURATED = [
		'Course'     => ['$eq' => 'published'],
		'Lesson'     => ['$eq' => 'published'],
		'Programme'  => ['$eq' => 'published'],
		'Assignment' => ['$in' => ['published', 'closed']],
		'Regulation' => ['$eq' => 'published'],
	];

	/**
	 * The shipped schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas'];
	}//end schemas()

	/**
	 * Only the five curated schemas carry the dialect, and all of them do.
	 *
	 * @return void
	 */
	public function testOnlyTheCuratedSchemasOptIn(): void {
		$optedIn = [];
		foreach (self::schemas() as $name => $schema) {
			if (isset($schema['configuration']['x-openregister-mcp']) === true) {
				$optedIn[] = $name;
			}
		}

		sort($optedIn);
		$expected = array_keys(self::CURATED);
		sort($expected);
		self::assertSame($expected, $optedIn);
		self::assertArrayNotHasKey('configuration', self::schemas()['Session'], 'Session carries learner ids and stays off.');
	}//end testOnlyTheCuratedSchemasOptIn()

	/**
	 * Every declared verb is a read verb with read scope and the read-only hint.
	 *
	 * @return void
	 */
	public function testTheSurfaceIsReadOnly(): void {
		foreach (array_keys(self::CURATED) as $name) {
			$mcp = self::schemas()[$name]['configuration']['x-openregister-mcp'];
			self::assertTrue($mcp['enabled']);
			self::assertSame(['search', 'get'], array_keys($mcp['tools']), "$name declares only search and get.");
			foreach ($mcp['tools'] as $verb => $config) {
				self::assertSame('read', $config['scope'], "$name.$verb scope");
				self::assertTrue($config['readOnlyHint'], "$name.$verb readOnlyHint");
			}
		}
	}//end testTheSurfaceIsReadOnly()

	/**
	 * Every search filter is a property of its schema (OpenRegister refuses the import otherwise).
	 *
	 * @return void
	 */
	public function testEverySearchFilterIsAProperty(): void {
		foreach (array_keys(self::CURATED) as $name) {
			$schema = self::schemas()[$name];
			foreach ($schema['configuration']['x-openregister-mcp']['tools']['search']['filters'] as $filter) {
				self::assertArrayHasKey($filter, $schema['properties'], "$name filter $filter");
			}
		}
	}//end testEverySearchFilterIsAProperty()

	/**
	 * Outside the staff groups, a signed-in user reads only live rows.
	 *
	 * @return void
	 */
	public function testDraftsAreStaffOnly(): void {
		foreach (self::CURATED as $name => $match) {
			$schema = self::schemas()[$name];
			$read   = $schema['authorization']['read'];
			self::assertNotContains('authenticated', $read, "$name gives no unconditional read to every user.");
			self::assertContains(['group' => 'authenticated', 'match' => ['lifecycle' => $match]], $read, "$name live-only rule");
			foreach ($read as $rule) {
				if (is_string($rule) === true) {
					self::assertContains($rule, $schema['authorization']['create'], "$name: unconditional reader $rule also writes the schema.");
				}
			}
		}
	}//end testDraftsAreStaffOnly()
}//end class
