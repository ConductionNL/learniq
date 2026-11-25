<?php

/**
 * Learniq teacher dashboard read register test
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/teacher-dashboard-own-groups/tasks.md#1-6
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Coordinators and administration managers read every group and lesson, and write neither.
 *
 * Decided 25 Nov 2026 (FINISH-ORDER.md DECISIONS): the intern begeleider and the
 * director keep the school-wide teacher dashboard, so they read Cohort and Session;
 * creating and changing them stays with the teaching roles.
 */
class TeacherDashboardReadRegisterTest extends TestCase {

	/**
	 * The groups the decision adds to read.
	 */
	private const ADDED_READERS = ['coordinators', 'administration-managers'];

	/**
	 * The schemas the teacher dashboard lists.
	 */
	private const SCHEMAS = ['Cohort', 'Session'];

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
		$this->config = json_decode((string) file_get_contents($path), true);
	}//end setUp()

	/**
	 * Both roles read every group and every lesson.
	 *
	 * @return void
	 */
	public function testCoordinatorsAndAdministrationManagersReadGroupsAndLessons(): void {
		foreach (self::SCHEMAS as $schema) {
			$read = $this->config['components']['schemas'][$schema]['authorization']['read'];
			foreach (self::ADDED_READERS as $group) {
				self::assertContains(needle: $group, haystack: $read, message: $schema . ' read misses ' . $group);
			}

			foreach (['instructors', 'hr', 'compliance-officers', 'team-leads'] as $kept) {
				self::assertContains(needle: $kept, haystack: $read, message: $schema . ' read lost ' . $kept);
			}
		}
	}//end testCoordinatorsAndAdministrationManagersReadGroupsAndLessons()

	/**
	 * Neither role gains create or update: the read is all the decision gives.
	 *
	 * @return void
	 */
	public function testNeitherRoleWritesGroupsOrLessons(): void {
		foreach (self::SCHEMAS as $schema) {
			$authorization = $this->config['components']['schemas'][$schema]['authorization'];
			foreach (['create', 'update'] as $action) {
				foreach (self::ADDED_READERS as $group) {
					self::assertNotContains(needle: $group, haystack: ($authorization[$action] ?? []), message: $schema . ' ' . $action . ' grants ' . $group);
				}
			}
		}
	}//end testNeitherRoleWritesGroupsOrLessons()
}//end class
