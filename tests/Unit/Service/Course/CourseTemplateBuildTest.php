<?php

/**
 * CourseTemplateBuild unit tests: the objects a course built from a template creates.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Course
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Course;

use OCA\Learniq\Exception\CourseTemplateRefusedException;
use OCA\Learniq\Service\Course\CourseTemplateBuild;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Building a course from a template over a mocked object writer.
 */
#[CoversClass(CourseTemplateBuild::class)]
#[UsesClass(CourseTemplateRefusedException::class)]
class CourseTemplateBuildTest extends TestCase {

	/**
	 * Objects the writer was asked to create, by schema.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $written = [];

	/**
	 * A build over a writer that numbers its ids, refusing the given schema.
	 *
	 * @param string|null $refuse A schema the writer refuses.
	 * @param array|null  $draft  The draft fields.
	 *
	 * @return CourseTemplateBuild
	 */
	private function build(?string $refuse = null, ?array $draft = null): CourseTemplateBuild {
		$writer = $this->createMock(CoursePackageObjectWriter::class);
		$writer->method('create')->willReturnCallback(
			function (string $schema, array $object) use ($refuse): ?string {
				if ($schema === $refuse) {
					return null;
				}

				$this->written[] = [$schema, $object];

				return $schema . '-' . count($this->written);
			}
		);

		return new CourseTemplateBuild(
			writer: $writer,
			draft: ($draft ?? ['name' => 'Welding', 'level' => 'mbo-2', 'language' => 'nl', 'tenant_id' => 't-1'])
		);
	}//end build()

	/**
	 * A curriculum plan is created from a skeleton, with only list entries kept.
	 *
	 * @return void
	 */
	public function testACurriculumPlanIsCreatedFromTheSkeleton(): void {
		$build = $this->build();

		$id = $build->createCurriculumPlan(skeleton: ['kind' => 'weighted', 'components' => [['id' => 'c-1'], 'x'], 'passRules' => 'none'], name: 'Welding plan');

		self::assertSame('curriculum-plan-1', $id);
		self::assertSame(
			[['curriculum-plan', ['name' => 'Welding plan', 'kind' => 'weighted', 'formula' => null, 'components' => [['id' => 'c-1']], 'periods' => [], 'passRules' => [], 'tenant_id' => 't-1']]],
			$this->written
		);
		self::assertSame([['schema' => 'curriculum-plan', 'id' => 'curriculum-plan-1']], $build->created);
	}//end testACurriculumPlanIsCreatedFromTheSkeleton()

	/**
	 * Without a skeleton no plan is created.
	 *
	 * @return void
	 */
	public function testWithoutASkeletonNoPlanIsCreated(): void {
		$build = $this->build();

		self::assertNull($build->createCurriculumPlan(skeleton: [], name: 'x'));
		self::assertNull($build->createCurriculumPlan(skeleton: 'plan', name: 'x'));
		self::assertSame([], $this->written);
	}//end testWithoutASkeletonNoPlanIsCreated()

	/**
	 * The course is a draft carrying the draft's fields; null fields are left out.
	 *
	 * @return void
	 */
	public function testTheCourseIsADraftWithoutNullFields(): void {
		$build = $this->build(draft: ['name' => 'Welding', 'level' => null, 'language' => 'nl', 'tenant_id' => 't-1']);

		self::assertSame('course-1', $build->createCourse(code: 'WLD', curriculumPlanId: null));
		self::assertSame(['course', ['code' => 'WLD', 'name' => 'Welding', 'language' => 'nl', 'lifecycle' => 'draft', 'tenant_id' => 't-1']], $this->written[0]);
	}//end testTheCourseIsADraftWithoutNullFields()

	/**
	 * Modules become child courses coded after the course; lessons hang under their module with blocks.
	 *
	 * @return void
	 */
	public function testModulesBecomeChildCoursesWithTheirLessons(): void {
		$build = $this->build();
		$build->createCourse(code: 'WLD', curriculumPlanId: 'plan-1');

		$build->createModules(modules: [
			[
				'key' => 'module-1',
				'name' => 'Basics',
				'ectsCredits' => 3,
				'lessons' => [
					['name' => 'Intro', 'blocksSkeleton' => [['blockId' => 'b-1', 'text' => 'Write here'], 'x']],
					['name' => 'Quiz', 'order' => 5, 'contentType' => 'quiz', 'durationMinutes' => 15],
				],
			],
			'not a module',
			['name' => 'Advanced', 'order' => 4],
		]);

		self::assertSame(
			['course', ['code' => 'WLD-module-1', 'name' => 'Basics', 'level' => 'mbo-2', 'language' => 'nl', 'lifecycle' => 'draft', 'parentCourseId' => 'course-1', 'order' => 1, 'ectsCredits' => 3, 'tenant_id' => 't-1']],
			$this->written[1]
		);
		self::assertSame(
			['lesson', ['courseId' => 'course-2', 'name' => 'Intro', 'order' => 1, 'contentType' => 'text', 'blocks' => [['blockId' => 'b-1', 'type' => 'richText', 'order' => null, 'text' => 'Write here']], 'tenant_id' => 't-1']],
			$this->written[2]
		);
		self::assertSame(
			['lesson', ['courseId' => 'course-2', 'name' => 'Quiz', 'order' => 5, 'contentType' => 'quiz', 'durationMinutes' => 15, 'blocks' => [], 'tenant_id' => 't-1']],
			$this->written[3]
		);
		// The dropped entry takes no position: the next module is the second.
		self::assertSame('WLD-module-2', $this->written[4][1]['code']);
		self::assertSame(4, $this->written[4][1]['order']);
		self::assertSame(['course', 'course', 'lesson', 'lesson', 'course'], array_column($build->created, 'schema'));
	}//end testModulesBecomeChildCoursesWithTheirLessons()

	/**
	 * An object the writer cannot save stops the build with a 500.
	 *
	 * @return void
	 */
	public function testAnUnsavedObjectStopsTheBuild(): void {
		$build = $this->build(refuse: 'lesson');
		$build->createCourse(code: 'WLD', curriculumPlanId: null);

		try {
			$build->createModules(modules: [['name' => 'M', 'lessons' => [['name' => 'L1'], ['name' => 'L2']]]]);
			self::fail('An unsaved lesson must stop the build.');
		} catch (CourseTemplateRefusedException $exception) {
			self::assertSame(500, $exception->getStatus());
			self::assertSame('not-saved', $exception->getReason());
		}

		self::assertSame(['course', 'course'], array_column($build->created, 'schema'));
	}//end testAnUnsavedObjectStopsTheBuild()
}//end class
