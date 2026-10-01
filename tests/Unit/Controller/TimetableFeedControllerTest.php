<?php

/**
 * Unit tests for TimetableFeedController.
 *
 * The feed is built from the real sibling classes (PersonalTimetableService,
 * TimetableProjector, the timetable sources, TimetableVisibilityService over
 * TimetableDirectory, TimetableFeedEventBuilder, TimetableIcsWriter and
 * TimetableFeedTokenService); only OpenRegister and Nextcloud's edges are
 * doubles. Every feed body is parsed with sabre/vobject, the iCalendar parser
 * Nextcloud itself uses.
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

use Generator;
use OCA\Learniq\Controller\TimetableFeedController;
use OCA\Learniq\Service\LessonNoteReader;
use OCA\Learniq\Service\PersonalTimetableService;
use OCA\Learniq\Service\TimetableDirectory;
use OCA\Learniq\Service\TimetableFeedEventBuilder;
use OCA\Learniq\Service\TimetableFeedTokenService;
use OCA\Learniq\Service\TimetableIcsWriter;
use OCA\Learniq\Service\TimetableProjector;
use OCA\Learniq\Service\TimetableVisibilityService;
use OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource;
use OCA\Learniq\Timetabling\Source\PlanninqTimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * Tests for the calendar feed: token, read scope, iCalendar output and revocation.
 */
class TimetableFeedControllerTest extends TestCase {
	/**
	 * Stored user preferences: uid => key => value.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $prefs = [];

	/**
	 * The request's active user (null = anonymous, as a calendar app is).
	 *
	 * @var IUser|null
	 */
	private ?IUser $active = null;

	/**
	 * Every volatile user set, in order (uid or null).
	 *
	 * @var array<int,string|null>
	 */
	private array $volatile = [];

	/**
	 * The active user's uid at each OpenRegister read.
	 *
	 * @var array<int,string|null>
	 */
	private array $readsAs = [];

	/**
	 * Cohorts, enrolments, sessions, rooms and the visibility policy served by OpenRegister.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $data = [];

	/**
	 * Whether every session read fails, as when the source does not answer.
	 *
	 * @var bool
	 */
	private bool $sourceDown = false;

	/**
	 * Users that exist: uid => enabled.
	 *
	 * @var array<string,bool>
	 */
	private array $users = ['alice' => true, 'sam' => true, 'tom' => true];

	/**
	 * Counter for the token generator.
	 *
	 * @var int
	 */
	private int $issued = 0;

	/**
	 * Fixtures: alice learns in cohort-1; cohort-2 is someone else's.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->data = [
			'cohort' => [
				['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => ['tom']],
				['id' => 'cohort-2', 'learnerIds' => ['carol'], 'teacherIds' => ['tom']],
			],
			'enrolment' => [],
			'session' => [
				['id' => 's-maths', 'cohortId' => 'cohort-1', 'title' => 'Maths', 'startsAt' => '2026-01-08T09:00:00+00:00', 'endsAt' => '2026-01-08T10:00:00+00:00', 'roomId' => 'room-1', 'lifecycle' => 'scheduled'],
				['id' => 's-bio', 'cohortId' => 'cohort-1', 'title' => 'Biology', 'startsAt' => '2026-01-09T09:00:00+00:00', 'endsAt' => '2026-01-09T10:00:00+00:00', 'location' => 'Lab', 'lifecycle' => 'cancelled', 'changeReasonKind' => 'teacher-absence', 'changeReason' => 'Mr Tom is ill'],
				['id' => 's-art', 'cohortId' => 'cohort-1', 'title' => 'Art', 'startsAt' => '2026-01-12T13:00:00+00:00', 'endsAt' => '2026-01-12T14:00:00+00:00', 'location' => 'Studio', 'lifecycle' => 'scheduled', 'substituteTeacherId' => 'sam', 'changeReasonKind' => 'teacher-absence'],
				['id' => 's-secret', 'cohortId' => 'cohort-2', 'title' => 'Secret', 'startsAt' => '2026-01-08T09:00:00+00:00', 'endsAt' => '2026-01-08T10:00:00+00:00', 'location' => 'Room 9', 'lifecycle' => 'scheduled'],
				['id' => 's-far', 'cohortId' => 'cohort-1', 'title' => 'Next year', 'startsAt' => '2027-01-08T09:00:00+00:00', 'endsAt' => '2027-01-08T10:00:00+00:00', 'lifecycle' => 'scheduled'],
			],
			'room' => [
				['id' => 'room-1', 'name' => 'B 1.12'],
			],
			'timetable-visibility-policy' => [],
		];
	}//end setUp()

	/**
	 * Build the controller from the real sibling classes.
	 *
	 * @return TimetableFeedController
	 */
	private function controller(): TimetableFeedController {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$this->readsAs[] = $this->active?->getUID();
				$schema = (string)($config['filters']['schema'] ?? '');
				if ($schema === 'session' && $this->sourceDown === true) {
					throw new RuntimeException('source down');
				}

				$filters = array_diff_key(($config['filters'] ?? []), ['register' => true, 'schema' => true]);
				$rows = ($this->data[$schema] ?? []);
				if (isset($config['ids']) === true) {
					$rows = array_filter($rows, static fn (array $r): bool => in_array(($r['id'] ?? null), $config['ids'], true));
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
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
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturn(false);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid): ?IUser => isset($this->users[$uid]) === true ? $this->user($uid) : null);
		$userManager->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => ['sam' => 'Sam Substitute', 'tom' => 'Tom Teacher'][$uid] ?? null);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('auto');
		$sources = new TimetableSourceResolver(
			$appConfig,
			new LocalSessionTimetableSource($objects),
			new PlanninqTimetableSource($appManager, $this->createMock(IEventDispatcher::class))
		);

		$timetable = new PersonalTimetableService(
			objectService: $objects,
			projector: new TimetableProjector(logger: $logger, noteReader: new LessonNoteReader($objects, $groups, $logger)),
			sources: $sources,
			logger: $logger
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->active);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->volatile[] = $user?->getUID();
				$this->active = $user;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('getUserLanguage')->willReturn('en');
		$factory->method('get')->willReturn($l10n);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args): string => 'https://cloud.example/apps/learniq/api/timetable/feed/' . $args['token'] . '.ics#' . $route
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime('2026-01-07T08:00:00+00:00'));

		return new TimetableFeedController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			userManager: $userManager,
			tokens: new TimetableFeedTokenService($this->userConfig(), $this->random()),
			timetable: $timetable,
			events: new TimetableFeedEventBuilder(
				new TimetableVisibilityService(new TimetableDirectory($objects, $sources), $groups, $userManager),
				$userManager
			),
			writer: new TimetableIcsWriter(),
			urls: $urls,
			l10nFactory: $factory,
			time: $time,
			logger: $logger,
		);
	}//end controller()

	/**
	 * A user double.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturnCallback(fn (): bool => ($this->users[$uid] ?? false));
		return $user;
	}//end user()

	/**
	 * User preferences backed by $prefs, searchable as Nextcloud's are.
	 *
	 * @return IUserConfig
	 */
	private function userConfig(): IUserConfig {
		$config = $this->createMock(IUserConfig::class);
		$config->method('setValueString')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): bool {
				$this->prefs[$uid][$app . '/' . $key] = $value;
				return true;
			}
		);
		$config->method('getValueString')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = ''): string => ($this->prefs[$uid][$app . '/' . $key] ?? $default)
		);
		$config->method('deleteUserConfig')->willReturnCallback(
			function (string $uid, string $app, string $key): void {
				unset($this->prefs[$uid][$app . '/' . $key]);
			}
		);
		$config->method('searchUsersByValueString')->willReturnCallback(
			function (string $app, string $key, string $value): Generator {
				foreach ($this->prefs as $uid => $values) {
					if (($values[$app . '/' . $key] ?? null) === $value) {
						yield $uid;
					}
				}
			}
		);
		return $config;
	}//end userConfig()

	/**
	 * A random source giving a different 64-character token each call.
	 *
	 * @return ISecureRandom
	 */
	private function random(): ISecureRandom {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			function (int $length): string {
				$this->issued++;
				return substr(hash('sha256', 'token-' . $this->issued), 0, $length);
			}
		);
		return $random;
	}//end random()

	/**
	 * Sign a user in for the address calls, and return their new feed token.
	 *
	 * @param TimetableFeedController $controller The controller.
	 * @param string                  $uid        The user.
	 *
	 * @return string The token in the address.
	 */
	private function subscribe(TimetableFeedController $controller, string $uid): string {
		$this->active = $this->user($uid);
		$response = $controller->create();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$url = (string)$response->getData()['url'];
		$this->assertMatchesRegularExpression('#/api/timetable/feed/[a-f0-9]{64}\.ics\#learniq\.timetableFeed\.feed$#', $url);
		$this->assertStringStartsWith('webcal://cloud.example/', (string)$response->getData()['webcalUrl']);
		$this->active = null;
		$this->volatile = [];
		$this->readsAs = [];

		preg_match('#feed/([a-f0-9]{64})\.ics#', $url, $match);
		return $match[1];
	}//end subscribe()

	/**
	 * The headers the response set itself. Response::getHeaders() merges in
	 * server headers through \OC, which a unit run does not have.
	 *
	 * @param DataDisplayResponse $response The response.
	 *
	 * @return array<string,string>
	 */
	private function headersOf(DataDisplayResponse $response): array {
		return (array)(new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headersOf()

	/**
	 * Parse a feed body with sabre/vobject.
	 *
	 * @param DataDisplayResponse $response The feed response.
	 *
	 * @return array<string,\Sabre\VObject\Component\VEvent> Events keyed by UID.
	 */
	private function events(DataDisplayResponse $response): array {
		$calendar = Reader::read($response->render(), Reader::OPTION_FORGIVING);
		$this->assertInstanceOf(VCalendar::class, $calendar);
		$this->assertSame([], $calendar->validate(), 'the feed is valid iCalendar');
		$events = [];
		foreach ($calendar->select('VEVENT') as $event) {
			$events[(string)$event->UID] = $event;
		}

		return $events;
	}//end events()

	/**
	 * A learner's feed holds their lessons for the window, with the room, the
	 * cancelled lesson cancelled, and nothing of another group.
	 *
	 * @return void
	 */
	public function testFeedServesTheOwnersLessonsAsICalendar(): void {
		$controller = $this->controller();
		$token = $this->subscribe($controller, 'alice');

		$response = $controller->feed($token);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('text/calendar; charset=utf-8', $this->headersOf($response)['Content-Type']);
		$events = $this->events($response);
		$this->assertSame(['s-maths@learniq', 's-bio@learniq', 's-art@learniq'], array_keys($events));

		$maths = $events['s-maths@learniq'];
		$this->assertSame('Maths', (string)$maths->SUMMARY);
		$this->assertSame('B 1.12', (string)$maths->LOCATION);
		$this->assertSame('CONFIRMED', (string)$maths->STATUS);
		$this->assertSame('20260108T090000Z', $maths->DTSTART->getValue());
		$this->assertSame('20260108T100000Z', $maths->DTEND->getValue());

		$bio = $events['s-bio@learniq'];
		$this->assertSame('CANCELLED', (string)$bio->STATUS);
		$this->assertSame('Cancelled: Biology', (string)$bio->SUMMARY);
		$this->assertStringContainsString('Reason: teacher absent', (string)$bio->DESCRIPTION);
		// The free-text reason can name a person; it never leaves the app.
		$this->assertStringNotContainsString('Mr Tom', $response->render());
	}//end testFeedServesTheOwnersLessonsAsICalendar()

	/**
	 * The read runs as the token's owner and the request is anonymous again afterwards.
	 *
	 * @return void
	 */
	public function testFeedReadsAsTheOwnerAndClearsTheVolatileUser(): void {
		$controller = $this->controller();
		$token = $this->subscribe($controller, 'alice');

		$controller->feed($token);

		$this->assertSame(['alice', null], $this->volatile);
		$this->assertNotEmpty($this->readsAs);
		$this->assertSame(['alice'], array_values(array_unique($this->readsAs)));
		$this->assertNull($this->active);
	}//end testFeedReadsAsTheOwnerAndClearsTheVolatileUser()

	/**
	 * A source that does not answer gives 503, and the volatile user is still cleared.
	 *
	 * @return void
	 */
	public function testSourceDownAnswers503AndClearsTheVolatileUser(): void {
		$controller = $this->controller();
		$token = $this->subscribe($controller, 'alice');
		$this->sourceDown = true;

		$response = $controller->feed($token);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['alice', null], $this->volatile);
		$this->assertNull($this->active);
	}//end testSourceDownAnswers503AndClearsTheVolatileUser()

	/**
	 * A wrong, malformed or empty token answers 404 and reads nothing.
	 *
	 * @return void
	 */
	public function testWrongTokenAnswers404AndReadsNothing(): void {
		$controller = $this->controller();
		$this->subscribe($controller, 'alice');

		foreach ([str_repeat('a', 64), 'not-a-token', '', str_repeat('A', 64)] as $token) {
			$response = $controller->feed($token);
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(), $token);
			$this->assertSame('', $response->render());
		}

		$this->assertSame([], $this->readsAs);
		$this->assertSame([], $this->volatile);
	}//end testWrongTokenAnswers404AndReadsNothing()

	/**
	 * Reset: a new address makes the old one 404 at once; revoking ends the new one.
	 *
	 * @return void
	 */
	public function testResetMakesTheOldAddressAnswer404(): void {
		$controller = $this->controller();
		$old = $this->subscribe($controller, 'alice');
		$new = $this->subscribe($controller, 'alice');

		$this->assertNotSame($old, $new);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->feed($old)->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->feed($new)->getStatus());

		$this->active = $this->user('alice');
		$this->assertSame(['exists' => true], $controller->status()->getData());
		$this->assertSame(['exists' => false], $controller->revoke()->getData());
		$this->assertSame(['exists' => false], $controller->status()->getData());
		$this->active = null;
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->feed($new)->getStatus());
	}//end testResetMakesTheOldAddressAnswer404()

	/**
	 * Only a hash of the token is stored.
	 *
	 * @return void
	 */
	public function testOnlyTheTokenHashIsStored(): void {
		$controller = $this->controller();
		$token = $this->subscribe($controller, 'alice');

		$stored = $this->prefs['alice']['learniq/' . TimetableFeedTokenService::CONFIG_KEY];
		$this->assertSame(hash('sha256', $token), $stored);
		$this->assertStringNotContainsString($token, serialize($this->prefs));
	}//end testOnlyTheTokenHashIsStored()

	/**
	 * A disabled or deleted owner's address answers 404.
	 *
	 * @return void
	 */
	public function testDisabledOrDeletedOwnerAnswers404(): void {
		$controller = $this->controller();
		$token = $this->subscribe($controller, 'alice');

		$this->users['alice'] = false;
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->feed($token)->getStatus());

		unset($this->users['alice']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->feed($token)->getStatus());
		$this->assertSame([], $this->volatile);
	}//end testDisabledOrDeletedOwnerAnswers404()

	/**
	 * A policy that hides teachers from learners keeps the substitute's name out.
	 *
	 * @return void
	 */
	public function testHiddenTeachersPolicyKeepsTheSubstitutesNameOut(): void {
		$this->data['timetable-visibility-policy'] = [['id' => 'p-1', 'learnerSeesTeachers' => 'none']];
		$controller = $this->controller();
		$token = $this->subscribe($controller, 'alice');

		$response = $controller->feed($token);

		$art = $this->events($response)['s-art@learniq'];
		$this->assertStringContainsString('A substitute teacher covers this lesson.', (string)$art->DESCRIPTION);
		$this->assertStringNotContainsString('Sam', $response->render());
		$this->assertStringNotContainsString('sam', strtolower((string)$art->DESCRIPTION));
	}//end testHiddenTeachersPolicyKeepsTheSubstitutesNameOut()

	/**
	 * A policy that shows every teacher names the substitute.
	 *
	 * @return void
	 */
	public function testOpenTeachersPolicyNamesTheSubstitute(): void {
		$this->data['timetable-visibility-policy'] = [['id' => 'p-1', 'learnerSeesTeachers' => 'all']];
		$controller = $this->controller();
		$token = $this->subscribe($controller, 'alice');

		$art = $this->events($controller->feed($token))['s-art@learniq'];

		$this->assertSame('CONFIRMED', (string)$art->STATUS);
		$this->assertStringContainsString('Substitute teacher: Sam Substitute', (string)$art->DESCRIPTION);
	}//end testOpenTeachersPolicyNamesTheSubstitute()

	/**
	 * The address calls need a signed-in user.
	 *
	 * @return void
	 */
	public function testAddressCallsNeedASignedInUser(): void {
		$controller = $this->controller();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->status()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->create()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->revoke()->getStatus());
		$this->assertSame([], $this->prefs);
	}//end testAddressCallsNeedASignedInUser()
}//end class
