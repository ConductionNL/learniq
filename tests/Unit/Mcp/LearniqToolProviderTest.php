<?php

/**
 * Unit tests for LearniqToolProvider.
 *
 * Covers: getAppId, the tool catalogue shape, invokeTool dispatch (incl. the
 * unknown-tool error envelope — must not throw), and the auth gate that rejects
 * anonymous callers before any data read.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Mcp;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Mcp\CourseToolPresenter;
use OCA\Learniq\Mcp\LearniqToolProvider;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit test suite for LearniqToolProvider.
 *
 * Every test runs in isolation with mocked services. The stub at
 * tests/Stubs/Mcp/IMcpToolProvider.php satisfies the interface declaration
 * when the openregister runtime (PR #1466) is absent.
 */
class LearniqToolProviderTest extends TestCase {

	/**
	 * Provider under test.
	 *
	 * @var LearniqToolProvider
	 */
	private LearniqToolProvider $provider;

	/**
	 * Mock OpenRegister ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up mocks and the provider before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->provider = new LearniqToolProvider(
			$this->objectService,
			$this->userSession,
			$this->groupManager,
			$this->logger,
			new CourseToolPresenter(),
		);

	}//end setUp()

	/**
	 * getAppId() returns the learniq slug.
	 *
	 * @return void
	 */
	public function testGetAppIdReturnsLearniq(): void {
		$this->assertSame('learniq', $this->provider->getAppId());

	}//end testGetAppIdReturnsLearniq()

	/**
	 * getTools() returns exactly the two MVP descriptors with valid shape.
	 *
	 * @return void
	 */
	public function testGetToolsCatalogueShape(): void {
		$tools = $this->provider->getTools();

		$this->assertCount(2, $tools);

		$ids = array_column($tools, 'id');
		$this->assertSame(['learniq.listCourses', 'learniq.getCourseDetails'], $ids);

		foreach ($tools as $tool) {
			$this->assertArrayHasKey('id', $tool);
			$this->assertArrayHasKey('name', $tool);
			$this->assertArrayHasKey('description', $tool);
			$this->assertArrayHasKey('inputSchema', $tool);

			$this->assertIsString($tool['id']);
			$this->assertStringStartsWith('learniq.', $tool['id']);
			$this->assertIsString($tool['name']);
			$this->assertNotSame('', $tool['name']);
			$this->assertIsString($tool['description']);
			$this->assertNotSame('', $tool['description']);

			$this->assertIsArray($tool['inputSchema']);
			$this->assertSame('object', $tool['inputSchema']['type']);
			$this->assertArrayHasKey('properties', $tool['inputSchema']);
			$this->assertIsArray($tool['inputSchema']['properties']);
			$this->assertArrayHasKey('required', $tool['inputSchema']);
			$this->assertIsArray($tool['inputSchema']['required']);
		}

	}//end testGetToolsCatalogueShape()

	/**
	 * getCourseDetails declares `id` as a required argument.
	 *
	 * @return void
	 */
	public function testGetCourseDetailsRequiresId(): void {
		$tools = $this->provider->getTools();
		$byId = array_column($tools, null, 'id');
		$schema = $byId['learniq.getCourseDetails']['inputSchema'];

		$this->assertSame(['id'], $schema['required']);

	}//end testGetCourseDetailsRequiresId()

	/**
	 * invokeTool() with an unknown tool id returns a structured error and does
	 * NOT throw.
	 *
	 * @return void
	 */
	public function testInvokeUnknownToolReturnsErrorArray(): void {
		$result = $this->provider->invokeTool('learniq.bogus', []);

		$this->assertIsArray($result);
		$this->assertTrue($result['isError'] ?? false);
		$this->assertSame('unknown_tool', $result['error'] ?? null);
		$this->assertIsString($result['message'] ?? null);

	}//end testInvokeUnknownToolReturnsErrorArray()

	/**
	 * listCourses with an invalid limit is rejected before any auth/data work.
	 *
	 * @return void
	 */
	public function testListCoursesRejectsInvalidLimit(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$result = $this->provider->invokeTool('learniq.listCourses', ['limit' => 999]);

		$this->assertTrue($result['isError'] ?? false);
		$this->assertSame('invalid_arguments', $result['error'] ?? null);

	}//end testListCoursesRejectsInvalidLimit()

	/**
	 * listCourses with no authenticated user is forbidden and reads no data.
	 *
	 * @return void
	 */
	public function testListCoursesForbiddenWhenAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectService->expects($this->never())->method('findAll');

		$result = $this->provider->invokeTool('learniq.listCourses', []);

		$this->assertTrue($result['isError'] ?? false);
		$this->assertSame('forbidden', $result['error'] ?? null);

	}//end testListCoursesForbiddenWhenAnonymous()

	/**
	 * A session whose user reports an EMPTY uid is refused exactly as an
	 * anonymous caller is, and reads no data.
	 *
	 * This is the arm that made `requireCourseReadAccess()`'s final
	 * `return $userId !== ''` unreachable-as-false: the empty-uid case has
	 * already returned by then. Pinning it here is what lets that final
	 * statement be written as a plain `return true` without the deny path
	 * quietly disappearing.
	 *
	 * @return void
	 */
	public function testListCoursesForbiddenWhenUidIsEmpty(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('');
		$this->userSession->method('getUser')->willReturn($user);
		$this->objectService->expects($this->never())->method('findAll');

		$result = $this->provider->invokeTool('learniq.listCourses', []);

		$this->assertTrue($result['isError'] ?? false);
		$this->assertSame('forbidden', $result['error'] ?? null);

	}//end testListCoursesForbiddenWhenUidIsEmpty()

	/**
	 * An authenticated NON-admin is allowed through the provider boundary.
	 *
	 * That is deliberate, not an oversight: OpenRegister's own RBAC (inside
	 * ObjectService, with `_rbac`/`_multitenancy` left at their defaults) is
	 * the per-object gate that scopes which courses this user actually sees.
	 * Pins the allow half of the boundary decision.
	 *
	 * @return void
	 */
	public function testListCoursesAllowedForAuthenticatedNonAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('learner1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('learner1')->willReturn(false);
		$this->objectService->expects($this->atLeastOnce())->method('findAll')->willReturn([]);

		$result = $this->provider->invokeTool('learniq.listCourses', []);

		$this->assertFalse($result['isError'] ?? false);
		$this->assertNull($result['error'] ?? null);

	}//end testListCoursesAllowedForAuthenticatedNonAdmin()

	/**
	 * getCourseDetails without the required id argument is rejected.
	 *
	 * @return void
	 */
	public function testGetCourseDetailsRejectsMissingId(): void {
		$result = $this->provider->invokeTool('learniq.getCourseDetails', []);

		$this->assertTrue($result['isError'] ?? false);
		$this->assertSame('invalid_arguments', $result['error'] ?? null);

	}//end testGetCourseDetailsRejectsMissingId()

	/**
	 * getCourseDetails with an authenticated admin returns a privacy-safe course
	 * payload (no learner data) when the course exists.
	 *
	 * @return void
	 */
	public function testGetCourseDetailsReturnsCoursePayload(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('teacher1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('teacher1')->willReturn(true);

		$courseUuid = '11111111-1111-1111-1111-111111111111';

		$this->objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($courseUuid): array {
				if (($config['schema'] ?? null) === 'course') {
					return [
						[
							'uuid' => $courseUuid,
							'code' => 'NIS2-AWARENESS-2026',
							'name' => 'NIS2 Awareness Training',
							'lifecycle' => 'published',
							'mandatoryTraining' => true,
						],
					];
				}

				if (($config['schema'] ?? null) === 'lesson') {
					return [
						['uuid' => 'aaa', 'name' => 'Module 1', 'order' => 1, 'contentType' => 'cmi5'],
						['uuid' => 'bbb', 'name' => 'Module 2', 'order' => 2, 'contentType' => 'text'],
					];
				}

				return [];
			}
		);

		$result = $this->provider->invokeTool('learniq.getCourseDetails', ['id' => 'nis2-awareness-2026']);

		$this->assertTrue($result['success'] ?? false);
		$this->assertSame($courseUuid, $result['course']['uuid']);
		$this->assertSame('NIS2 Awareness Training', $result['course']['name']);
		$this->assertCount(2, $result['modules']);
		$this->assertSame(1, $result['modules'][0]['order']);

		// No learner / enrolment / credential keys must leak through.
		$serialised = json_encode($result);
		$this->assertIsString($serialised);
		$this->assertStringNotContainsStringIgnoringCase('learner', $serialised);
		$this->assertStringNotContainsStringIgnoringCase('enrolment', $serialised);
		$this->assertStringNotContainsStringIgnoringCase('credential', $serialised);
		$this->assertStringNotContainsStringIgnoringCase('attestation', $serialised);

	}//end testGetCourseDetailsReturnsCoursePayload()

}//end class
