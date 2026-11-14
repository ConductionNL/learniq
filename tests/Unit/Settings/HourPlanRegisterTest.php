<?php

/**
 * Unit tests for the `timetabling-multi-year-hour-plan` register declarations.
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
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies HourPlan (lines, norms, lifecycle, guard, access) and Cohort.programmeYear.
 */
class HourPlanRegisterTest extends TestCase {

	/**
	 * The register's schemas.
	 *
	 * @var array<string, mixed>
	 */
	private array $schemas;

	/**
	 * Load the register once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$config = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$this->schemas = $config['components']['schemas'];
		self::assertContains('hour-plan', $config['components']['registers']['learniq']['schemas']);
	}//end setUp()

	/**
	 * A plan names its programme and intake year and carries lines and norms.
	 *
	 * @return void
	 */
	public function testShape(): void {
		$plan = $this->schemas['HourPlan'];
		$line = $plan['properties']['lines']['items'];

		self::assertSame('0.1.0', $plan['version']);
		self::assertSame(['programmeId', 'intakeYear', 'durationYears', 'tenant_id'], $plan['required']);
		self::assertSame('Programme', $plan['properties']['programmeId']['$ref']);
		self::assertSame(['courseId', 'programmeYear', 'contactHours'], $line['required']);
		self::assertSame('Course', $line['properties']['courseId']['$ref']);
		self::assertSame(['lesson', 'practical', 'work-placement', 'self-study', 'exam'], $line['properties']['activityKind']['enum']);
		self::assertArrayHasKey('contactHours', $plan['properties']['yearNorms']['items']['properties']);
	}//end testShape()

	/**
	 * Activation is guarded; the lifecycle has no appendOnly.
	 *
	 * @return void
	 */
	public function testLifecycle(): void {
		$plan = $this->schemas['HourPlan'];
		$transitions = $plan['x-openregister-lifecycle']['transitions'];

		self::assertSame('draft', $plan['x-openregister-lifecycle']['initial']);
		self::assertSame('OCA\\Learniq\\Lifecycle\\HourPlanActivationGuard', $transitions['activate']['requires']);
		self::assertSame(['draft', 'active'], [$transitions['activate']['from'], $transitions['activate']['to']]);
		self::assertArrayNotHasKey('appendOnly', $plan['x-openregister'] ?? []);
	}//end testLifecycle()

	/**
	 * Everyone reads a plan; staff groups write one, as for Programme.
	 *
	 * @return void
	 */
	public function testAccess(): void {
		$auth = $this->schemas['HourPlan']['authorization'];

		self::assertSame(['authenticated'], $auth['read']);
		self::assertSame(['instructors', 'team-leads', 'compliance-officers'], $auth['create']);
		self::assertSame($auth['create'], $auth['update']);
	}//end testAccess()

	/**
	 * A cohort knows its year of the programme.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-cohort-knows-which-year-of-its-programme-it-is-in
	 */
	public function testCohortProgrammeYear(): void {
		$cohort = $this->schemas['Cohort'];

		// 0.3.0: the optional capacity (board-data-the-schemas-lacked); programmeYear came with 0.2.0.
		self::assertSame('0.3.0', $cohort['version']);
		self::assertSame('integer', $cohort['properties']['programmeYear']['type']);
		self::assertTrue($cohort['properties']['programmeYear']['nullable']);
	}//end testCohortProgrammeYear()
}//end class
