<?php

/**
 * Unit tests for LtiToolPlacementController.
 *
 * The launch raises integriq's typed `LtiLaunchRequestedEvent` (a stand-in
 * copied from integriq lives under tests/Stubs/Integriq) and answers with the
 * login initiation form the listener sets. Covers: no integriq (503), a refusal
 * (409), nobody answering (503), the event's contents (message type, role,
 * course context, return URL), and the unchanged 401/404/422 paths.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/course-management/spec.md#requirement-lessonplayer-delegates-the-lti-launch-to-integriq-through-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Integriq\Event\LtiLaunchRequestedEvent;
use OCA\Learniq\Controller\LtiToolPlacementController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for LtiToolPlacementController::launch().
 */
class LtiToolPlacementControllerTest extends TestCase {

	/**
	 * Objects by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $objects = [];

	/**
	 * What the listener does with the event: 'form', 'refuse' or 'nothing'.
	 *
	 * @var string
	 */
	private string $listener = 'form';

	/**
	 * The last dispatched event.
	 *
	 * @var LtiLaunchRequestedEvent|null
	 */
	private ?LtiLaunchRequestedEvent $dispatched = null;

	/**
	 * Whether the signed-in user teaches.
	 *
	 * @var bool
	 */
	private bool $instructor = false;

	/**
	 * Build the controller.
	 *
	 * @param bool $integriq  Whether integriq's event class resolves.
	 * @param bool $signedIn  Whether a user is signed in.
	 *
	 * @return LtiToolPlacementController
	 */
	private function controller(bool $integriq = true, bool $signedIn = true): LtiToolPlacementController {
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('pupil1');
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				$row = ($this->objects[(string)$schema][(string)$id] ?? null);
				if ($row === null) {
					throw new DoesNotExistException('not found');
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				return $entity;
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function ($event): void {
				$this->dispatched = $event;
				if ($this->listener === 'form') {
					$event->setLoginInitiation(['formActionUrl' => 'https://tool.example/login', 'method' => 'post', 'fields' => ['login_hint' => 'h', 'iss' => 'i']]);
				}

				if ($this->listener === 'refuse') {
					$event->refuse('tool-not-approved', 'This tool is not approved in integriq.');
				}
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => $this->instructor === true && $group === 'instructors');

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://school.example' . $path);

		return new class(
			$integriq,
			$this->createMock(IRequest::class),
			$session,
			$objects,
			$dispatcher,
			$groups,
			$urls
		) extends LtiToolPlacementController {

			/**
			 * @param bool             $integriq   Whether the event class resolves.
			 * @param IRequest         $request    Request.
			 * @param IUserSession     $session    Session.
			 * @param ObjectService    $objects    Objects.
			 * @param IEventDispatcher $dispatcher Dispatcher.
			 * @param IGroupManager    $groups     Groups.
			 * @param IURLGenerator    $urls       URLs.
			 */
			public function __construct(
				private readonly bool $integriq,
				IRequest $request,
				IUserSession $session,
				ObjectService $objects,
				IEventDispatcher $dispatcher,
				IGroupManager $groups,
				IURLGenerator $urls,
			) {
				parent::__construct(
					request: $request,
					userSession: $session,
					objectService: $objects,
					eventDispatcher: $dispatcher,
					groupManager: $groups,
					urlGenerator: $urls,
					logger: new NullLogger()
				);
			}

			/**
			 * @param string $eventClass The class.
			 *
			 * @return string|null The class, or null without integriq.
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				if ($this->integriq === false) {
					return null;
				}

				return parent::resolveEventClass(eventClass: $eventClass);
			}
		};
	}//end controller()

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = [
			'lti-tool-placement' => [
				'pl1' => ['id' => 'pl1', 'openconnectorDeploymentId' => 'dep-1', 'launchMode' => 'resource-link', 'courseId' => 'c1', 'lessonId' => 'l1'],
				'pl2' => ['id' => 'pl2', 'openconnectorDeploymentId' => '', 'launchMode' => 'resource-link'],
			],
			// Real Course shape: the display name is `name` (lib/Settings/learniq_register.json).
			'course'             => [
				'c1' => ['id' => 'c1', 'code' => 'AK-3H', 'name' => 'Aardrijkskunde'],
				'c2' => ['id' => 'c2', 'code' => 'GS-3H', 'name' => 'Geschiedenis'],
			],
			'lesson'             => ['l2' => ['id' => 'l2', 'courseId' => 'c2', 'name' => 'De Gouden Eeuw', 'contentType' => 'lti']],
		];
	}//end setUp()

	/**
	 * A launch raises the event and answers with the login initiation form.
	 *
	 * @return void
	 */
	public function testLaunchRaisesTheEventAndReturnsTheForm(): void {
		$response = $this->controller()->launch(placementId: 'pl1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			['formActionUrl' => 'https://tool.example/login', 'method' => 'POST', 'fields' => ['login_hint' => 'h', 'iss' => 'i'], 'launchMode' => 'resource-link'],
			$response->getData()
		);
		self::assertSame('learniq', $this->dispatched->getSourceApp());
		self::assertSame('pl1', $this->dispatched->getPlacementId());
		self::assertSame('dep-1', $this->dispatched->getDeploymentUuid());
		self::assertSame('pupil1', $this->dispatched->getUserId());
		self::assertSame('LtiResourceLinkRequest', $this->dispatched->getMessageType());
		self::assertSame('Learner', $this->dispatched->getRole());
		self::assertSame('c1', $this->dispatched->getContextId());
		self::assertSame('Aardrijkskunde', $this->dispatched->getContextTitle());
		self::assertSame('https://school.example/apps/learniq/lessons/l1', $this->dispatched->getReturnUrl());
	}//end testLaunchRaisesTheEventAndReturnsTheForm()

	/**
	 * A lesson-level placement has no courseId; the context is the lesson's course.
	 *
	 * @return void
	 */
	public function testALessonPlacementRunsInTheLessonsCourse(): void {
		$this->objects['lti-tool-placement']['pl3'] = ['id' => 'pl3', 'openconnectorDeploymentId' => 'dep-1', 'launchMode' => 'resource-link', 'courseId' => null, 'lessonId' => 'l2'];

		$this->controller()->launch(placementId: 'pl3');

		self::assertSame('c2', $this->dispatched->getContextId());
		self::assertSame('Geschiedenis', $this->dispatched->getContextTitle());
		self::assertSame('https://school.example/apps/learniq/lessons/l2', $this->dispatched->getReturnUrl());
	}//end testALessonPlacementRunsInTheLessonsCourse()

	/**
	 * The fixture's Course shape is the register's: a `name`, no `title`.
	 *
	 * @return void
	 */
	public function testTheCourseFixtureHasTheRegistersShape(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$course   = null;
		foreach ($register['components']['schemas'] as $schema) {
			if (($schema['slug'] ?? '') === 'course') {
				$course = $schema;
			}
		}

		self::assertArrayHasKey('name', $course['properties']);
		self::assertArrayNotHasKey('title', $course['properties']);
	}//end testTheCourseFixtureHasTheRegistersShape()

	/**
	 * Teaching staff launch as Instructor, and deep linking asks for a deep-linking message.
	 *
	 * @return void
	 */
	public function testInstructorRoleAndDeepLinking(): void {
		$this->instructor = true;
		$this->objects['lti-tool-placement']['pl1']['launchMode'] = 'deep-linking';

		$response = $this->controller()->launch(placementId: 'pl1');

		self::assertSame('deep-linking', $response->getData()['launchMode']);
		self::assertSame('Instructor', $this->dispatched->getRole());
		self::assertSame('LtiDeepLinkingRequest', $this->dispatched->getMessageType());
	}//end testInstructorRoleAndDeepLinking()

	/**
	 * Without integriq the launch answers 503 and raises nothing.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqAnswers503(): void {
		$response = $this->controller(integriq: false)->launch(placementId: 'pl1');

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame('LTI tools need integriq, which is not installed', $response->getData()['error']);
		self::assertNull($this->dispatched);
	}//end testWithoutIntegriqAnswers503()

	/**
	 * A refusal answers 409 with integriq's reason; nobody answering is 503.
	 *
	 * @return void
	 */
	public function testRefusalAndNoAnswer(): void {
		$this->listener = 'refuse';
		$refused = $this->controller()->launch(placementId: 'pl1');
		self::assertSame(Http::STATUS_CONFLICT, $refused->getStatus());
		self::assertSame('This tool is not approved in integriq.', $refused->getData()['error']);

		$this->listener = 'nothing';
		$silent = $this->controller()->launch(placementId: 'pl1');
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $silent->getStatus());
	}//end testRefusalAndNoAnswer()

	/**
	 * Unauthenticated, unknown placement and a placement without a deployment keep their answers.
	 *
	 * @return void
	 */
	public function testGuardPaths(): void {
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(signedIn: false)->launch(placementId: 'pl1')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->launch(placementId: 'missing')->getStatus());
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->launch(placementId: 'pl2')->getStatus());
		self::assertNull($this->dispatched);
	}//end testGuardPaths()

	/**
	 * Run the controller's private answer step on an event.
	 *
	 * @param \OCP\EventDispatcher\Event $event      The answered event.
	 * @param string                    $launchMode The placement's launch mode.
	 *
	 * @return array{0: int, 1: mixed} The status and the data.
	 */
	private function answerOf(\OCP\EventDispatcher\Event $event, string $launchMode): array {
		$response = (new \ReflectionMethod(LtiToolPlacementController::class, 'answer'))->invoke($this->controller(), $event, $launchMode);

		return [$response->getStatus(), $response->getData()];
	}//end answerOf()

	/**
	 * An answered event with the given refusal and login initiation.
	 *
	 * @param mixed $refusal The refusal getRefusal() returns.
	 * @param mixed $form    The form getLoginInitiation() returns.
	 *
	 * @return \OCP\EventDispatcher\Event
	 */
	private function answered(mixed $refusal, mixed $form): \OCP\EventDispatcher\Event {
		return new class ($refusal, $form) extends \OCP\EventDispatcher\Event {
			/**
			 * @param mixed $refusal The refusal.
			 * @param mixed $form    The form.
			 */
			public function __construct(private readonly mixed $refusal, private readonly mixed $form) {
				parent::__construct();
			}

			/**
			 * @return mixed
			 */
			public function getRefusal(): mixed {
				return $this->refusal;
			}

			/**
			 * @return mixed
			 */
			public function getLoginInitiation(): mixed {
				return $this->form;
			}
		};
	}//end answered()

	/**
	 * Every answer to an answered event is exact: a refusal, no form, a form
	 * without an action, a form, and an event that offers neither method.
	 *
	 * @return void
	 */
	public function testEachAnswerToTheEventIsExact(): void {
		$form = ['formActionUrl' => 'https://tool.example/login', 'method' => 'post', 'fields' => ['iss' => 'i']];
		$none = ['error' => 'No LTI launch handler answered'];

		self::assertSame([409, ['error' => 'Not approved', 'code' => 'x']], $this->answerOf($this->answered(['reason' => 'Not approved', 'code' => 'x'], $form), 'resource-link'));
		self::assertSame([409, ['error' => 'The launch was refused', 'code' => '']], $this->answerOf($this->answered([], null), 'resource-link'));
		self::assertSame([503, $none], $this->answerOf($this->answered('refused', null), 'resource-link'));
		self::assertSame([503, $none], $this->answerOf($this->answered(null, ['formActionUrl' => '']), 'resource-link'));
		self::assertSame([503, $none], $this->answerOf($this->answered(null, 'form'), 'resource-link'));
		self::assertSame(
			[200, ['formActionUrl' => 'https://tool.example/login', 'method' => 'POST', 'fields' => ['iss' => 'i'], 'launchMode' => 'deep-linking']],
			$this->answerOf($this->answered(null, $form), 'deep-linking')
		);
		self::assertSame(
			[200, ['formActionUrl' => 'https://tool.example/login', 'method' => 'POST', 'fields' => [], 'launchMode' => 'resource-link']],
			$this->answerOf($this->answered(null, ['formActionUrl' => 'https://tool.example/login']), 'resource-link')
		);
		self::assertSame([503, $none], $this->answerOf(new \OCP\EventDispatcher\Event(), 'resource-link'));
	}//end testEachAnswerToTheEventIsExact()
}//end class
