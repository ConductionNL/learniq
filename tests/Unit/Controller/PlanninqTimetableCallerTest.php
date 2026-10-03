<?php

/**
 * The planninq course query and lesson link, asserted from the callers.
 *
 * Live pass D8 (DECISIONS row 53): with planninq installed (source 'auto'),
 * the elective picker's course slots were always empty and no lesson carried
 * a Join link. These tests drive the real ElectiveSlotsController,
 * ElectiveSlotService, PersonalTimetableService, TimetableProjector,
 * TimetableSourceResolver and PlanninqTimetableSource against a planninq that
 * speaks contract v2; only OpenRegister and Nextcloud's edges are doubles.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

if (class_exists('\\OCA\\Planninq\\Event\\TimetableSessionsQueryEvent') === false) {
	require_once __DIR__ . '/../../Stubs/Planninq/Event/TimetableSessionsQueryEvent.php';
}

use DateTimeZone;
use OCA\Learniq\Controller\ElectiveSlotsController;
use OCA\Learniq\Service\ElectiveSlotService;
use OCA\Learniq\Service\LessonNoteReader;
use OCA\Learniq\Service\PersonalTimetableService;
use OCA\Learniq\Service\TimetableProjector;
use OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource;
use OCA\Learniq\Timetabling\Source\PlanninqTimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Planninq\Event\TimetableSessionsQueryEvent;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Planninq's query event at contract version 2, as the planninq change ships it
 * (for-ruben/planninq-timetable-course-query-and-lesson-link.md).
 */
class PlanninqQueryEventV2 extends TimetableSessionsQueryEvent {

	public const CONTRACT_VERSION = 2;
}//end class

/**
 * Tests for the planninq path, from the callers.
 */
class PlanninqTimetableCallerTest extends TestCase {

	/**
	 * Planninq's lessons in its read shape (contract v2). On Tuesday 6 January
	 * 2026 the electives Spanish and Art both meet at 10:00 Amsterdam time;
	 * alice's core Maths lesson has an https link, her Music lesson a
	 * javascript: one.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private const LESSONS = [
		['id' => 'p-sp', 'title' => 'Spaans', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T09:50:00+00:00', 'cohortId' => 'keuze-sp', 'courseId' => 'course-sp', 'onlineMeetingUrl' => null, 'status' => 'scheduled'],
		['id' => 'p-ku', 'title' => 'Kunst', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00', 'cohortId' => 'keuze-ku', 'courseId' => 'course-ku', 'onlineMeetingUrl' => null, 'status' => 'scheduled'],
		['id' => 'p-wi', 'title' => 'Wiskunde', 'startsAt' => '2026-01-06T11:00:00+00:00', 'endsAt' => '2026-01-06T11:50:00+00:00', 'cohortId' => 'c-1', 'courseId' => null, 'onlineMeetingUrl' => 'https://meet.example.org/wiskunde', 'status' => 'scheduled'],
		['id' => 'p-mu', 'title' => 'Muziek', 'startsAt' => '2026-01-06T12:00:00+00:00', 'endsAt' => '2026-01-06T12:50:00+00:00', 'cohortId' => 'c-1', 'courseId' => null, 'onlineMeetingUrl' => 'javascript:alert(1)', 'status' => 'scheduled'],
	];

	/**
	 * Planninq, answering like its TimetableSessionQuery at contract v2: every
	 * identity key named must match.
	 *
	 * @return IEventDispatcher
	 */
	private function planninq(): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event): void {
				if ($event instanceof TimetableSessionsQueryEvent === false) {
					return;
				}

				$identity = array_intersect_key($event->getCriteria(), array_flip(['cohortId', 'groupReference', 'teacherUserId', 'teacherReference', 'courseId']));
				if ($identity === []) {
					$event->setError('Name a cohortId, groupReference, teacherUserId, teacherReference or courseId to read a timetable.');
					return;
				}

				$event->setSessions(
					array_values(
						array_filter(
							self::LESSONS,
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
	 * OpenRegister: alice learns in cohort c-1; every course is readable; no
	 * learniq sessions, rooms or notes.
	 *
	 * @return ObjectService
	 */
	private function objects(): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($this->createMock(ObjectEntity::class));
		$objects->method('findAll')->willReturnCallback(
			static function (array $config): array {
				if (($config['filters']['schema'] ?? '') === 'cohort') {
					return [['id' => 'c-1', 'learnerIds' => ['alice'], 'teacherIds' => []]];
				}

				return [];
			}
		);
		return $objects;
	}//end objects()

	/**
	 * The timetable service over the real sources, with planninq installed.
	 *
	 * @param ObjectService $objects OpenRegister.
	 *
	 * @return array{0: TimetableSourceResolver, 1: PersonalTimetableService}
	 */
	private function timetable(ObjectService $objects): array {
		$logger = $this->createMock(LoggerInterface::class);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('auto');
		$sources = new TimetableSourceResolver(
			$appConfig,
			new LocalSessionTimetableSource($objects),
			new PlanninqTimetableSource($appManager, $this->planninq(), PlanninqQueryEventV2::class)
		);
		$projector = new TimetableProjector(logger: $logger, noteReader: new LessonNoteReader($objects, $this->createMock(IGroupManager::class), $logger));
		return [$sources, new PersonalTimetableService(objectService: $objects, projector: $projector, sources: $sources, logger: $logger)];
	}//end timetable()

	/**
	 * The live pass request: `course-slots?courseIds=A,B&withCore=1` as a
	 * learner. Both planninq electives come back with their Tuesday 10:00 slot,
	 * so the picker can warn that they overlap.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-elective-sessions-in-the-personal-timetable
	 */
	public function testTwoOverlappingPlanninqElectivesBothShowTheirSlot(): void {
		$objects = $this->objects();
		[$sources, $timetable] = $this->timetable(objects: $objects);
		self::assertTrue($sources->usesPlanninq());

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user);
		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime('2026-01-05T07:00:00+00:00'));

		$controller = new ElectiveSlotsController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			slots: new ElectiveSlotService(objectService: $objects, sources: $sources, timetable: $timetable),
			zone: $zone,
			time: $time,
		);

		$data = $controller->slots(courseIds: 'course-sp,course-ku', withCore: '1')->getData();

		self::assertSame([['weekday' => 2, 'start' => '10:00', 'end' => '10:50', 'label' => 'Spaans']], $data['courses']['course-sp']);
		self::assertSame([['weekday' => 2, 'start' => '10:00', 'end' => '11:00', 'label' => 'Kunst']], $data['courses']['course-ku']);
	}//end testTwoOverlappingPlanninqElectivesBothShowTheirSlot()

	/**
	 * My timetable with planninq: a lesson's https link reaches the learner's
	 * timetable (the Join action), and a non-https link never does.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-online-lesson-link/specs/timetable-online-lesson-link/spec.md#requirement-online-meeting-link-on-a-lesson
	 */
	public function testAPlanninqLessonLinkReachesTheTimetable(): void {
		[, $timetable] = $this->timetable(objects: $this->objects());

		$result = $timetable->forUser(uid: 'alice', windowFrom: '2026-01-05T00:00:00+00:00', windowTo: '2026-01-12T00:00:00+00:00');

		self::assertSame('planninq', $result['source']);
		$links = array_column($result['sessions'], 'onlineMeetingUrl', 'id');
		self::assertSame(['p-wi' => 'https://meet.example.org/wiskunde', 'p-mu' => null], $links);
	}//end testAPlanninqLessonLinkReachesTheTimetable()
}//end class
