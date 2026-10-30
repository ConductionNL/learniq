<?php

/**
 * Learniq Course Template Service
 *
 * Reads a course tree (course, modules, lessons) in three queries, captures it
 * as a CourseTemplate, and instantiates a CourseTemplate into a new course
 * tree. Every read and write runs with the signed-in user's own OpenRegister
 * permissions; the tenant comes from the source course or the template, never
 * from the caller. An instantiation that fails half way removes what it
 * already created.
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
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Course tree reads, Save as template and New course from template.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */
class CourseTemplateService {

	private const REGISTER = 'learniq';

	/**
	 * Rows fetched per page when reading modules or lessons.
	 */
	private const PAGE = 500;

	/**
	 * Longest template or course name accepted.
	 */
	private const MAX_NAME_LENGTH = 255;

	/**
	 * Language of a new course when the caller names none.
	 */
	private const DEFAULT_LANGUAGE = 'nl';

	/**
	 * Constructor.
	 *
	 * @param ObjectService             $objectService OpenRegister reads and deletes.
	 * @param CoursePackageObjectWriter $writer      OpenRegister creates.
	 * @param IL10N                     $l10n        Placeholder texts.
	 * @param ITimeFactory              $timeFactory The suffix of a new course code.
	 * @param LoggerInterface           $logger      Failed rollbacks.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CoursePackageObjectWriter $writer,
		private readonly IL10N $l10n,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * A course with its modules and each module's lessons, both sorted by
	 * `order` with a missing order last. Lessons for every module come from
	 * one filtered query.
	 *
	 * @param string $courseId The course uuid.
	 *
	 * @return array{course: array<string, mixed>, modules: list<array<string, mixed>>}
	 *
	 * @throws CourseTemplateRefusedException When the course does not exist or the user may not read it.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	public function structure(string $courseId): array {
		$course = $this->load(schema: 'course', id: $courseId, what: 'course');
		$courseUuid = (string)($course['id'] ?? $courseId);

		$modules = $this->readAll(schema: 'course', filters: ['parentCourseId' => $courseUuid]);
		usort($modules, static fn (array $left, array $right): int => self::compareByOrder(left: $left, right: $right));

		$moduleIds = array_values(array_filter(array_map(static fn (array $module): string => (string)($module['id'] ?? ''), $modules)));
		$lessonsByModule = array_fill_keys($moduleIds, []);
		if ($moduleIds !== []) {
			foreach ($this->readAll(schema: 'lesson', filters: ['courseId' => $moduleIds]) as $lesson) {
				$moduleId = (string)($lesson['courseId'] ?? '');
				if (array_key_exists($moduleId, $lessonsByModule) === true) {
					$lessonsByModule[$moduleId][] = $lesson;
				}
			}
		}

		foreach ($modules as $index => $module) {
			$lessons = $lessonsByModule[(string)($module['id'] ?? '')] ?? [];
			usort($lessons, static fn (array $left, array $right): int => self::compareByOrder(left: $left, right: $right));
			$modules[$index]['lessons'] = $lessons;
		}

		return ['course' => $course, 'modules' => $modules];
	}//end structure()

	/**
	 * Capture a course tree as a new CourseTemplate: module and lesson names,
	 * order and content type, and a placeholder for each rich text block. No
	 * live references and no learner data are copied, and the course is left
	 * unchanged.
	 *
	 * @param string $courseId    The source course uuid.
	 * @param string $name        The template name.
	 * @param string $description The template description; empty for none.
	 *
	 * @return array{id: string}
	 *
	 * @throws CourseTemplateRefusedException When the name is empty, the course is missing, or the user may not create templates.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	public function saveAsTemplate(string $courseId, string $name, string $description): array {
		$name = $this->validName(name: $name);
		$tree = $this->structure(courseId: $courseId);
		$course = $tree['course'];

		$description = trim($description);
		if ($description === '') {
			$description = null;
		}

		$template = [
			'name' => $name,
			'description' => $description,
			'level' => (string)($course['level'] ?? ''),
			'sourceCoursesId' => (string)($course['id'] ?? $courseId),
			'moduleStructure' => (new CourseTemplateSkeleton())->moduleStructure(
				modules: $tree['modules'],
				placeholder: $this->l10n->t('Introduction: replace with your own text')
			),
			'tenant_id' => (string)($course['tenant_id'] ?? ''),
		];

		$id = $this->guardedCreate(schema: 'course-template', object: $template);
		if ($id === null) {
			throw new CourseTemplateRefusedException(
				status: Http::STATUS_INTERNAL_SERVER_ERROR,
				reason: 'not-saved',
				message: 'The template could not be saved.'
			);
		}

		return ['id' => $id];
	}//end saveAsTemplate()

	/**
	 * Create a new, independent course tree from a CourseTemplate: a draft
	 * course, one module per module entry, one lesson per lesson entry, and a
	 * curriculum plan when the template carries one. When any write fails,
	 * everything this call created is removed again.
	 *
	 * @param string $templateId The template uuid.
	 * @param string $name       The new course name.
	 * @param string $language   The new course language; empty for the default.
	 *
	 * @return array{id: string}
	 *
	 * @throws CourseTemplateRefusedException When the name is empty, the template is missing, or a write is refused.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	public function instantiate(string $templateId, string $name, string $language): array {
		$name = $this->validName(name: $name);
		$template = $this->load(schema: 'course-template', id: $templateId, what: 'template');
		$tenantId = (string)($template['tenant_id'] ?? '');
		$level = (string)($template['level'] ?? '');
		$language = trim($language);
		if ($language === '') {
			$language = self::DEFAULT_LANGUAGE;
		}

		$created = [];
		$courseId = '';
		try {
			$curriculumPlanId = $this->createCurriculumPlan(template: $template, name: $name, tenantId: $tenantId, created: $created);

			$code = $this->courseCode(name: $name);
			$courseId = $this->createTracked(
				schema: 'course',
				object: array_filter(
					[
						'code' => $code,
						'name' => $name,
						'level' => $level,
						'language' => $language,
						'lifecycle' => 'draft',
						'curriculumPlanId' => $curriculumPlanId,
						'tenant_id' => $tenantId,
					],
					static fn ($value): bool => $value !== null
				),
				created: $created
			);

			foreach ($this->listOf(value: $template['moduleStructure'] ?? []) as $position => $module) {
				$this->createModule(
					module: $module,
					position: $position,
					parent: ['id' => $courseId, 'code' => $code, 'level' => $level, 'language' => $language, 'tenant_id' => $tenantId],
					created: $created
				);
			}
		} catch (Throwable $failure) {
			$this->rollback(created: $created);
			throw $this->refusalFor(failure: $failure, fallback: 'The course could not be created from this template.');
		}//end try

		return ['id' => $courseId];
	}//end instantiate()

	/**
	 * Create the template's curriculum plan, when it carries one.
	 *
	 * @param array<string, mixed>                          $template The template.
	 * @param string                                        $name     The new course name.
	 * @param string                                        $tenantId The tenant.
	 * @param list<array{schema: string, id: string}>       $created  Objects created so far, appended to.
	 *
	 * @return string|null The plan uuid, or null when the template has no plan.
	 */
	private function createCurriculumPlan(array $template, string $name, string $tenantId, array &$created): ?string {
		$plan = $template['curriculumPlanSkeleton'] ?? null;
		if (is_array($plan) === false || $plan === []) {
			return null;
		}

		return $this->createTracked(
			schema: 'curriculum-plan',
			object: [
				'name' => $this->l10n->t('Curriculum plan for %s', [$name]),
				'kind' => $plan['kind'] ?? null,
				'formula' => $plan['formula'] ?? null,
				'components' => $this->listOf(value: $plan['components'] ?? []),
				'periods' => $this->listOf(value: $plan['periods'] ?? []),
				'passRules' => $this->listOf(value: $plan['passRules'] ?? []),
				'tenant_id' => $tenantId,
			],
			created: $created
		);
	}//end createCurriculumPlan()

	/**
	 * Create one module and its lessons.
	 *
	 * @param array<string, mixed>                    $module   The module entry.
	 * @param int                                     $position Its index in the template.
	 * @param array<string, string>                   $parent   The new course: id, code, level, language, tenant_id.
	 * @param list<array{schema: string, id: string}> $created  Objects created so far, appended to.
	 *
	 * @return void
	 */
	private function createModule(array $module, int $position, array $parent, array &$created): void {
		$key = (string)($module['key'] ?? 'module-' . ($position + 1));
		$moduleId = $this->createTracked(
			schema: 'course',
			object: array_filter(
				[
					'code' => $parent['code'] . '-' . $key,
					'name' => (string)($module['name'] ?? ''),
					'level' => $parent['level'],
					'language' => $parent['language'],
					'lifecycle' => 'draft',
					'parentCourseId' => $parent['id'],
					'order' => $module['order'] ?? ($position + 1),
					'ectsCredits' => $module['ectsCredits'] ?? null,
					'tenant_id' => $parent['tenant_id'],
				],
				static fn ($value): bool => $value !== null
			),
			created: $created
		);

		foreach ($this->listOf(value: $module['lessons'] ?? []) as $index => $lesson) {
			$blocks = [];
			foreach ($this->listOf(value: $lesson['blocksSkeleton'] ?? []) as $block) {
				$blocks[] = [
					'blockId' => $block['blockId'] ?? null,
					'type' => $block['type'] ?? 'richText',
					'order' => $block['order'] ?? null,
					'text' => $block['text'] ?? null,
				];
			}

			$this->createTracked(
				schema: 'lesson',
				object: array_filter(
					[
						'courseId' => $moduleId,
						'name' => (string)($lesson['name'] ?? ''),
						'order' => $lesson['order'] ?? ($index + 1),
						'contentType' => $lesson['contentType'] ?? 'text',
						'durationMinutes' => $lesson['durationMinutes'] ?? null,
						'blocks' => $blocks,
						'tenant_id' => $parent['tenant_id'],
					],
					static fn ($value): bool => $value !== null
				),
				created: $created
			);
		}//end foreach
	}//end createModule()

	/**
	 * Create an object, record it for rollback, and return its uuid.
	 *
	 * @param string                                  $schema  The schema slug.
	 * @param array<string, mixed>                    $object  The payload.
	 * @param list<array{schema: string, id: string}> $created Objects created so far, appended to.
	 *
	 * @return string The uuid.
	 *
	 * @throws CourseTemplateRefusedException When the object was not created.
	 */
	private function createTracked(string $schema, array $object, array &$created): string {
		$id = $this->writer->create(schema: $schema, object: $object);
		if ($id === null) {
			throw new CourseTemplateRefusedException(
				status: Http::STATUS_INTERNAL_SERVER_ERROR,
				reason: 'not-saved',
				message: 'The course could not be created from this template.'
			);
		}

		$created[] = ['schema' => $schema, 'id' => $id];

		return $id;
	}//end createTracked()

	/**
	 * Create one object, answering a permission refusal with a 403.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The payload.
	 *
	 * @return string|null The uuid.
	 *
	 * @throws CourseTemplateRefusedException When the user may not create it.
	 */
	private function guardedCreate(string $schema, array $object): ?string {
		try {
			return $this->writer->create(schema: $schema, object: $object);
		} catch (Throwable $failure) {
			throw $this->refusalFor(failure: $failure, fallback: 'The template could not be saved.');
		}
	}//end guardedCreate()

	/**
	 * Remove what an instantiation created, newest first.
	 *
	 * @param list<array{schema: string, id: string}> $created The created objects.
	 *
	 * @return void
	 */
	private function rollback(array $created): void {
		foreach (array_reverse($created) as $object) {
			try {
				$this->objectService->deleteObject(uuid: $object['id'], register: self::REGISTER, schema: $object['schema'], _rbac: true);
			} catch (Throwable $failure) {
				$this->logger->warning(
					'[CourseTemplateService] Could not remove {schema} {id} after a failed instantiation: {error}',
					['schema' => $object['schema'], 'id' => $object['id'], 'error' => $failure->getMessage()]
				);
			}
		}
	}//end rollback()

	/**
	 * The refusal to answer a failed write with.
	 *
	 * @param Throwable $failure  The failure.
	 * @param string    $fallback The message for anything but a refusal or a permission denial.
	 *
	 * @return CourseTemplateRefusedException
	 */
	private function refusalFor(Throwable $failure, string $fallback): CourseTemplateRefusedException {
		if ($failure instanceof CourseTemplateRefusedException) {
			return $failure;
		}

		if ($failure instanceof NotAuthorizedException) {
			return new CourseTemplateRefusedException(
				status: Http::STATUS_FORBIDDEN,
				reason: 'not-allowed',
				message: 'You are not allowed to do this.'
			);
		}

		$this->logger->error('[CourseTemplateService] ' . $fallback . ' {error}', ['error' => $failure->getMessage()]);

		return new CourseTemplateRefusedException(status: Http::STATUS_INTERNAL_SERVER_ERROR, reason: 'not-saved', message: $fallback);
	}//end refusalFor()

	/**
	 * One object the user may read.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 * @param string $what   `course` or `template`, for the refusal.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws CourseTemplateRefusedException When it does not exist or the user may not read it.
	 */
	private function load(string $schema, string $id, string $what): array {
		$missing = new CourseTemplateRefusedException(
			status: Http::STATUS_NOT_FOUND,
			reason: $what . '-not-found',
			message: ucfirst($what) . ' not found.'
		);

		try {
			$record = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: true, _render: false)?->jsonSerialize();
		} catch (DoesNotExistException) {
			throw $missing;
		} catch (NotAuthorizedException) {
			throw $missing;
		}

		if (is_array($record) === false || $record === []) {
			throw $missing;
		}

		return $record;
	}//end load()

	/**
	 * Every row matching the filters that the user may read, page by page.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Property filters; a list value matches any of its items.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function readAll(string $schema, array $filters): array {
		$rows = [];
		$offset = 0;
		do {
			$page = $this->objectService->findAll(
				config: [
					'filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters),
					'limit' => self::PAGE,
					'offset' => $offset,
				],
				_rbac: true
			);
			foreach ($page as $row) {
				$value = $row;
				if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
					$value = $row->jsonSerialize();
				}

				if (is_array($value) === true) {
					$rows[] = $value;
				}
			}

			$offset += self::PAGE;
			$full = count($page) === self::PAGE;
		} while ($full === true);

		return $rows;
	}//end readAll()

	/**
	 * A trimmed, non-empty name of bounded length.
	 *
	 * @param string $name The name as given.
	 *
	 * @return string
	 *
	 * @throws CourseTemplateRefusedException When it is empty or too long.
	 */
	private function validName(string $name): string {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new CourseTemplateRefusedException(
				status: Http::STATUS_UNPROCESSABLE_ENTITY,
				reason: 'invalid-name',
				message: 'Give a name of at most 255 characters.'
			);
		}

		return $name;
	}//end validName()

	/**
	 * A unique code for a new course, derived from its name.
	 *
	 * @param string $name The course name.
	 *
	 * @return string
	 */
	private function courseCode(string $name): string {
		$stem = strtoupper((string)preg_replace('/\s+/u', '-', mb_substr($name, 0, 12)));

		return $stem . '-' . $this->timeFactory->getTime() . '-' . bin2hex(random_bytes(2));
	}//end courseCode()

	/**
	 * The array entries of a list value; anything else is an empty list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, 'is_array'));
	}//end listOf()

	/**
	 * Order by `order`, a missing order last.
	 *
	 * @param array<string, mixed> $left  One row.
	 * @param array<string, mixed> $right The other row.
	 *
	 * @return int
	 */
	private static function compareByOrder(array $left, array $right): int {
		$leftOrder = $left['order'] ?? null;
		$rightOrder = $right['order'] ?? null;
		if ($leftOrder === null || $rightOrder === null) {
			return ($leftOrder === null) <=> ($rightOrder === null);
		}

		return (int)$leftOrder <=> (int)$rightOrder;
	}//end compareByOrder()
}//end class
