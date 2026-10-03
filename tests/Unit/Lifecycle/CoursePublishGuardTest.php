<?php

/**
 * Learniq CoursePublishGuard unit tests.
 *
 * Regression coverage for delegate-ooapi-to-opencatalogi tasks.md#task-4.1: the
 * OOAPI publication-contract spec sync does not alter Course's existing
 * `publish` transition guard behavior — it only changes what downstream
 * consumers (opencatalogi/openconnector) are told to expect once a Course is
 * published. This test asserts CoursePublishGuard::check() still behaves
 * exactly as before this change: a Course may only publish once it has at
 * least one published Lesson.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/archive/2026-07-13-delegate-ooapi-to-opencatalogi/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Lifecycle\CoursePublishGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CoursePublishGuard::check() — the Course `draft -> published` transition.
 */
class CoursePublishGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * A Course with at least one published Lesson is allowed to publish —
	 * unchanged by the OOAPI publication-contract spec sync.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-13-delegate-ooapi-to-opencatalogi/tasks.md#task-4.1
	 */
	public function testCourseWithPublishedLessonIsAllowedToPublish(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([['id' => 'lesson-1', 'lifecycle' => 'published']]);

		$guard = new CoursePublishGuard($objectService, $this->createMock(LoggerInterface::class));
		$object = ['id' => 'course-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'];

		self::assertAllowed($guard->check($object, 'publish', ''));

	}//end testCourseWithPublishedLessonIsAllowedToPublish()

	/**
	 * A Course with no published Lesson is blocked from publishing —
	 * unchanged by the OOAPI publication-contract spec sync.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-13-delegate-ooapi-to-opencatalogi/tasks.md#task-4.1
	 */
	public function testCourseWithoutPublishedLessonIsBlocked(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		$guard = new CoursePublishGuard($objectService, $this->createMock(LoggerInterface::class));
		$object = ['id' => 'course-2', 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'];

		self::assertDenied($guard->check($object, 'publish', ''));

	}//end testCourseWithoutPublishedLessonIsBlocked()

	/**
	 * A transition context with no course id blocks the publish outright.
	 *
	 * @return void
	 */
	public function testMissingCourseIdBlocksPublish(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('findAll');

		$guard = new CoursePublishGuard($objectService, $this->createMock(LoggerInterface::class));
		$object = ['lifecycle' => 'published'];

		self::assertDenied($guard->check($object, 'publish', ''));

	}//end testMissingCourseIdBlocksPublish()

	/**
	 * The Lesson lookup is scoped to the Course's own tenant — H1 isolation,
	 * unaffected by the OOAPI contract.
	 *
	 * @return void
	 */
	public function testLessonLookupIsScopedToTenant(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->once())
			->method('findAll')
			->with(
				self::callback(
					function (array $params): bool {
						return ($params['filters']['tenant_id'] ?? null) === 'tenant-b'
							&& ($params['filters']['courseId'] ?? null) === 'course-3'
							&& ($params['filters']['schema'] ?? null) === 'lesson';
					}
				)
			)
			->willReturn([['id' => 'lesson-9', 'lifecycle' => 'published']]);

		$guard = new CoursePublishGuard($objectService, $this->createMock(LoggerInterface::class));
		$object = ['id' => 'course-3', 'tenant_id' => 'tenant-b', 'lifecycle' => 'published'];

		self::assertAllowed($guard->check($object, 'publish', ''));

	}//end testLessonLookupIsScopedToTenant()

	/**
	 * A published Lesson on the Course lets it publish, read the way OpenRegister reads it (#1109).
	 *
	 * The store behind the ObjectService double answers like OpenRegister:
	 * the register and schema count only inside `filters`, and a filter on a
	 * property the shipped Lesson schema does not declare matches nothing.
	 * Before #1047 the guard named its register and schema at the top level
	 * of the config, OpenRegister read the Course's own table instead, and
	 * `courseId` (which Course does not declare) matched nothing, so no Course
	 * could publish. Run against that guard, this test is red.
	 *
	 * The tenant key travels as `tenant_id`, whole: the underscore split
	 * #1109 suspected happens only on the REST query path, not in findAll().
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md
	 */
	public function testAPublishedLessonLetsTheCoursePublishThroughARegisterFaithfulRead(): void {
		$store = new RegisterFaithfulStore();
		$store->rows['lesson'] = [
			['id' => 'lesson-1', 'courseId' => 'course-7', 'lifecycle' => 'published', 'tenant_id' => 'tenant-a'],
		];

		$guard = new CoursePublishGuard($this->storeBackedObjectService(store: $store), $this->createMock(LoggerInterface::class));
		$object = ['id' => 'course-7', 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'];

		self::assertAllowed($guard->check($object, 'publish', ''));

		$filters = ($store->reads[0]['config']['filters'] ?? []);
		self::assertSame('tenant-a', ($filters['tenant_id'] ?? null), 'The tenant key must reach OpenRegister whole.');
		self::assertArrayNotHasKey('tenant', $filters);

	}//end testAPublishedLessonLetsTheCoursePublishThroughARegisterFaithfulRead()

	/**
	 * A draft Lesson, a Lesson on another Course, or one in another tenant does not count.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md
	 */
	public function testOnlyAPublishedLessonOfThisCourseAndTenantCounts(): void {
		$store = new RegisterFaithfulStore();
		$store->rows['lesson'] = [
			['id' => 'lesson-draft', 'courseId' => 'course-7', 'lifecycle' => 'draft', 'tenant_id' => 'tenant-a'],
			['id' => 'lesson-other-course', 'courseId' => 'course-8', 'lifecycle' => 'published', 'tenant_id' => 'tenant-a'],
			['id' => 'lesson-other-tenant', 'courseId' => 'course-7', 'lifecycle' => 'published', 'tenant_id' => 'tenant-b'],
		];

		$guard = new CoursePublishGuard($this->storeBackedObjectService(store: $store), $this->createMock(LoggerInterface::class));
		$object = ['id' => 'course-7', 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'];

		self::assertDenied($guard->check($object, 'publish', ''));

	}//end testOnlyAPublishedLessonOfThisCourseAndTenantCounts()

	/**
	 * An ObjectService double whose findAll() is answered by the register-faithful store.
	 *
	 * @param RegisterFaithfulStore $store The store holding the rows.
	 *
	 * @return ObjectService
	 */
	private function storeBackedObjectService(RegisterFaithfulStore $store): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $store->findAll($config, $_rbac, $_multitenancy)
		);

		return $objectService;

	}//end storeBackedObjectService()
}//end class
