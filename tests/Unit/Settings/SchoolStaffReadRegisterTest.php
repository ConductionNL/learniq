<?php

/**
 * Learniq School staff-read register test.
 *
 * Found in the primary-school live check (2026-10-02): a teacher could not
 * read the school, so portaliq's News screen offered no school to write
 * whole-school news for. School carried no authorization block and fell back
 * to the register's `read-write` role. OpenRegister applies that cascade to
 * the row grant, but its multitenancy bypass only reads the schema's OWN
 * block, so for a non-admin the rows (which carry no organisation) were
 * filtered away. An explicit block on School gives staff the read on the
 * schema itself, and keeps writing exactly where the cascade had it.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/changes/school-readable-by-staff/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Asserts staff read the School schema through its own authorization block.
 */
class SchoolStaffReadRegisterTest extends TestCase {

	/**
	 * The staff groups that read a school.
	 */
	private const STAFF = ['instructors', 'hr', 'compliance-officers', 'team-leads', 'coordinators', 'administration-managers'];

	/**
	 * The groups the register's read-write role gave create and update before.
	 */
	private const WRITERS = ['instructors', 'hr', 'compliance-officers', 'team-leads'];

	/**
	 * The shipped register.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		return json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);
	}//end register()

	/**
	 * School declares its own authorization, so OpenRegister's multitenancy
	 * bypass sees the staff grant.
	 *
	 * @return void
	 */
	public function testSchoolDeclaresItsOwnAuthorization(): void {
		$authorization = ($this->register()['components']['schemas']['School']['authorization'] ?? null);
		self::assertIsArray($authorization, 'School must carry its own authorization block');
		foreach (self::STAFF as $group) {
			self::assertContains(needle: $group, haystack: $authorization['read'], message: $group . ' reads the school');
		}
	}//end testSchoolDeclaresItsOwnAuthorization()

	/**
	 * Writing stays where the register cascade had it, and nobody but an
	 * administrator deletes a school; a guardian or pupil gets nothing.
	 *
	 * @return void
	 */
	public function testWritingStaysWhereItWas(): void {
		$authorization = $this->register()['components']['schemas']['School']['authorization'];
		foreach (['create', 'update'] as $operation) {
			self::assertEqualsCanonicalizing(expected: self::WRITERS, actual: $authorization[$operation], message: $operation);
		}

		self::assertArrayNotHasKey(key: 'delete', array: $authorization);
		foreach ($authorization as $operation => $rules) {
			self::assertNotContains(needle: 'authenticated', haystack: $rules, message: $operation);
			self::assertNotContains(needle: 'public', haystack: $rules, message: $operation);
		}
	}//end testWritingStaysWhereItWas()
}//end class
