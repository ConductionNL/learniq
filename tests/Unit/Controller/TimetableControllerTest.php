<?php

/**
 * Unit tests for TimetableController.
 *
 * Verify the personal-timetable read surface: cohort-membership resolution
 * (teacher via Cohort.teacherIds, learner via Cohort.learnerIds and
 * Enrolment.cohortId), window filtering, ordering, the empty-not-error
 * contract, no cross-cohort leakage, and the read-only invariant (no writes).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Controller\TimetableController;
use OCA\Learniq\Service\LessonNoteReader;
use OCA\Learniq\Service\PersonalTimetableService;
use OCA\Learniq\Service\TimetableProjector;
use OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource;
use OCA\Learniq\Timetabling\Source\PlanninqTimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\Planninq\Event\TimetableSessionsQueryEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for TimetableController::mine().
 *
 * The window is passed explicitly to every test so the assertions do not depend
 * on the wall-clock "current week" default.
 */
class TimetableControllerTest extends TestCase {
	/**
	 * ObjectService mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * User-session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Window start used by every windowed test (Monday).
	 *
	 * @var string
	 */
	private string $from = '2026-01-05T00:00:00+00:00';

	/**
	 * Window end used by every windowed test (next Monday, exclusive).
	 *
	 * @var string
	 */
	private string $to = '2026-01-12T00:00:00+00:00';

	/**
	 * Build the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Criteria of every planninq query, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $planninqQueries = [];

	/**
	 * Build the controller under test.
	 *
	 * @param array<int,array<string,mixed>>|null $planninqLessons Lessons planninq answers with; null means planninq is not installed.
	 * @param bool                                $planninqSilent  Whether planninq stays silent.
	 *
	 * @return TimetableController The controller.
	 */
	private function controller(?array $planninqLessons = null, bool $planninqSilent = false): TimetableController {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($planninqLessons, $planninqSilent): void {
				if (($event instanceof TimetableSessionsQueryEvent) === false || $planninqSilent === true) {
					return;
				}

				$criteria = $event->getCriteria();
				$this->planninqQueries[] = $criteria;
				$event->setSessions(
					array_values(
						array_filter(
							($planninqLessons ?? []),
							static fn (array $lesson): bool => (isset($criteria['cohortId']) === true && ($lesson['cohortId'] ?? '') === $criteria['cohortId'])
								|| (isset($criteria['teacherUserId']) === true && ($lesson['teacherUserId'] ?? '') === $criteria['teacherUserId'])
						)
					)
				);
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($planninqLessons !== null);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('auto');

		$projector = new TimetableProjector(
			logger: $this->logger,
			noteReader: new LessonNoteReader($this->objectService, $this->groupManager(), $this->logger)
		);
		$sources = new TimetableSourceResolver(
			$config,
			new LocalSessionTimetableSource($this->objectService),
			new PlanninqTimetableSource($appManager, $dispatcher)
		);

		// The real PersonalTimetableService: mine() delegates to it since the
		// calendar feed shares it, and every test below proves its output unchanged.
		return new TimetableController(
			request: $this->createMock(IRequest::class),
			userSession: $this->userSession,
			objectService: $this->objectService,
			projector: $projector,
			sources: $sources,
			timetable: new PersonalTimetableService(
				objectService: $this->objectService,
				projector: $projector,
				sources: $sources,
				logger: $this->logger
			),
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * Groups of the signed-in user, for the lesson note reader.
	 *
	 * @var array<int,string>
	 */
	private array $callerGroups = [];

	/**
	 * Lesson notes served for the `lesson-note` schema.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $notes = [];

	/**
	 * A group manager that answers from $callerGroups.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => in_array($group, $this->callerGroups, true));
		return $groups;
	}//end groupManager()

	/**
	 * Make IUserSession return a user with the given uid.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signInAs()

	/**
	 * Wire ObjectService::findAll to serve fixture data keyed by schema.
	 *
	 * The `session` schema is served per-cohort: the callback honours the
	 * `cohortId` equality filter so the test proves the controller never loads
	 * a session for a cohort the caller does not belong to.
	 *
	 * @param array<int,array<string,mixed>> $cohorts Cohort fixtures.
	 * @param array<int,array<string,mixed>> $enrolments Enrolment fixtures (already scoped to the caller).
	 * @param array<int,array<string,mixed>> $sessions Session fixtures (all cohorts).
	 * @param array<int,array<string,mixed>> $rooms Room fixtures.
	 *
	 * @return void
	 */
	private function wireFindAll(array $cohorts, array $enrolments, array $sessions, array $rooms = []): void {
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($cohorts, $enrolments, $sessions, $rooms): array {
				$schema = $config['filters']['schema'] ?? '';
				$filters = array_diff_key(($config['filters'] ?? []), ['register' => true, 'schema' => true]);

				if ($schema === 'cohort') {
					return $cohorts;
				}

				if ($schema === 'enrolment') {
					$learner = $filters['learnerId'] ?? null;
					return array_values(
						array_filter(
							$enrolments,
							static fn (array $e): bool => $learner === null || ($e['learnerId'] ?? null) === $learner
						)
					);
				}

				if ($schema === 'session') {
					// Equality on every filter key, as OpenRegister applies them
					// (`cohortId` per cohort, `substituteTeacherId` for cover).
					return array_values(
						array_filter(
							$sessions,
							static function (array $s) use ($filters): bool {
								foreach ($filters as $key => $value) {
									if (($s[$key] ?? null) !== $value) {
										return false;
									}
								}

								return true;
							}
						)
					);
				}

				if ($schema === 'lesson-note') {
					$cohort = $filters['cohortId'] ?? null;
					return array_values(array_filter($this->notes, static fn (array $n): bool => ($n['cohortId'] ?? null) === $cohort));
				}

				if ($schema === 'room') {
					$id = $config['ids'][0] ?? null;
					return array_values(array_filter($rooms, static fn (array $r): bool => ($r['id'] ?? null) === $id));
				}

				return [];
			}
		);
	}//end wireFindAll()

	/**
	 * Decode a JSONResponse body to an array.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array<string,mixed> The decoded body.
	 */
	private function body(JSONResponse $response): array {
		return (array)$response->getData();
	}//end body()

	/**
	 * A learner sees this week's sessions for their enrolled cohorts, ordered,
	 * and never a session of a cohort they are not in.
	 *
	 * @return void
	 */
	public function testLearnerSeesEnrolledCohortSessions(): void {
		$this->signInAs('alice');

		// alice is a listed learner in cohort-1 and enrolled (via Enrolment) in cohort-2.
		// cohort-3 is someone else's — she must never see its session.
		$cohorts = [
			['id' => 'cohort-1', 'learnerIds' => ['alice', 'bob'], 'teacherIds' => ['tom']],
			['id' => 'cohort-2', 'learnerIds' => [], 'teacherIds' => ['tom']],
			['id' => 'cohort-3', 'learnerIds' => ['carol'], 'teacherIds' => ['tom']],
		];
		$enrolments = [
			['learnerId' => 'alice', 'cohortId' => 'cohort-2'],
		];
		$sessions = [
			['id' => 's-b', 'cohortId' => 'cohort-1', 'title' => 'Biology', 'startsAt' => '2026-01-07T10:00:00+00:00', 'endsAt' => '2026-01-07T11:00:00+00:00', 'location' => 'Room 1'],
			['id' => 's-a', 'cohortId' => 'cohort-2', 'title' => 'Algebra', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00', 'location' => 'Room 2'],
			['id' => 's-x', 'cohortId' => 'cohort-3', 'title' => 'Secret',  'startsAt' => '2026-01-08T09:00:00+00:00', 'endsAt' => '2026-01-08T10:00:00+00:00', 'location' => 'Room 9'],
		];
		$this->wireFindAll($cohorts, $enrolments, $sessions);

		$response = $this->controller()->mine(from: $this->from, to: $this->to);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$out = $this->body($response);
		$ids = array_column($out['sessions'], 'id');

		// Ordered by startsAt: Algebra (Jan 6) before Biology (Jan 7).
		$this->assertSame(['s-a', 's-b'], $ids);
		// No cross-cohort leakage.
		$this->assertNotContains('s-x', $ids);
		// Projection carries the required fields.
		$this->assertSame('Algebra', $out['sessions'][0]['title']);
		$this->assertSame('Room 2', $out['sessions'][0]['location']);
	}//end testLearnerSeesEnrolledCohortSessions()

	/**
	 * A teacher sees the sessions of the cohorts they teach.
	 *
	 * @return void
	 */
	public function testTeacherSeesTaughtCohortSessions(): void {
		$this->signInAs('tom');

		$cohorts = [
			['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => ['tom']],
			['id' => 'cohort-9', 'learnerIds' => ['zoe'], 'teacherIds' => ['other']],
		];
		$sessions = [
			['id' => 's-1', 'cohortId' => 'cohort-1', 'title' => 'Lecture', 'startsAt' => '2026-01-07T13:00:00+00:00', 'endsAt' => '2026-01-07T14:00:00+00:00'],
			['id' => 's-9', 'cohortId' => 'cohort-9', 'title' => 'Other',   'startsAt' => '2026-01-07T15:00:00+00:00', 'endsAt' => '2026-01-07T16:00:00+00:00'],
		];
		$this->wireFindAll($cohorts, [], $sessions);

		$out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));
		$ids = array_column($out['sessions'], 'id');

		$this->assertSame(['s-1'], $ids);
	}//end testTeacherSeesTaughtCohortSessions()

	/**
	 * A user with no cohorts gets an empty list (HTTP 200), never an error.
	 *
	 * @return void
	 */
	public function testNoCohortsReturnsEmptyOk(): void {
		$this->signInAs('nobody');

		$cohorts = [
			['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => ['tom']],
		];
		// Sessions are only ever asked for the caller's own cover lessons when
		// the caller has no cohorts: never per cohort, never unfiltered.
		$this->objectService->expects($this->exactly(3))
			->method('findAll')
			->willReturnCallback(
				function (array $config) use ($cohorts): array {
					if (($config['filters']['schema'] ?? '') === 'session') {
						$filters = array_diff_key($config['filters'], ['register' => true, 'schema' => true]);
						$this->assertSame(['substituteTeacherId' => 'nobody'], $filters, 'Sessions must not be queried per cohort when the caller has no cohorts');
						return [];
					}
					if (($config['filters']['schema'] ?? '') === 'cohort') {
						return $cohorts;
					}
					return [];
				}
			);

		$response = $this->controller()->mine(from: $this->from, to: $this->to);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$body = $this->body($response);
		$this->assertSame([], $body['sessions']);
		$this->assertSame([], $body['changes']);
	}//end testNoCohortsReturnsEmptyOk()

	/**
	 * A teacher assigned as substitute on another cohort's lesson sees that
	 * lesson, marked as cover, and no other lesson of that cohort (learniq#1134).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-substitute-teacher-sees-the-lessons-they-cover
	 */
	public function testASubstituteSeesTheLessonTheyCoverAndNothingElseOfThatCohort(): void {
		$this->signInAs('e.devries');

		$cohorts = [
			['id' => 'cohort-own', 'learnerIds' => ['alice'], 'teacherIds' => ['e.devries']],
			['id' => 'cohort-4havo', 'learnerIds' => ['bob'], 'teacherIds' => ['s.jansen']],
		];
		$sessions = [
			['id' => 's-own', 'cohortId' => 'cohort-own', 'title' => 'Economie', 'startsAt' => '2026-01-05T09:00:00+00:00', 'endsAt' => '2026-01-05T10:00:00+00:00'],
			['id' => 's-cover', 'cohortId' => 'cohort-4havo', 'title' => 'Wiskunde B', 'startsAt' => '2026-01-06T11:00:00+00:00', 'endsAt' => '2026-01-06T12:00:00+00:00', 'substituteTeacherId' => 'e.devries'],
			['id' => 's-not-covered', 'cohortId' => 'cohort-4havo', 'title' => 'Wiskunde B', 'startsAt' => '2026-01-08T11:00:00+00:00', 'endsAt' => '2026-01-08T12:00:00+00:00'],
		];
		$this->wireFindAll($cohorts, [], $sessions);

		$out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));
		$byId = array_column($out['sessions'], null, 'id');

		$this->assertSame(['s-own', 's-cover'], array_column($out['sessions'], 'id'));
		$this->assertTrue($byId['s-cover']['cover'], 'The covered lesson is marked as cover.');
		$this->assertFalse($byId['s-own']['cover'], 'A lesson of the caller\'s own cohort is not cover.');
	}//end testASubstituteSeesTheLessonTheyCoverAndNothingElseOfThatCohort()

	/**
	 * A substitute who teaches no cohort still sees the lessons they cover.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-substitute-teacher-sees-the-lessons-they-cover
	 */
	public function testASubstituteWithNoCohortSeesTheLessonTheyCover(): void {
		$this->signInAs('pool.invaller');

		$cohorts = [['id' => 'cohort-4havo', 'learnerIds' => ['bob'], 'teacherIds' => ['s.jansen']]];
		$sessions = [
			['id' => 's-cover', 'cohortId' => 'cohort-4havo', 'title' => 'Wiskunde B', 'startsAt' => '2026-01-06T11:00:00+00:00', 'endsAt' => '2026-01-06T12:00:00+00:00', 'substituteTeacherId' => 'pool.invaller'],
			['id' => 's-not-covered', 'cohortId' => 'cohort-4havo', 'title' => 'Wiskunde B', 'startsAt' => '2026-01-08T11:00:00+00:00', 'endsAt' => '2026-01-08T12:00:00+00:00'],
		];
		$this->wireFindAll($cohorts, [], $sessions);

		$out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));

		$this->assertSame(['s-cover'], array_column($out['sessions'], 'id'));
		$this->assertTrue($out['sessions'][0]['cover']);
	}//end testASubstituteWithNoCohortSeesTheLessonTheyCover()

	/**
	 * Each session projects roomId (with resolved Room detail when set),
	 * lifecycle, substituteTeacherId, changeReasonKind, and changeReason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
	 */
	public function testProjectsRoomAndSubstitutionFields(): void {
		$this->signInAs('alice');

		$cohorts = [['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => []]];
		$sessions = [
			[
				'id' => 's-1', 'cohortId' => 'cohort-1', 'title' => 'Bio',
				'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00',
				'roomId' => 'room-1', 'lifecycle' => 'scheduled',
				'substituteTeacherId' => 'sub-1', 'changeReasonKind' => 'teacher-absence', 'changeReason' => 'Ziek',
			],
		];
		$rooms = [['id' => 'room-1', 'name' => 'Lokaal A-203', 'capacity' => 30, 'facilities' => ['projector']]];
		$this->wireFindAll($cohorts, [], $sessions, $rooms);

		$out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));
		$session = $out['sessions'][0];

		$this->assertSame('room-1', $session['roomId']);
		$this->assertSame('Lokaal A-203', $session['room']['name']);
		$this->assertSame(30, $session['room']['capacity']);
		$this->assertSame('sub-1', $session['substituteTeacherId']);
		$this->assertSame('teacher-absence', $session['changeReasonKind']);
		$this->assertSame('Ziek', $session['changeReason']);
		$this->assertSame('scheduled', $session['lifecycle']);
	}//end testProjectsRoomAndSubstitutionFields()

	/**
	 * A Session with no roomId projects a null room, never an error.
	 *
	 * @return void
	 */
	public function testNoRoomProjectsNull(): void {
		$this->signInAs('alice');

		$cohorts = [['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => []]];
		$sessions = [['id' => 's-1', 'cohortId' => 'cohort-1', 'title' => 'Bio', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00']];
		$this->wireFindAll($cohorts, [], $sessions);

		$out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));

		$this->assertNull($out['sessions'][0]['roomId']);
		$this->assertNull($out['sessions'][0]['room']);
	}//end testNoRoomProjectsNull()

	/**
	 * Today's cancellation surfaces in the dagrooster `changes` list even for
	 * a Session scheduled outside the requested window (a future Session).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#scenario-today-s-cancellation-surfaces-in-the-dagrooster-changes-list-even-for-a-future-session
	 */
	public function testTodaysCancellationSurfacesInChangesRegardlessOfWindow(): void {
		$this->signInAs('alice');

		$today = gmdate('Y-m-d\TH:i:s\+00:00');
		$cohorts = [['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => []]];
		$sessions = [
			[
				// Scheduled well outside the requested window (2026-01-05..12).
				'id' => 's-future', 'cohortId' => 'cohort-1', 'title' => 'Future lesson',
				'startsAt' => '2099-01-01T09:00:00+00:00', 'endsAt' => '2099-01-01T10:00:00+00:00',
				'lifecycle' => 'cancelled', 'changedAt' => $today,
			],
		];
		$this->wireFindAll($cohorts, [], $sessions);

		$out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));

		$this->assertSame([], $out['sessions']);
		$this->assertCount(1, $out['changes']);
		$this->assertSame('s-future', $out['changes'][0]['id']);
	}//end testTodaysCancellationSurfacesInChangesRegardlessOfWindow()

	/**
	 * A Session changed on a prior day does not appear in today's changes list.
	 *
	 * @return void
	 */
	public function testStaleChangeDoesNotAppearInTodaysChanges(): void {
		$this->signInAs('alice');

		$cohorts = [['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => []]];
		$sessions = [
			[
				'id' => 's-old-change', 'cohortId' => 'cohort-1', 'title' => 'Old change',
				'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00',
				'lifecycle' => 'cancelled', 'changedAt' => '2020-01-01T09:00:00+00:00',
			],
		];
		$this->wireFindAll($cohorts, [], $sessions);

		$out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));

		$this->assertSame([], $out['changes']);
	}//end testStaleChangeDoesNotAppearInTodaysChanges()

	/**
	 * Sessions outside the requested window are excluded.
	 *
	 * @return void
	 */
	public function testWindowingExcludesOutOfWindowSessions(): void {
		$this->signInAs('alice');

		$cohorts = [['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => []]];
		$sessions = [
			// In window.
			['id' => 'in', 'cohortId' => 'cohort-1', 'title' => 'In', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00'],
			// Before the window.
			['id' => 'before', 'cohortId' => 'cohort-1', 'title' => 'Before', 'startsAt' => '2026-01-01T09:00:00+00:00', 'endsAt' => '2026-01-01T10:00:00+00:00'],
			// After the window (starts on the exclusive end boundary).
			['id' => 'after', 'cohortId' => 'cohort-1', 'title' => 'After', 'startsAt' => '2026-01-12T09:00:00+00:00', 'endsAt' => '2026-01-12T10:00:00+00:00'],
		];
		$this->wireFindAll($cohorts, [], $sessions);

		$out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));
		$ids = array_column($out['sessions'], 'id');

		$this->assertSame(['in'], $ids);
	}//end testWindowingExcludesOutOfWindowSessions()

	/**
	 * An unauthenticated caller gets HTTP 401.
	 *
	 * @return void
	 */
	public function testUnauthenticatedReturns401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectService->expects($this->never())->method('findAll');

		$response = $this->controller()->mine();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testUnauthenticatedReturns401()

	/**
	 * The timetable never writes — saveObject is never called.
	 *
	 * @return void
	 */
	public function testReadOnlyNeverWrites(): void {
		$this->signInAs('alice');
		$this->wireFindAll(
			[['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => []]],
			[],
			[['id' => 's', 'cohortId' => 'cohort-1', 'title' => 'X', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00']]
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->mine(from: $this->from, to: $this->to);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testReadOnlyNeverWrites()

	/**
	 * The window defaults to the current ISO week when from/to are omitted.
	 *
	 * @return void
	 */
	public function testDefaultWindowIsCurrentWeek(): void {
		$this->signInAs('nobody');
		$this->objectService->method('findAll')->willReturn([]);

		$out = $this->body($this->controller()->mine());

		// The server echoes a resolved 7-day window even for the empty result.
		$this->assertNotSame('', $out['from']);
		$this->assertNotSame('', $out['to']);
		$fromTs = strtotime($out['from']);
		$toTs = strtotime($out['to']);
		$this->assertSame(7 * 24 * 3600, ($toTs - $fromTs));
	}//end testDefaultWindowIsCurrentWeek()

	/**
	 * Two planninq lessons: one for cohort c-1 taught by jan, one of jan's in another group.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function planninqLessons(): array {
		return [
			['id' => 'p-1', 'title' => 'Wiskunde', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T09:50:00+00:00', 'cohortId' => 'c-1', 'teacherUserId' => 'jan', 'roomReference' => 'A1.12', 'roomLabel' => null, 'status' => 'scheduled'],
			['id' => 'p-2', 'title' => 'Wiskunde', 'startsAt' => '2026-01-07T09:00:00+00:00', 'endsAt' => '2026-01-07T09:50:00+00:00', 'cohortId' => '', 'teacherUserId' => 'jan', 'roomReference' => 'A1.12', 'roomLabel' => null, 'status' => 'cancelled'],
		];
	}//end planninqLessons()

	/**
	 * With planninq, my timetable reads planninq by cohort and by the caller's
	 * own account, merges without duplicates, and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
	 */
	public function testMineReadsPlanninqByCohortAndTeacher(): void {
		$this->signInAs('jan');
		$this->wireFindAll(cohorts: [['id' => 'c-1', 'teacherIds' => ['jan'], 'learnerIds' => []]], enrolments: [], sessions: []);
		$this->objectService->expects($this->never())->method('saveObject');

		$out = $this->controller(planninqLessons: $this->planninqLessons())->mine($this->from, $this->to)->getData();

		$this->assertSame('planninq', $out['source']);
		$this->assertSame(['p-1', 'p-2'], array_column($out['sessions'], 'id'));
		$this->assertSame(['planninq', 'planninq'], array_column($out['sessions'], 'source'));
		$this->assertSame('cancelled', $out['sessions'][1]['lifecycle']);
		$this->assertSame('A1.12', $out['sessions'][0]['location']);
		$this->assertSame('c-1', $this->planninqQueries[0]['cohortId']);
		$this->assertSame('jan', $this->planninqQueries[1]['teacherUserId']);
	}//end testMineReadsPlanninqByCohortAndTeacher()

	/**
	 * A silent planninq is a 503, not an empty timetable.
	 *
	 * @return void
	 */
	public function testSilentPlanninqIsUnavailableNotEmpty(): void {
		$this->signInAs('jan');
		$this->wireFindAll(cohorts: [['id' => 'c-1', 'teacherIds' => ['jan'], 'learnerIds' => []]], enrolments: [], sessions: []);

		$response = $this->controller(planninqLessons: [], planninqSilent: true)->mine($this->from, $this->to);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testSilentPlanninqIsUnavailableNotEmpty()

	/**
	 * The cohort timetable reads the cohort with RBAC, then the source.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
	 */
	public function testCohortTimetableReadsThroughTheSource(): void {
		$this->signInAs('jan');
		$this->objectService->method('find')->willReturn($this->createMock(ObjectEntity::class));

		$out = $this->controller(planninqLessons: $this->planninqLessons())->cohort('c-1', $this->from, $this->to)->getData();

		$this->assertSame('planninq', $out['source']);
		$this->assertSame(['p-1'], array_column($out['sessions'], 'id'));
		$this->assertSame($this->from, $out['from']);
	}//end testCohortTimetableReadsThroughTheSource()

	/**
	 * The cohort timetable without planninq reads the cohort's Sessions, and
	 * defaults to an eight-week window.
	 *
	 * @return void
	 */
	public function testCohortTimetableWithoutPlanninqReadsSessions(): void {
		$this->signInAs('jan');
		$this->objectService->method('find')->willReturn($this->createMock(ObjectEntity::class));
		$monday = (new \DateTimeImmutable('monday this week', new \DateTimeZone('UTC')))->setTime(9, 0);
		$this->wireFindAll(
			cohorts: [],
			enrolments: [],
			sessions: [['id' => 's-1', 'cohortId' => 'c-1', 'title' => 'Biologie', 'startsAt' => $monday->format(DATE_ATOM), 'endsAt' => $monday->modify('+50 minutes')->format(DATE_ATOM)]]
		);

		$out = $this->controller()->cohort('c-1')->getData();

		$this->assertSame('learniq', $out['source']);
		$this->assertSame(['s-1'], array_column($out['sessions'], 'id'));
		$this->assertSame(56 * 24 * 3600, (strtotime($out['to']) - strtotime($out['from'])));
	}//end testCohortTimetableWithoutPlanninqReadsSessions()

	/**
	 * A cohort the caller cannot read is a 403, and no lesson is read.
	 *
	 * @return void
	 */
	public function testUnreadableCohortIsForbiddenAndReadsNothing(): void {
		$this->signInAs('learner');
		$this->objectService->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('nope'));

		$response = $this->controller(planninqLessons: $this->planninqLessons())->cohort('c-9', $this->from, $this->to);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->planninqQueries);
	}//end testUnreadableCohortIsForbiddenAndReadsNothing()
	/**
	 * The lesson note fixtures: one for learners and one for the covering
	 * teacher on s-1 (cohort-1), and one on another cohort's lesson.
	 *
	 * @return void
	 */
	private function seedNotes(): void {
		$this->notes = [
			['id' => 'n-1', 'sessionId' => 's-1', 'cohortId' => 'cohort-1', 'topic' => 'Hoofdstuk 4', 'text' => 'Neem je rekenmachine mee', 'audience' => 'learners', 'authorId' => 'tom'],
			['id' => 'n-2', 'sessionId' => 's-1', 'cohortId' => 'cohort-1', 'topic' => null, 'text' => 'Opgave 12 tot 18', 'audience' => 'cover', 'authorId' => 'tom'],
			['id' => 'n-3', 'sessionId' => 's-9', 'cohortId' => 'cohort-9', 'topic' => null, 'text' => 'Other', 'audience' => 'learners', 'authorId' => 'other'],
		];
	}//end seedNotes()

	/**
	 * The cohort and lessons the note tests share.
	 *
	 * @param array<string,mixed> $extra Extra fields on s-1.
	 *
	 * @return void
	 */
	private function wireNoteLessons(array $extra = []): void {
		$cohorts = [
			['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => ['tom']],
			['id' => 'cohort-9', 'learnerIds' => ['zoe'], 'teacherIds' => ['other']],
		];
		$sessions = [
			array_merge(['id' => 's-1', 'cohortId' => 'cohort-1', 'title' => 'Wiskunde B', 'startsAt' => '2026-01-07T13:00:00+00:00', 'endsAt' => '2026-01-07T14:00:00+00:00'], $extra),
			['id' => 's-9', 'cohortId' => 'cohort-9', 'title' => 'Other', 'startsAt' => '2026-01-07T15:00:00+00:00', 'endsAt' => '2026-01-07T16:00:00+00:00'],
		];
		$this->seedNotes();
		$this->wireFindAll($cohorts, [], $sessions);
	}//end wireNoteLessons()

	/**
	 * A learner sees the note for learners on their lesson, and never the cover note.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
	 */
	public function testLearnerSeesLearnerNoteButNeverTheCoverNote(): void {
		$this->signInAs('alice');
		$this->wireNoteLessons();

		$out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));

		$this->assertSame(['s-1'], array_column($out['sessions'], 'id'));
		$notes = $out['sessions'][0]['notes'];
		$this->assertSame(['n-1'], array_column($notes, 'id'));
		$this->assertSame('Hoofdstuk 4', $notes[0]['topic']);
		$this->assertNotContains('cover', array_column($notes, 'audience'));
		// A learner may not add a note.
		$this->assertFalse($out['sessions'][0]['canAddNote']);
	}//end testLearnerSeesLearnerNoteButNeverTheCoverNote()

	/**
	 * The lesson's teacher sees both notes.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
	 */
	public function testTeacherSeesEveryNoteOfTheirLesson(): void {
		$this->signInAs('tom');
		$this->wireNoteLessons();

		$out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));

		$this->assertSame(['n-1', 'n-2'], array_column($out['sessions'][0]['notes'], 'id'));
		$this->assertTrue($out['sessions'][0]['canAddNote']);
	}//end testTeacherSeesEveryNoteOfTheirLesson()

	/**
	 * A substitute gets the lesson they cover, marked cover, with the cover note.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-substitute-teacher-sees-the-lessons-they-cover
	 */
	public function testSubstituteSeesTheCoverNote(): void {
		$this->signInAs('eva');
		$this->wireNoteLessons(extra: ['substituteTeacherId' => 'eva']);

		$out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));

		$this->assertSame(['s-1'], array_column($out['sessions'], 'id'));
		$this->assertTrue($out['sessions'][0]['cover']);
		$this->assertSame(['n-1', 'n-2'], array_column($out['sessions'][0]['notes'], 'id'));
	}//end testSubstituteSeesTheCoverNote()
}//end class
