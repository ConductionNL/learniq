<?php

/**
 * Unit tests for the timetable sources' edges: empty scope ids are not
 * queried, another course's lesson never leaks into a course read, and a
 * planninq without the course contract answers no course lessons.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Timetabling\Source
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Timetabling\Source;

use OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource;
use OCA\Learniq\Timetabling\Source\PlanninqTimetableSource;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource
 * @covers \OCA\Learniq\Timetabling\Source\PlanninqTimetableSource
 * @uses   \OCA\Learniq\Support\ObjectRow
 */
class TimetableSourceEdgesTest extends TestCase {

	/**
	 * Empty cohort, teacher and course ids are never queried.
	 *
	 * @return void
	 */
	public function testEmptyScopeIdsAreNotQueried(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::never())->method('findAll');
		$source = new LocalSessionTimetableSource($objects);

		self::assertSame([], $source->sessionsForCohorts(cohortIds: ['', ''], from: null, to: null));
		self::assertSame([], $source->sessionsForTeacher(userId: '', from: null, to: null));
		self::assertSame([], $source->sessionsForCourses(courseIds: [''], from: null, to: null));
	}//end testEmptyScopeIdsAreNotQueried()

	/**
	 * A course read queries each distinct course once, by course, without RBAC, and drops another course's lesson.
	 *
	 * @return void
	 */
	public function testACourseReadKeepsOnlyThatCoursesLessons(): void {
		$calls = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config, bool $_rbac = true) use (&$calls): array {
				$calls[] = [$config, $_rbac];
				return [
					['id' => 's-1', 'courseId' => 'course-1'],
					['id' => 's-x', 'courseId' => 'course-2'],
					['id' => 's-none'],
				];
			}
		);
		$source = new LocalSessionTimetableSource($objects);

		$rows = $source->sessionsForCourses(courseIds: ['course-1', '', 'course-1'], from: '2026-09-01', to: '2026-09-30');

		self::assertSame([['id' => 's-1', 'courseId' => 'course-1', 'source' => 'learniq']], $rows);
		self::assertSame(
			[
				[
					[
						'filters' => ['register' => 'learniq', 'schema' => 'session', 'courseId' => 'course-1'],
						'sort' => ['startsAt' => 'ASC'],
					],
					false,
				],
			],
			$calls
		);
	}//end testACourseReadKeepsOnlyThatCoursesLessons()

	/**
	 * A planninq whose query event is missing, or has no contract version, answers no course lessons without dispatching.
	 *
	 * @return void
	 */
	public function testWithoutTheCourseContractPlanninqAnswersNoCourseLessons(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method(self::anything());
		$apps = $this->createMock(IAppManager::class);

		$missing = new PlanninqTimetableSource($apps, $dispatcher, 'OCA\\Planninq\\Event\\NoSuchEvent');
		$unversioned = new PlanninqTimetableSource($apps, $dispatcher, \stdClass::class);

		self::assertSame([], $missing->sessionsForCourses(courseIds: ['course-1'], from: null, to: null));
		self::assertSame([], $unversioned->sessionsForCourses(courseIds: ['course-1'], from: null, to: null));
	}//end testWithoutTheCourseContractPlanninqAnswersNoCourseLessons()
}//end class
