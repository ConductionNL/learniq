<?php

/**
 * Learniq course template build.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Course
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Course;

use OCA\Learniq\Exception\CourseTemplateRefusedException;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCP\AppFramework\Http;

/**
 * One instantiation of a course template: writes the curriculum plan, the
 * course, its modules and their lessons, and keeps every object it created
 * so a failed instantiation can remove them again.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */
final class CourseTemplateBuild {

	use ListOfArrays;

	/**
	 * Every object created so far, in creation order.
	 *
	 * @var list<array{schema:string,id:string}>
	 */
	public array $created = [];

	/**
	 * The new course's UUID, once created.
	 *
	 * @var string
	 */
	private string $courseId = '';

	/**
	 * The new course's code, once created.
	 *
	 * @var string
	 */
	private string $code = '';

	/**
	 * Constructor.
	 *
	 * @param CoursePackageObjectWriter                                      $writer Creates the objects.
	 * @param array{name:string,level:string,language:string,tenant_id:string} $draft  The new course's name, level, language and tenant.
	 */
	public function __construct(
		private readonly CoursePackageObjectWriter $writer,
		public readonly array $draft,
	) {
	}//end __construct()

	/**
	 * Create the curriculum plan from the template's skeleton, when it has one.
	 *
	 * @param mixed  $skeleton The template's curriculumPlanSkeleton.
	 * @param string $name     The plan's (translated) name.
	 *
	 * @return string|null The plan's UUID, or null when the template has no plan.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	public function createCurriculumPlan(mixed $skeleton, string $name): ?string {
		if (is_array($skeleton) === false || $skeleton === []) {
			return null;
		}

		return $this->create(schema: 'curriculum-plan', object: [
			'name' => $name,
			'kind' => $skeleton['kind'] ?? null,
			'formula' => $skeleton['formula'] ?? null,
			'components' => self::listOf(value: $skeleton['components'] ?? []),
			'periods' => self::listOf(value: $skeleton['periods'] ?? []),
			'passRules' => self::listOf(value: $skeleton['passRules'] ?? []),
			'tenant_id' => $this->draft['tenant_id'],
		]);
	}//end createCurriculumPlan()

	/**
	 * Create the course itself, as a draft.
	 *
	 * @param string      $code             The course code.
	 * @param string|null $curriculumPlanId The curriculum plan's UUID, if any.
	 *
	 * @return string The course's UUID.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	public function createCourse(string $code, ?string $curriculumPlanId): string {
		$this->code     = $code;
		$this->courseId = $this->create(schema: 'course', object: self::withoutNulls(row: [
			'code' => $code,
			'name' => $this->draft['name'],
			'level' => $this->draft['level'],
			'language' => $this->draft['language'],
			'lifecycle' => 'draft',
			'curriculumPlanId' => $curriculumPlanId,
			'tenant_id' => $this->draft['tenant_id'],
		]));

		return $this->courseId;
	}//end createCourse()

	/**
	 * Create every module of the template's module structure, each with its lessons.
	 *
	 * @param mixed $modules The template's moduleStructure.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	public function createModules(mixed $modules): void {
		foreach (self::listOf(value: $modules) as $position => $module) {
			$this->createModule(module: $module, position: $position);
		}
	}//end createModules()

	/**
	 * Create one module under the course, then its lessons.
	 *
	 * @param array<string,mixed> $module   The module skeleton.
	 * @param int                 $position Its position in the structure.
	 *
	 * @return void
	 */
	private function createModule(array $module, int $position): void {
		$moduleId = $this->create(schema: 'course', object: $this->moduleFields(module: $module, position: $position));
		foreach (self::listOf(value: $module['lessons'] ?? []) as $index => $lesson) {
			$this->create(schema: 'lesson', object: $this->lessonFields(lesson: self::withOrder(row: $lesson, order: ($index + 1)), moduleId: $moduleId));
		}
	}//end createModule()

	/**
	 * The fields of a module course.
	 *
	 * @param array<string,mixed> $module   The module skeleton.
	 * @param int                 $position Its position in the structure.
	 *
	 * @return array<string,mixed>
	 */
	private function moduleFields(array $module, int $position): array {
		$key = (string)($module['key'] ?? 'module-' . ($position + 1));
		return self::withoutNulls(row: [
			'code' => $this->code . '-' . $key,
			'name' => (string)($module['name'] ?? ''),
			'level' => $this->draft['level'],
			'language' => $this->draft['language'],
			'lifecycle' => 'draft',
			'parentCourseId' => $this->courseId,
			'order' => $module['order'] ?? ($position + 1),
			'ectsCredits' => $module['ectsCredits'] ?? null,
			'tenant_id' => $this->draft['tenant_id'],
		]);
	}//end moduleFields()

	/**
	 * The fields of a lesson.
	 *
	 * @param array<string,mixed> $lesson   The lesson skeleton, with its order set.
	 * @param string              $moduleId The module's UUID.
	 *
	 * @return array<string,mixed>
	 */
	private function lessonFields(array $lesson, string $moduleId): array {
		return self::withoutNulls(row: [
			'courseId' => $moduleId,
			'name' => (string)($lesson['name'] ?? ''),
			'order' => $lesson['order'],
			'contentType' => $lesson['contentType'] ?? 'text',
			'durationMinutes' => $lesson['durationMinutes'] ?? null,
			'blocks' => self::blocks(lesson: $lesson),
			'tenant_id' => $this->draft['tenant_id'],
		]);
	}//end lessonFields()

	/**
	 * The lesson's content blocks from its blocks skeleton.
	 *
	 * @param array<string,mixed> $lesson The lesson skeleton.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function blocks(array $lesson): array {
		$blocks = [];
		foreach (self::listOf(value: $lesson['blocksSkeleton'] ?? []) as $block) {
			$blocks[] = [
				'blockId' => $block['blockId'] ?? null,
				'type' => $block['type'] ?? 'richText',
				'order' => $block['order'] ?? null,
				'text' => $block['text'] ?? null,
			];
		}

		return $blocks;
	}//end blocks()

	/**
	 * Create one object and keep it for a rollback.
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $object The object.
	 *
	 * @return string The new object's UUID.
	 *
	 * @throws CourseTemplateRefusedException When the writer created nothing.
	 */
	private function create(string $schema, array $object): string {
		$id = $this->writer->create(schema: $schema, object: $object);
		if ($id === null) {
			throw new CourseTemplateRefusedException(
				status: Http::STATUS_INTERNAL_SERVER_ERROR,
				reason: 'not-saved',
				message: 'The course could not be created from this template.'
			);
		}

		$this->created[] = ['schema' => $schema, 'id' => $id];

		return $id;
	}//end create()

	/**
	 * The row with its `order` defaulted when it is missing or null.
	 *
	 * @param array<string,mixed> $row   The row.
	 * @param int                 $order The default order.
	 *
	 * @return array<string,mixed>
	 */
	private static function withOrder(array $row, int $order): array {
		$row['order'] = $row['order'] ?? $order;
		return $row;
	}//end withOrder()

	/**
	 * The row without its null values.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return array<string,mixed>
	 */
	private static function withoutNulls(array $row): array {
		return array_filter($row, static fn ($value): bool => $value !== null);
	}//end withoutNulls()
}//end class
