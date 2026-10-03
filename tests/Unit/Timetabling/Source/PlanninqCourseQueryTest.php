<?php

/**
 * Planninq answers a course query and carries a lesson's link (live pass D8).
 *
 * With planninq as the timetable source the elective overlap warning and the
 * timetable Join action never appeared: PlanninqTimetableSource sent no course
 * query and dropped any link (livepass/learniq/timetabling-student-choice-placement/slots.txt).
 * Ruben decided to build the planninq path (DECISIONS row 53). The contract is
 * for-ruben/planninq-timetable-course-query-and-lesson-link.md: planninq's
 * TimetableSessionsQueryEvent at CONTRACT_VERSION 2 takes `courseId` as an
 * identity key and each lesson may carry `courseId` and `onlineMeetingUrl`.
 * The fake below answers exactly that; the live check stays open until
 * planninq lands it.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-elective-sessions-in-the-personal-timetable
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Timetabling\Source;

if (class_exists('\\OCA\\Planninq\\Event\\TimetableSessionsQueryEvent') === false) {
	require_once __DIR__ . '/../../../Stubs/Planninq/Event/TimetableSessionsQueryEvent.php';
}

use OCA\Learniq\Timetabling\Source\PlanninqTimetableSource;
use OCA\Planninq\Event\TimetableSessionsQueryEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Planninq's event at contract version 2.
 */
class TimetableSessionsQueryEventV2 extends TimetableSessionsQueryEvent {

	public const CONTRACT_VERSION = 2;
}//end class

/**
 * The course query and the lesson link, against the v2 contract.
 */
class PlanninqCourseQueryTest extends TestCase {

	/**
	 * Criteria planninq received.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * A planninq that answers the v2 contract over three lessons.
	 *
	 * @return IEventDispatcher
	 */
	private function planninq(): IEventDispatcher {
		$lessons = [
			['id' => 'p-a1', 'title' => 'Spaans', 'subject' => 'sp', 'startsAt' => '2026-10-05T09:00:00+02:00', 'endsAt' => '2026-10-05T09:50:00+02:00', 'cohortId' => 'keuze-1', 'courseId' => 'course-a', 'onlineMeetingUrl' => 'https://meet.example.org/sp', 'status' => 'scheduled'],
			['id' => 'p-b1', 'title' => 'Kunst', 'subject' => 'ku', 'startsAt' => '2026-10-05T09:00:00+02:00', 'endsAt' => '2026-10-05T09:50:00+02:00', 'cohortId' => 'keuze-2', 'courseId' => 'course-b', 'onlineMeetingUrl' => null, 'status' => 'scheduled'],
			['id' => 'p-c1', 'title' => 'Wiskunde', 'subject' => 'wi', 'startsAt' => '2026-10-05T10:00:00+02:00', 'endsAt' => '2026-10-05T10:50:00+02:00', 'cohortId' => '3a', 'status' => 'scheduled'],
		];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($lessons): void {
				if ($event instanceof TimetableSessionsQueryEvent === false) {
					return;
				}

				$criteria = $event->getCriteria();
				$this->queries[] = $criteria;
				// Planninq's TimetableSessionQuery: every identity key named must match.
				$identity = array_intersect_key($criteria, array_flip(['cohortId', 'groupReference', 'teacherUserId', 'teacherReference', 'courseId']));
				if ($identity === []) {
					$event->setError('Name a cohortId, groupReference, teacherUserId, teacherReference or courseId to read a timetable.');
					return;
				}

				$event->setSessions(
					array_values(
						array_filter(
							$lessons,
							static function (array $lesson) use ($identity): bool {
								foreach ($identity as $key => $value) {
									if ((string)($lesson[$key] ?? '') !== (string)$value) {
										return false;
									}
								}

								return true;
							}
						)
					)
				);
			}
		);

		return $dispatcher;
	}//end planninq()

	/**
	 * Planninq is installed.
	 *
	 * @return IAppManager
	 */
	private function installed(): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		return $apps;
	}//end installed()

	/**
	 * Live pass D8: the slots of two electives come back per course, each
	 * lesson naming its course, so the picker can warn on the overlap.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-elective-sessions-in-the-personal-timetable
	 */
	public function testTheElectivesComeBackPerCourse(): void {
		$source = new PlanninqTimetableSource($this->installed(), $this->planninq(), TimetableSessionsQueryEventV2::class);

		$sessions = $source->sessionsForCourses(['course-a', 'course-b', ''], '2026-10-05T00:00:00+02:00', '2026-10-12T00:00:00+02:00');

		self::assertSame(['p-a1', 'p-b1'], array_column($sessions, 'id'));
		self::assertSame(['course-a', 'course-b'], array_column($sessions, 'courseId'));
		self::assertSame(['course-a', 'course-b'], array_column($this->queries, 'courseId'));
		self::assertSame('planninq', $sessions[0]['source']);
	}//end testTheElectivesComeBackPerCourse()

	/**
	 * The lesson's link reaches the timetable page through the projector (only https).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-online-lesson-link/specs/timetable-online-lesson-link/spec.md#requirement-online-meeting-link-on-a-lesson
	 */
	public function testTheLessonLinkIsCarried(): void {
		$source = new PlanninqTimetableSource($this->installed(), $this->planninq(), TimetableSessionsQueryEventV2::class);

		$sessions = $source->sessionsForCohorts(['keuze-1', '3a'], null, null);

		self::assertSame('https://meet.example.org/sp', $sessions[0]['onlineMeetingUrl']);
		self::assertNull($sessions[1]['onlineMeetingUrl']);
	}//end testTheLessonLinkIsCarried()

	/**
	 * A planninq on contract version 1 is never asked by course: it would
	 * refuse a query without a cohort, group or teacher.
	 *
	 * @return void
	 */
	public function testAVersionOnePlanninqIsNotAskedByCourse(): void {
		$source = new PlanninqTimetableSource($this->installed(), $this->planninq());

		self::assertSame([], $source->sessionsForCourses(['course-a'], null, null));
		self::assertSame([], $this->queries);
	}//end testAVersionOnePlanninqIsNotAskedByCourse()
}//end class
