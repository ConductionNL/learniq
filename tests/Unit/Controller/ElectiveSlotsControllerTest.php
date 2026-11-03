<?php

/**
 * Unit tests for ElectiveSlotsController.
 *
 * Built on the real ElectiveSlotService, PersonalTimetableService,
 * TimetableProjector and timetable sources; only OpenRegister and Nextcloud's
 * edges are doubles.
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

use DateTimeZone;
use OCA\Learniq\Controller\ElectiveSlotsController;
use OCA\Learniq\Service\ElectiveSlotService;
use OCA\Learniq\Service\LessonNoteReader;
use OCA\Learniq\Service\PersonalTimetableService;
use OCA\Learniq\Service\TimetableProjector;
use OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource;
use OCA\Learniq\Timetabling\Source\PlanninqTimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the subject choice picker's slots.
 */
class ElectiveSlotsControllerTest extends TestCase {
	/**
	 * Sessions served by OpenRegister.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $sessions = [];

	/**
	 * Course ids the caller may not read.
	 *
	 * @var array<int,string>
	 */
	private array $hidden = ['course-secret'];

	/**
	 * Every session query, by course id.
	 *
	 * @var array<int,string>
	 */
	private array $courseQueries = [];

	/**
	 * Whether session reads fail.
	 *
	 * @var bool
	 */
	private bool $down = false;

	/**
	 * Every session query the source sent.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $sessionQueries = [];

	/**
	 * Recorded slots and session queries.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__ . '/golden/ElectiveSlots.json';

	/**
	 * Fixtures: on Tuesday 6 January 2026 Drama and Robotics meet at 10:00
	 * Amsterdam time, Maths (alice's core lesson) at 10:30; Art on Thursday.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sessions = [
			['id' => 'd1', 'courseId' => 'course-drama', 'cohortId' => 'c-drama', 'title' => 'Drama', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00'],
			['id' => 'd2', 'courseId' => 'course-drama', 'cohortId' => 'c-drama', 'title' => 'Drama', 'startsAt' => '2026-01-13T09:00:00+00:00', 'endsAt' => '2026-01-13T10:00:00+00:00'],
			['id' => 'r1', 'courseId' => 'course-robotics', 'cohortId' => 'c-rob', 'title' => 'Robotics', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T09:50:00+00:00'],
			['id' => 'a1', 'courseId' => 'course-art', 'cohortId' => 'c-art', 'title' => 'Art', 'startsAt' => '2026-01-08T09:00:00+00:00', 'endsAt' => '2026-01-08T10:00:00+00:00'],
			['id' => 'x1', 'courseId' => 'course-secret', 'cohortId' => 'c-x', 'title' => 'Secret', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00'],
			['id' => 'late', 'courseId' => 'course-art', 'cohortId' => 'c-art', 'title' => 'Art', 'startsAt' => '2026-06-08T09:00:00+00:00', 'endsAt' => '2026-06-08T10:00:00+00:00'],
			['id' => 'm1', 'courseId' => 'course-maths', 'cohortId' => 'c-1', 'title' => 'Maths', 'startsAt' => '2026-01-06T09:30:00+00:00', 'endsAt' => '2026-01-06T10:30:00+00:00'],
		];
	}//end setUp()

	/**
	 * The controller over the real services.
	 *
	 * @param string|null $uid The signed-in user, or null.
	 *
	 * @return ElectiveSlotsController
	 */
	private function controller(?string $uid = 'alice', ?TimetableSourceResolver $resolver = null): ElectiveSlotsController {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (string $id): ObjectEntity {
				if (in_array($id, $this->hidden, true) === true) {
					throw new RuntimeException('not found');
				}

				return $this->createMock(ObjectEntity::class);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$schema = $config['filters']['schema'] ?? '';
				if ($schema === 'cohort') {
					return [['id' => 'c-1', 'learnerIds' => ['alice'], 'teacherIds' => []]];
				}

				if ($schema !== 'session') {
					return [];
				}

				if ($this->down === true) {
					throw new RuntimeException('down');
				}

				$this->sessionQueries[] = $config;
				$filters = array_diff_key($config['filters'], ['register' => true, 'schema' => true]);
				if (isset($filters['courseId']) === true) {
					$this->courseQueries[] = $filters['courseId'];
				}

				return array_values(
					array_filter(
						$this->sessions,
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
		);

		$logger = $this->createMock(LoggerInterface::class);
		$groups = $this->createMock(IGroupManager::class);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('auto');
		$sources = $resolver ?? new TimetableSourceResolver(
			$appConfig,
			new LocalSessionTimetableSource($objects),
			new PlanninqTimetableSource($appManager, $this->createMock(IEventDispatcher::class))
		);
		$projector = new TimetableProjector(logger: $logger, noteReader: new LessonNoteReader($objects, $groups, $logger));

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime('2026-01-05T07:00:00+00:00'));

		return new ElectiveSlotsController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			slots: new ElectiveSlotService(
				objectService: $objects,
				sources: $sources,
				timetable: new PersonalTimetableService(objectService: $objects, projector: $projector, sources: $sources, logger: $logger)
			),
			zone: $zone,
			time: $time,
		);
	}//end controller()

	/**
	 * Drama and Robotics both show Tuesday 10:00 local time; a weekly lesson is one slot.
	 *
	 * @return void
	 */
	public function testElectivesShowTheirWeeklySlotsInLocalTime(): void {
		$data = $this->controller()->slots(courseIds: 'course-drama,course-robotics,course-art')->getData();

		$this->assertSame([['weekday' => 2, 'start' => '10:00', 'end' => '11:00', 'label' => 'Drama']], $data['courses']['course-drama']);
		$this->assertSame([['weekday' => 2, 'start' => '10:00', 'end' => '10:50', 'label' => 'Robotics']], $data['courses']['course-robotics']);
		// The June lesson is outside the four weeks read.
		$this->assertSame([['weekday' => 4, 'start' => '10:00', 'end' => '11:00', 'label' => 'Art']], $data['courses']['course-art']);
		$this->assertSame([], $data['core']);
	}//end testElectivesShowTheirWeeklySlotsInLocalTime()

	/**
	 * A learner choosing for themselves gets their core lessons, without the electives asked about.
	 *
	 * @return void
	 */
	public function testCoreLessonsComeOnlyWhenAsked(): void {
		$data = $this->controller()->slots(courseIds: 'course-drama', withCore: '1')->getData();

		$this->assertSame([['weekday' => 2, 'start' => '10:30', 'end' => '11:30', 'label' => 'Maths']], $data['core']);
	}//end testCoreLessonsComeOnlyWhenAsked()

	/**
	 * A course the caller cannot read is left out, and its lessons are never read.
	 *
	 * @return void
	 */
	public function testAnUnreadableCourseIsLeftOutUnread(): void {
		$data = $this->controller()->slots(courseIds: 'course-secret,course-drama')->getData();

		$this->assertSame(['course-drama'], array_keys($data['courses']));
		$this->assertNotContains('course-secret', $this->courseQueries);
	}//end testAnUnreadableCourseIsLeftOutUnread()

	/**
	 * No user 401, too many courses 400, a source that does not answer 503.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->slots(courseIds: 'course-drama')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->slots(courseIds: implode(',', range(1, 21)))->getStatus());
		$this->down = true;
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller()->slots(courseIds: 'course-drama')->getStatus());
	}//end testRefusals()

	/**
	 * Slots and session queries for courses, window edges and core lessons match the recorded set.
	 *
	 * @return void
	 */
	public function testSlotsMatchGolden(): void {
		$this->sessions = array_merge(
			$this->sessions,
			[
				['id' => 'd-now', 'courseId' => 'course-drama', 'cohortId' => 'c-drama', 'title' => 'Drama now', 'startsAt' => '2026-01-05T07:00:00+00:00', 'endsAt' => '2026-01-05T08:00:00+00:00'],
				['id' => 'd-before', 'courseId' => 'course-drama', 'cohortId' => 'c-drama', 'title' => 'Drama before', 'startsAt' => '2026-01-05T06:59:59+00:00', 'endsAt' => '2026-01-05T08:00:00+00:00'],
				['id' => 'd-last', 'courseId' => 'course-drama', 'cohortId' => 'c-drama', 'title' => 'Drama last', 'startsAt' => '2026-02-02T06:59:59+00:00', 'endsAt' => '2026-02-02T08:00:00+00:00'],
				['id' => 'd-end', 'courseId' => 'course-drama', 'cohortId' => 'c-drama', 'title' => 'Drama end', 'startsAt' => '2026-02-02T07:00:00+00:00', 'endsAt' => '2026-02-02T08:00:00+00:00'],
				['id' => 'd-bad', 'courseId' => 'course-drama', 'cohortId' => 'c-drama', 'title' => 'Drama bad', 'startsAt' => '2026-01-07T09:00:00+00:00', 'endsAt' => '2026-01-07T09:00:00+00:00'],
				['id' => 'm-off', 'courseId' => 'course-maths', 'cohortId' => 'c-1', 'title' => 'Maths off', 'startsAt' => '2026-01-07T09:30:00+00:00', 'endsAt' => '2026-01-07T10:30:00+00:00', 'lifecycle' => 'cancelled'],
				['id' => 'g1', 'courseId' => 'course-gym', 'cohortId' => 'c-1', 'title' => 'Gym', 'startsAt' => '2026-01-09T13:00:00+00:00', 'endsAt' => '2026-01-09T14:00:00+00:00'],
				['id' => 'n1', 'cohortId' => 'c-1', 'title' => 'Mentor', 'startsAt' => '2026-01-09T15:00:00+00:00', 'endsAt' => '2026-01-09T15:30:00+00:00'],
			]
		);
		$source = $this->createMock(TimetableSource::class);
		foreach (['sessionsForCohorts', 'sessionsForTeacher', 'sessionsForCourses'] as $method) {
			$source->method($method)->willReturnCallback(
				function (...$args) use ($method): array {
					$this->sessionQueries[] = [$method => $args];
					return $this->sessions;
				}
			);
		}

		$resolver = $this->createMock(TimetableSourceResolver::class);
		$resolver->method('current')->willReturn($source);
		$cases = [];
		foreach (['course-drama,course-art', '', 'course-secret'] as $ids) {
			$this->sessionQueries = [];
			$data = $this->controller(resolver: $resolver)->slots(courseIds: $ids, withCore: '1')->getData();
			$cases['source ' . $ids] = ['data' => $data, 'queries' => $this->sessionQueries];
		}

		foreach (['course-drama,course-robotics,course-art', 'course-secret,course-drama', '', 'course-maths', 'course-art,course-art,course-gym', 'course-secret'] as $ids) {
			foreach (['0', '1'] as $withCore) {
				$this->sessionQueries = [];
				$data = $this->controller()->slots(courseIds: $ids, withCore: $withCore)->getData();
				$cases[$ids . ' core=' . $withCore] = ['data' => $data, 'queries' => $this->sessionQueries];
			}
		}

		$actual = json_decode((string)json_encode($cases, JSON_UNESCAPED_SLASHES), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		foreach (json_decode((string)file_get_contents(self::GOLDEN), true) as $name => $expected) {
			$this->assertSame($expected, $actual[$name], $name);
		}
	}//end testSlotsMatchGolden()
}//end class
