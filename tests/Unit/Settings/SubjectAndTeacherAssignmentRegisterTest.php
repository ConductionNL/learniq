<?php

/**
 * Unit tests for the `Staff`/`SubjectTeacherAssignment` register-JSON
 * declarations and the additive `Cohort.teacherAssignments` relation.
 *
 * IMPORTANT SCOPE NOTE: this register is read by OpenRegister core, which
 * does not live in this repository. What this suite verifies is the
 * declared SHAPE, mirroring SchoolAndLocationRegisterTest.
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
 * @spec openspec/changes/archive/2026-09-28-subject-and-teacher-assignment/tasks.md#task-1-add-the-staff-and-subjectteacherassignment-schemas
 * @spec openspec/changes/archive/2026-09-28-subject-and-teacher-assignment/tasks.md#task-2-add-cohortteacherassignments
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Staff/SubjectTeacherAssignment schema shape and the
 * Cohort.teacherAssignments addition.
 */
class SubjectAndTeacherAssignmentRegisterTest extends TestCase {

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
		$path = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);

	}//end setUp()

	/**
	 * `Staff` is a plain resource-metadata schema (no lifecycle) declaring
	 * roles, qualifications and working days, with a slug chosen to avoid
	 * both a cross-app collision and the camelCase-to-kebab $ref bug.
	 *
	 * @return void
	 */
	public function testStaffIsAPlainResourceMetadataSchema(): void {
		$schema = $this->config['components']['schemas']['Staff'];

		self::assertSame('staff', $schema['slug']);
		self::assertArrayNotHasKey('x-openregister-lifecycle', $schema);
		self::assertSame(['ncUserId', 'tenant_id'], $schema['required']);

		// The original seven tags stay first and in order; later changes may
		// append tags (staff-role-vocabulary-extension added seven), so this
		// asserts a floor, not the exact list.
		$roles = $schema['properties']['roles'];
		self::assertSame(
			['teacher', 'mentor', 'coordinator', 'teaching-assistant', 'support-staff', 'administrator', 'other'],
			array_slice($roles['items']['enum'], 0, 7)
		);

		$workingDays = $schema['properties']['workingDays'];
		self::assertSame(
			['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
			$workingDays['items']['enum']
		);

	}//end testStaffIsAPlainResourceMetadataSchema()

	/**
	 * `SubjectTeacherAssignment` declares cohortId/courseId/teacherId, no
	 * lifecycle, and its slug is the plain lowercase concatenation of its
	 * PascalCase name (no dashes), matching the $ref-by-lowercased-slug
	 * convention this register relies on.
	 *
	 * @return void
	 */
	public function testSubjectTeacherAssignmentDeclaresTheJoinFields(): void {
		$schema = $this->config['components']['schemas']['SubjectTeacherAssignment'];

		self::assertSame('subjectteacherassignment', $schema['slug']);
		self::assertArrayNotHasKey('x-openregister-lifecycle', $schema);
		self::assertSame(['cohortId', 'courseId', 'teacherId', 'tenant_id'], $schema['required']);
		self::assertSame('Cohort', $schema['properties']['cohortId']['$ref']);
		self::assertSame('Course', $schema['properties']['courseId']['$ref']);

	}//end testSubjectTeacherAssignmentDeclaresTheJoinFields()

	/**
	 * `Cohort.teacherAssignments` is additive (default `[]`), each entry
	 * requires `teacherId`/`role`, `role` is `primary` or `duo-partner`,
	 * and the existing `teacherIds`/`required` list is untouched.
	 *
	 * @return void
	 */
	public function testCohortTeacherAssignmentsIsAdditiveWithDuoPartnerRole(): void {
		$cohort = $this->config['components']['schemas']['Cohort'];

		$teacherAssignments = $cohort['properties']['teacherAssignments'];
		self::assertSame('array', $teacherAssignments['type']);
		self::assertSame([], $teacherAssignments['default']);

		$item = $teacherAssignments['items'];
		self::assertSame(['teacherId', 'role'], $item['required']);
		self::assertSame(['primary', 'duo-partner'], $item['properties']['role']['enum']);

		// Purely additive: teacherIds and the required list are untouched.
		self::assertArrayHasKey('teacherIds', $cohort['properties']);
		self::assertSame(['name', 'period', 'academicYear', 'tenant_id'], $cohort['required']);

	}//end testCohortTeacherAssignmentsIsAdditiveWithDuoPartnerRole()

	/**
	 * The primary school example set exercises the duo-partner day split on
	 * "Groep 5/6" and two independent SubjectTeacherAssignments on it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-primary-school-set-is-one-consistent-school
	 */
	public function testSeedFixturesExerciseDuoPartnerSplitAndSubjectAssignments(): void {
		$cohort = self::poObject(schema: 'cohort', field: 'name', value: 'Groep 5/6');

		$assignments = $cohort['teacherAssignments'];
		self::assertCount(2, $assignments);
		$roles = array_column($assignments, 'role');
		sort($roles);
		self::assertSame(['duo-partner', 'primary'], $roles);

		$primary = array_values(array_filter($assignments, static fn (array $a): bool => $a['role'] === 'primary'))[0];
		$duoPartner = array_values(array_filter($assignments, static fn (array $a): bool => $a['role'] === 'duo-partner'))[0];
		self::assertEmpty(array_intersect($primary['days'], $duoPartner['days']));

		$staffIds = array_column(self::poObjects(schema: 'staff'), 'ncUserId');
		self::assertContains($primary['teacherId'], $staffIds);
		self::assertContains($duoPartner['teacherId'], $staffIds);

		$onCohort = array_values(array_filter(
			self::poObjects(schema: 'subjectteacherassignment'),
			static fn (array $a): bool => $a['cohortId'] === $cohort['uuid']
		));
		self::assertGreaterThanOrEqual(2, count($onCohort));
		self::assertGreaterThanOrEqual(2, count(array_unique(array_column($onCohort, 'courseId'))));
		self::assertGreaterThanOrEqual(2, count(array_unique(array_column($onCohort, 'teacherId'))));

	}//end testSeedFixturesExerciseDuoPartnerSplitAndSubjectAssignments()

	/**
	 * The objects of one schema in the primary school example set, where the
	 * curated primary school seeds moved to (segment-example-datasets-po).
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function poObjects(string $schema): array {
		$path = __DIR__ . '/../../../lib/Settings/profiles/po.json';
		$set  = json_decode((string)file_get_contents($path), true);

		return ($set['x-openregister']['seedData']['objects'][$schema] ?? []);
	}//end poObjects()

	/**
	 * The first object of a schema in the example set whose field equals a value.
	 *
	 * @param string $schema The schema slug.
	 * @param string $field  The field to match.
	 * @param mixed  $value  The value it must hold.
	 *
	 * @return array<string, mixed>
	 */
	private static function poObject(string $schema, string $field, mixed $value): array {
		foreach (self::poObjects(schema: $schema) as $object) {
			if (($object[$field] ?? null) === $value) {
				return $object;
			}
		}

		self::fail('No ' . $schema . ' with ' . $field . ' = ' . json_encode($value) . ' in the primary school example set.');
	}//end poObject()
}//end class
