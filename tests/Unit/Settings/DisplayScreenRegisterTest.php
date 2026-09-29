<?php

/**
 * Register contract for DisplayScreen.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The screen schema, its hidden hash and who may manage it.
 */
class DisplayScreenRegisterTest extends TestCase {

	/**
	 * The DisplayScreen schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $schema;

	/**
	 * Load the register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$config = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/learniq_register.json'), true);
		$this->schema = $config['components']['schemas']['DisplayScreen'];
	}//end setUp()

	/**
	 * The schema has its slug, version and scope fields.
	 *
	 * @return void
	 */
	public function testSchemaIsDeclared(): void {
		self::assertSame('display-screen', $this->schema['slug']);
		self::assertSame('0.1.0', $this->schema['version']);
		self::assertSame(['today', 'today-and-tomorrow', 'changes-only'], $this->schema['properties']['shows']['enum']);
		self::assertSame('Vestiging', $this->schema['properties']['vestigingId']['$ref']);
	}//end testSchemaIsDeclared()

	/**
	 * The stored hash is writeOnly, so no read returns it.
	 *
	 * @return void
	 */
	public function testTokenHashIsNeverRead(): void {
		self::assertTrue($this->schema['properties']['tokenHash']['writeOnly']);
	}//end testTokenHashIsNeverRead()

	/**
	 * Only team leads and compliance officers manage screens.
	 *
	 * @return void
	 */
	public function testOnlyManagersManageScreens(): void {
		foreach (['read', 'create', 'update'] as $verb) {
			self::assertSame(['team-leads', 'compliance-officers'], $this->schema['authorization'][$verb]);
		}
	}//end testOnlyManagersManageScreens()
}//end class
