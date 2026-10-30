<?php

/**
 * Learniq CourseTemplateService unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Course
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

namespace OCA\Learniq\Tests\Unit\Service\Course;

use OCA\Learniq\Exception\CourseTemplateRefusedException;
use OCA\Learniq\Service\Course\CourseTemplateService;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The course tree comes from one lesson query, a template captures names and
 * order only, and a failed instantiation leaves nothing behind.
 */
class CourseTemplateServiceTest extends TestCase {

	/**
	 * findAll configs the service sent.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Objects the writer was asked to create, in order.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $created = [];

	/**
	 * Objects deleted, in order.
	 *
	 * @var list<array{schema: string, id: string}>
	 */
	private array $deleted = [];

	/**
	 * A service over fixed records.
	 *
	 * @param array<string, array<string, mixed>> $records  Records by uuid.
	 * @param list<array<string, mixed>>          $modules  Module rows.
	 * @param list<array<string, mixed>>          $lessons  Lesson rows.
	 * @param int|null                            $failAt   The create call (1-based) that throws, if any.
	 * @param \Throwable|null                     $failWith What that call throws.
	 *
	 * @return CourseTemplateService
	 */
	private function service(
		array $records,
		array $modules = [],
		array $lessons = [],
		?int $failAt = null,
		?\Throwable $failWith = null,
	): CourseTemplateService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id) use ($records) {
				if (isset($records[$id]) === false) {
					throw new DoesNotExistException('missing');
				}

				return OrEntityFactory::make($records[$id]);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($modules, $lessons): array {
				$this->queries[] = $config;
				$rows = $modules;
				if ($config['filters']['schema'] === 'lesson') {
					$rows = $lessons;
				}

				return OrEntityFactory::makeMany($rows);
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid, mixed $register = null, mixed $schema = null): bool {
				$this->deleted[] = ['schema' => (string)$schema, 'id' => $uuid];

				return true;
			}
		);

		$writer = $this->createMock(CoursePackageObjectWriter::class);
		$writer->method('create')->willReturnCallback(
			function (string $schema, array $object) use ($failAt, $failWith): string {
				$call = count($this->created) + 1;
				if ($call === $failAt) {
					throw $failWith ?? new RuntimeException('database down');
				}

				$this->created[] = ['schema' => $schema, 'object' => $object];

				return $schema . '-new-' . $call;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);

		return new CourseTemplateService(
			objectService: $objects,
			writer: $writer,
			l10n: $l10n,
			timeFactory: $time,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * A course with two modules out of order and three lessons.
	 *
	 * @return array{0: array<string, array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
	 */
	private function tree(): array {
		$records = [
			'c-1' => ['id' => 'c-1', 'name' => 'Biology', 'level' => 'vo', 'language' => 'nl', 'tenant_id' => 't-1'],
		];
		$modules = [
			['id' => 'm-2', 'name' => 'Cells', 'order' => 2, 'parentCourseId' => 'c-1'],
			['id' => 'm-x', 'name' => 'Unordered', 'parentCourseId' => 'c-1'],
			['id' => 'm-1', 'name' => 'Plants', 'order' => 1, 'ectsCredits' => 2, 'parentCourseId' => 'c-1'],
		];
		$lessons = [
			['id' => 'l-3', 'courseId' => 'm-1', 'name' => 'Roots', 'order' => 2, 'contentType' => 'text'],
			[
				'id' => 'l-1',
				'courseId' => 'm-1',
				'name' => 'Leaves',
				'order' => 1,
				'contentType' => 'text',
				'blocks' => [
					['blockId' => 'b-1', 'type' => 'richText', 'order' => 1, 'text' => 'Secret lesson text'],
					['blockId' => 'b-2', 'type' => 'material', 'order' => 2, 'materialId' => 'mat-9'],
				],
			],
			['id' => 'l-2', 'courseId' => 'm-2', 'name' => 'Nucleus', 'order' => 1, 'contentType' => 'video'],
			['id' => 'l-9', 'courseId' => 'other-module', 'name' => 'Stray', 'order' => 1, 'contentType' => 'text'],
		];

		return [$records, $modules, $lessons];
	}//end tree()

	/**
	 * Lessons for every module come from one query on the list of module ids.
	 *
	 * @return void
	 */
	public function testStructureReadsAllLessonsInOneQuery(): void {
		[$records, $modules, $lessons] = $this->tree();
		$tree = $this->service(records: $records, modules: $modules, lessons: $lessons)->structure(courseId: 'c-1');

		$lessonQueries = array_values(array_filter($this->queries, static fn (array $q): bool => $q['filters']['schema'] === 'lesson'));
		self::assertCount(1, $lessonQueries);
		self::assertSame(['m-1', 'm-2', 'm-x'], $lessonQueries[0]['filters']['courseId']);
		self::assertCount(2, $this->queries);

		self::assertSame(['m-1', 'm-2', 'm-x'], array_column($tree['modules'], 'id'));
		self::assertSame(['l-1', 'l-3'], array_column($tree['modules'][0]['lessons'], 'id'));
		self::assertSame(['l-2'], array_column($tree['modules'][1]['lessons'], 'id'));
		self::assertSame([], $tree['modules'][2]['lessons']);
	}//end testStructureReadsAllLessonsInOneQuery()

	/**
	 * A course without modules asks for no lessons.
	 *
	 * @return void
	 */
	public function testStructureWithoutModulesSkipsTheLessonQuery(): void {
		[$records] = $this->tree();
		$tree = $this->service(records: $records)->structure(courseId: 'c-1');

		self::assertSame([], $tree['modules']);
		self::assertCount(1, $this->queries);
	}//end testStructureWithoutModulesSkipsTheLessonQuery()

	/**
	 * A course the user cannot read answers 404.
	 *
	 * @return void
	 */
	public function testStructureOfAMissingCourseIsRefused(): void {
		$this->expectException(CourseTemplateRefusedException::class);
		$this->expectExceptionMessage('Course not found.');

		$this->service(records: [])->structure(courseId: 'c-404');
	}//end testStructureOfAMissingCourseIsRefused()

	/**
	 * The template keeps names, order and content types, the course's tenant
	 * and level, and replaces block text with a placeholder.
	 *
	 * @return void
	 */
	public function testSaveAsTemplateCapturesTheSkeletonOnly(): void {
		[$records, $modules, $lessons] = $this->tree();
		$result = $this->service(records: $records, modules: $modules, lessons: $lessons)
			->saveAsTemplate(courseId: 'c-1', name: '  Biology template ', description: '');

		self::assertSame(['id' => 'course-template-new-1'], $result);
		self::assertCount(1, $this->created);
		$template = $this->created[0]['object'];
		self::assertSame('course-template', $this->created[0]['schema']);
		self::assertSame('Biology template', $template['name']);
		self::assertNull($template['description']);
		self::assertSame('vo', $template['level']);
		self::assertSame('t-1', $template['tenant_id']);
		self::assertSame('c-1', $template['sourceCoursesId']);
		self::assertSame(['Plants', 'Cells', 'Unordered'], array_column($template['moduleStructure'], 'name'));
		self::assertSame(2, $template['moduleStructure'][0]['ectsCredits']);

		$leaves = $template['moduleStructure'][0]['lessons'][0];
		self::assertSame('Leaves', $leaves['name']);
		self::assertSame(
			[['blockId' => 'b-1', 'type' => 'richText', 'order' => 1, 'text' => 'Introduction: replace with your own text']],
			$leaves['blocksSkeleton']
		);
		self::assertStringNotContainsString('Secret lesson text', (string)json_encode($template));
		self::assertStringNotContainsString('mat-9', (string)json_encode($template));
	}//end testSaveAsTemplateCapturesTheSkeletonOnly()

	/**
	 * A user OpenRegister does not let create templates gets a 403.
	 *
	 * @return void
	 */
	public function testSaveAsTemplateWithoutPermissionIsForbidden(): void {
		[$records, $modules, $lessons] = $this->tree();
		$service = $this->service(records: $records, modules: $modules, lessons: $lessons, failAt: 1, failWith: new NotAuthorizedException('no'));

		try {
			$service->saveAsTemplate(courseId: 'c-1', name: 'Biology template', description: '');
			self::fail('Expected a refusal.');
		} catch (CourseTemplateRefusedException $refusal) {
			self::assertSame(403, $refusal->getStatus());
			self::assertSame('not-allowed', $refusal->getReason());
		}
	}//end testSaveAsTemplateWithoutPermissionIsForbidden()

	/**
	 * An empty name is refused before anything is read or written.
	 *
	 * @return void
	 */
	public function testAnEmptyNameIsRefused(): void {
		[$records] = $this->tree();
		$service = $this->service(records: $records);

		try {
			$service->instantiate(templateId: 'tpl-1', name: '   ', language: '');
			self::fail('Expected a refusal.');
		} catch (CourseTemplateRefusedException $refusal) {
			self::assertSame(422, $refusal->getStatus());
		}

		self::assertSame([], $this->created);
	}//end testAnEmptyNameIsRefused()

	/**
	 * A template.
	 *
	 * @param bool $withPlan Whether it carries a curriculum plan skeleton.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function templateRecords(bool $withPlan): array {
		$template = [
			'id' => 'tpl-1',
			'name' => 'Biology template',
			'level' => 'vo',
			'tenant_id' => 't-1',
			'moduleStructure' => [
				[
					'key' => 'module-1',
					'name' => 'Plants',
					'order' => 1,
					'lessons' => [
						['key' => 'lesson-1-1', 'name' => 'Leaves', 'order' => 1, 'contentType' => 'text', 'blocksSkeleton' => [['blockId' => 'b-1', 'type' => 'richText', 'order' => 1, 'text' => 'Intro']]],
						['key' => 'lesson-1-2', 'name' => 'Roots', 'order' => 2, 'contentType' => 'text'],
					],
				],
				['key' => 'module-2', 'name' => 'Cells', 'order' => 2, 'lessons' => [['key' => 'lesson-2-1', 'name' => 'Nucleus', 'order' => 1, 'contentType' => 'video']]],
			],
		];
		if ($withPlan === true) {
			$template['curriculumPlanSkeleton'] = ['kind' => 'pta', 'formula' => 'weighted', 'components' => [['code' => 'T1']]];
		}

		return ['tpl-1' => $template];
	}//end templateRecords()

	/**
	 * Instantiating creates the plan, a draft course, its modules and lessons,
	 * all in the template's tenant.
	 *
	 * @return void
	 */
	public function testInstantiateCreatesAnIndependentDraftTree(): void {
		$result = $this->service(records: $this->templateRecords(withPlan: true))
			->instantiate(templateId: 'tpl-1', name: 'Biology 2027', language: 'en');

		self::assertSame(
			['curriculum-plan', 'course', 'course', 'lesson', 'lesson', 'course', 'lesson'],
			array_column($this->created, 'schema')
		);
		self::assertSame(['id' => 'course-new-2'], $result);

		$course = $this->created[1]['object'];
		self::assertSame('Biology 2027', $course['name']);
		self::assertSame('draft', $course['lifecycle']);
		self::assertSame('en', $course['language']);
		self::assertSame('curriculum-plan-new-1', $course['curriculumPlanId']);
		self::assertArrayNotHasKey('parentCourseId', $course);
		self::assertSame('Curriculum plan for Biology 2027', $this->created[0]['object']['name']);

		$module = $this->created[2]['object'];
		self::assertSame('course-new-2', $module['parentCourseId']);
		self::assertSame('Plants', $module['name']);
		self::assertSame($course['code'] . '-module-1', $module['code']);

		self::assertSame('course-new-3', $this->created[3]['object']['courseId']);
		self::assertSame([['blockId' => 'b-1', 'type' => 'richText', 'order' => 1, 'text' => 'Intro']], $this->created[3]['object']['blocks']);
		self::assertSame('course-new-6', $this->created[6]['object']['courseId']);

		foreach ($this->created as $row) {
			self::assertSame('t-1', $row['object']['tenant_id']);
		}
	}//end testInstantiateCreatesAnIndependentDraftTree()

	/**
	 * Without a plan skeleton no plan is created and the course names none.
	 *
	 * @return void
	 */
	public function testInstantiateWithoutAPlanSkeletonCreatesNoPlan(): void {
		$this->service(records: $this->templateRecords(withPlan: false))
			->instantiate(templateId: 'tpl-1', name: 'Biology 2027', language: '');

		self::assertSame('course', $this->created[0]['schema']);
		self::assertArrayNotHasKey('curriculumPlanId', $this->created[0]['object']);
		self::assertSame('nl', $this->created[0]['object']['language']);
	}//end testInstantiateWithoutAPlanSkeletonCreatesNoPlan()

	/**
	 * A failure half way removes everything already created, newest first.
	 *
	 * @return void
	 */
	public function testAFailedInstantiationRemovesWhatItCreated(): void {
		$service = $this->service(records: $this->templateRecords(withPlan: true), failAt: 5);

		try {
			$service->instantiate(templateId: 'tpl-1', name: 'Biology 2027', language: '');
			self::fail('Expected a refusal.');
		} catch (CourseTemplateRefusedException $refusal) {
			self::assertSame(500, $refusal->getStatus());
		}

		self::assertSame(
			[
				['schema' => 'lesson', 'id' => 'lesson-new-4'],
				['schema' => 'course', 'id' => 'course-new-3'],
				['schema' => 'course', 'id' => 'course-new-2'],
				['schema' => 'curriculum-plan', 'id' => 'curriculum-plan-new-1'],
			],
			$this->deleted
		);
	}//end testAFailedInstantiationRemovesWhatItCreated()

	/**
	 * A user who may not create courses gets a 403 and nothing is left.
	 *
	 * @return void
	 */
	public function testInstantiateWithoutPermissionIsForbidden(): void {
		$service = $this->service(records: $this->templateRecords(withPlan: false), failAt: 1, failWith: new NotAuthorizedException('no'));

		try {
			$service->instantiate(templateId: 'tpl-1', name: 'Biology 2027', language: '');
			self::fail('Expected a refusal.');
		} catch (CourseTemplateRefusedException $refusal) {
			self::assertSame(403, $refusal->getStatus());
		}

		self::assertSame([], $this->deleted);
	}//end testInstantiateWithoutPermissionIsForbidden()

	/**
	 * A template the user cannot read answers 404.
	 *
	 * @return void
	 */
	public function testInstantiatingAMissingTemplateIsRefused(): void {
		$this->expectException(CourseTemplateRefusedException::class);
		$this->expectExceptionMessage('Template not found.');

		$this->service(records: [])->instantiate(templateId: 'tpl-404', name: 'Biology 2027', language: '');
	}//end testInstantiatingAMissingTemplateIsRefused()
}//end class
