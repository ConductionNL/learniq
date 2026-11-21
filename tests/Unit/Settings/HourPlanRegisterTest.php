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
		self::assertContains(needle: 'hour-plan', haystack: $config['components']['registers']['learniq']['schemas']);
	}//end setUp()

	/**
	 * A plan names its programme and intake year and carries lines and norms.
	 *
	 * @return void
	 */
	public function testShape(): void {
		$plan = $this->schemas['HourPlan'];
		$line = $plan['properties']['lines']['items'];

		self::assertSame(expected: '0.1.0', actual: $plan['version']);
		self::assertSame(expected: ['programmeId', 'intakeYear', 'durationYears', 'tenant_id'], actual: $plan['required']);
		self::assertSame(expected: 'Programme', actual: $plan['properties']['programmeId']['$ref']);
		self::assertSame(expected: ['courseId', 'programmeYear', 'contactHours'], actual: $line['required']);
		self::assertSame(expected: 'Course', actual: $line['properties']['courseId']['$ref']);
		self::assertSame(
			expected: ['lesson', 'practical', 'work-placement', 'self-study', 'exam'],
			actual: $line['properties']['activityKind']['enum']
		);
		self::assertArrayHasKey(key: 'contactHours', array: $plan['properties']['yearNorms']['items']['properties']);
	}//end testShape()

	/**
	 * Activation is guarded; the lifecycle has no appendOnly.
	 *
	 * @return void
	 */
	public function testLifecycle(): void {
		$plan = $this->schemas['HourPlan'];
		$transitions = $plan['x-openregister-lifecycle']['transitions'];

		self::assertSame(expected: 'draft', actual: $plan['x-openregister-lifecycle']['initial']);
		self::assertSame(expected: 'OCA\\Learniq\\Lifecycle\\HourPlanActivationGuard', actual: $transitions['activate']['requires']);
		self::assertSame(expected: ['draft', 'active'], actual: [$transitions['activate']['from'], $transitions['activate']['to']]);
		self::assertArrayNotHasKey(key: 'appendOnly', array: $plan['x-openregister'] ?? []);
	}//end testLifecycle()

	/**
	 * Everyone reads a plan; staff groups write one, as for Programme.
	 *
	 * @return void
	 */
	public function testAccess(): void {
		$auth = $this->schemas['HourPlan']['authorization'];

		self::assertSame(expected: ['authenticated'], actual: $auth['read']);
		self::assertSame(expected: ['instructors', 'team-leads', 'compliance-officers'], actual: $auth['create']);
		self::assertSame(expected: $auth['create'], actual: $auth['update']);
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

		// 0.6.0: the class roster sync fields (enrolment-sync-classes-from-student-administration);
		// 0.5.1: the optional substituteUntil (employer-substitutes-a-colleague);
		// 0.5.0: the manual status move and the course day log, 0.4.0: minimum, decision and
		// course day status (training-provider-course-days); 0.3.0 the optional capacity
		// (board-data-the-schemas-lacked); programmeYear came with 0.2.0.
		self::assertTrue(condition: version_compare($cohort['version'], '0.6.0', '>='));
		self::assertSame(expected: 'integer', actual: $cohort['properties']['programmeYear']['type']);
		self::assertTrue(condition: $cohort['properties']['programmeYear']['nullable']);
	}//end testCohortProgrammeYear()
}//end class
