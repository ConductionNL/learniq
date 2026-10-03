<?php

/**
 * Unit tests for the `timetabling-standby-slots` register declaration.
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
 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the StandbySlot schema: a teacher, a weekday or date, a window, validity, access.
 */
class StandbySlotRegisterTest extends TestCase {

	/**
	 * The StandbySlot schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $schema;

	/**
	 * Load the register once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$config = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$this->schema = $config['components']['schemas']['StandbySlot'];
		self::assertContains('standby-slot', $config['components']['registers']['learniq']['schemas']);
	}//end setUp()

	/**
	 * The slot's fields.
	 *
	 * @return void
	 */
	public function testShape(): void {
		$props = $this->schema['properties'];

		self::assertSame(['teacherId', 'startsAt', 'endsAt', 'validFrom', 'validUntil', 'tenant_id'], $this->schema['required']);
		self::assertSame(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], $props['weekday']['enum']);
		self::assertTrue($props['weekday']['nullable']);
		self::assertTrue($props['date']['nullable']);
		self::assertSame('Vestiging', $props['vestigingId']['$ref']);
		self::assertSame(1, preg_match('/' . $props['startsAt']['pattern'] . '/', '10:15'));
		self::assertSame(0, preg_match('/' . $props['startsAt']['pattern'] . '/', '25:00'));
	}//end testShape()

	/**
	 * Every signed-in user reads standby; team leads and compliance officers plan it.
	 *
	 * @return void
	 */
	public function testAccess(): void {
		$auth = $this->schema['authorization'];

		self::assertSame(['authenticated'], $auth['read']);
		self::assertSame(['team-leads', 'compliance-officers'], $auth['create']);
		self::assertSame($auth['create'], $auth['update']);
	}//end testAccess()
}//end class
