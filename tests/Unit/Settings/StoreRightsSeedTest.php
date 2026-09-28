<?php

/**
 * Learniq store rights seed test (D27).
 *
 * Any teacher installs a shared course as a copy; publishing to the store
 * defaults to the team leads; the Canvas and Moodle package import stays with
 * administrators. An install writes the course, its lessons and its materials
 * as the installing user, so every group the install row names must be allowed
 * to create all three, or the button fails halfway.
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
 * @spec openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-any-teacher-installs-a-shared-course-as-a-copy
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pins the two store rows of the ADR-023 seed.
 */
class StoreRightsSeedTest extends TestCase {

	/**
	 * The seeded matrix.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function seed(): array {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/actions.seed.json'), true);

		return ($seed['actions'] ?? []);
	}//end seed()

	/**
	 * The create grant of one register schema.
	 *
	 * @param string $schema The schema key.
	 *
	 * @return array<int, mixed>
	 */
	private function createGrant(string $schema): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return ($register['components']['schemas'][$schema]['authorization']['create'] ?? []);
	}//end createGrant()

	/**
	 * The three store-related rows hold the D27 defaults.
	 *
	 * @return void
	 */
	public function testTheStoreRowsHoldTheDecidedDefaults(): void {
		$seed = $this->seed();

		self::assertSame(['admin', 'instructors', 'team-leads'], $seed['course-store.install'] ?? null);
		self::assertSame(['admin', 'team-leads'], $seed['course-package.share'] ?? null);
		self::assertSame(['admin'], $seed['course-package.import'] ?? null, 'the package upload stays with administrators');
	}//end testTheStoreRowsHoldTheDecidedDefaults()

	/**
	 * Every non-admin install group may create what an install writes.
	 *
	 * @return void
	 */
	public function testEveryInstallGroupMayCreateWhatAnInstallWrites(): void {
		$groups = array_diff($this->seed()['course-store.install'] ?? [], ['admin']);
		self::assertNotSame([], $groups);

		foreach (['Course', 'Lesson', 'Material'] as $schema) {
			$grant = $this->createGrant(schema: $schema);
			foreach ($groups as $group) {
				self::assertContains($group, $grant, $group . ' must be able to create a ' . $schema . ' to install a course');
			}
		}
	}//end testEveryInstallGroupMayCreateWhatAnInstallWrites()
}//end class
