<?php

/**
 * Learniq work group register test.
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
 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-teacher-sets-up-work-groups-with-a-maximum-size
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pins WorkGroup's staff-only access and the assignment's set name.
 */
class WorkGroupRegisterTest extends TestCase {

	/**
	 * The shipped schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas'];
	}//end schemas()

	/**
	 * Only staff read and write work groups through the object API; a group
	 * has a set, a name and a maximum size.
	 *
	 * @return void
	 */
	public function testWorkGroupsAreStaffOnlyWithAMaximumSize(): void {
		$group = $this->schemas()['WorkGroup'];

		foreach (['read', 'create', 'update'] as $verb) {
			self::assertSame(['instructors', 'team-leads', 'compliance-officers'], $group['authorization'][$verb], $verb);
		}

		self::assertSame(['cohortId', 'setName', 'name', 'maxMembers', 'tenant_id'], $group['required']);
		self::assertSame(1, $group['properties']['maxMembers']['minimum']);
		self::assertTrue($group['properties']['selfJoinUntil']['nullable']);
		self::assertSame(['close', 'reopen'], array_keys($group['x-openregister-lifecycle']['transitions']));
	}//end testWorkGroupsAreStaffOnlyWithAMaximumSize()

	/**
	 * An assignment names the work group set of a group hand-in.
	 *
	 * @return void
	 */
	public function testAnAssignmentNamesItsWorkGroupSet(): void {
		self::assertTrue($this->schemas()['Assignment']['properties']['workGroupSetName']['nullable']);
	}//end testAnAssignmentNamesItsWorkGroupSet()
}//end class
