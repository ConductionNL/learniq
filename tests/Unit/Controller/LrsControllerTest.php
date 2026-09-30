<?php

/**
 * Unit tests for LrsController and XapiStatementIngest.
 *
 * The trust boundary is the point: whoever the statement's own `actor` claims
 * to be, `verified_actor_id` is the authenticated caller (the launch token's
 * subject, or the session user with a valid request token). A caller with no
 * credential stores nothing.
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
 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#3-lrs-ingest-controller
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\LrsController;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\Cmi5LaunchTokenService;
use OCA\Learniq\Service\XapiCallerResolver;
use OCA\Learniq\Service\XapiStatementIngest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the xAPI statement endpoint.
 */
class LrsControllerTest extends TestCase {

	/**
	 * Rows passed to saveObject.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * A statement claiming to be somebody else.
	 *
	 * @var array<string, mixed>
	 */
	private const SPOOFED = [
		'actor'  => ['objectType' => 'Agent', 'account' => ['homePage' => 'https://x', 'name' => 'victim-uuid']],
		'verb'   => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
		'object' => ['id' => 'https://school.example/apps/learniq/lessons/lesson-1'],
	];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return $this->createMock(ObjectEntity::class);
			}
		);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed> $params      Request params (the JSON body).
	 * @param string               $auth        Authorization header.
	 * @param string|null          $sessionUid  Session user, if any.
	 * @param bool                 $csrfOk      Whether the request token passes.
	 * @param array<string, mixed>|null $claims Claims the token verifies to.
	 *
	 * @return LrsController
	 */
	private function controller(array $params, string $auth, ?string $sessionUid, bool $csrfOk, ?array $claims): LrsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === 'Authorization' ? $auth : '');
		$request->method('passesCSRFCheck')->willReturn($csrfOk);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($sessionUid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($sessionUid);
		}

		$session->method('getUser')->willReturn($user);

		$tokens = $this->createMock(Cmi5LaunchTokenService::class);
		$tokens->method('verifyAuthToken')->willReturn($claims);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('tenant-a');

		return new LrsController(
			request: $request,
			userSession: $session,
			groupManager: $this->createMock(IGroupManager::class),
			callers: new XapiCallerResolver(userSession: $session, tokens: $tokens),
			ingest: new XapiStatementIngest(objectService: $this->objectService, tenants: new CallerTenantResolver($config, $this->createMock(ObjectService::class))),
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * An AU with a valid token: the token's subject is stamped, not the payload's actor.
	 *
	 * @return void
	 */
	public function testTokenSubjectIsStampedNotThePayloadActor(): void {
		$response = $this->controller(
			params: self::SPOOFED,
			auth: 'Basic signed.jwt.token',
			sessionUid: null,
			csrfOk: false,
			claims: ['sub' => 'pupil1', 'aud' => 'lesson-1']
		)->postStatements();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertCount(1, $this->saved);
		self::assertSame('pupil1', $this->saved[0]['verified_actor_id']);
		self::assertSame('victim-uuid', $this->saved[0]['actor']['account']['name'], 'the claimed actor is kept as data');
		self::assertSame('lesson-1', $this->saved[0]['lessonId']);
		self::assertSame('tenant-a', $this->saved[0]['tenant_id']);
		self::assertSame($response->getData(), [$this->saved[0]['id']]);
	}//end testTokenSubjectIsStampedNotThePayloadActor()

	/**
	 * A signed-in learner with a valid request token: their uid is stamped.
	 *
	 * @return void
	 */
	public function testSessionCallerIsStamped(): void {
		$response = $this->controller(params: self::SPOOFED, auth: '', sessionUid: 'pupil2', csrfOk: true, claims: null)->postStatements();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('pupil2', $this->saved[0]['verified_actor_id']);
	}//end testSessionCallerIsStamped()

	/**
	 * No credential, a bad token, or a session without a request token: 401 and nothing stored.
	 *
	 * @return void
	 */
	public function testUnauthenticatedCallersStoreNothing(): void {
		$cases = [
			'no credential'         => [null, false, '', null],
			'invalid token'         => ['pupil2', true, 'Bearer forged', null],
			'session without CSRF'  => ['pupil2', false, '', null],
		];
		foreach ($cases as $label => [$uid, $csrf, $auth, $claims]) {
			$response = $this->controller(params: self::SPOOFED, auth: $auth, sessionUid: $uid, csrfOk: $csrf, claims: $claims)->postStatements();
			self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus(), $label);
		}

		self::assertSame([], $this->saved);
	}//end testUnauthenticatedCallersStoreNothing()

	/**
	 * A statement id already held by a different statement answers 409 and stores nothing.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#9-statement-id-conflicts
	 *
	 * @return void
	 */
	public function testKnownIdWithADifferentStatementAnswers409(): void {
		$id     = '7a394703-09fd-436b-9c90-78da537af5a5';
		$stored = $this->createMock(ObjectEntity::class);
		$stored->method('jsonSerialize')->willReturn(self::SPOOFED + ['id' => $id, 'verified_actor_id' => 'pupil1', 'result' => ['success' => false]]);
		$this->objectService->method('find')->willReturn($stored);

		$response = $this->controller(
			params: self::SPOOFED + ['id' => $id],
			auth: 'Basic signed.jwt.token',
			sessionUid: null,
			csrfOk: false,
			claims: ['sub' => 'pupil1', 'aud' => 'lesson-1']
		)->postStatements();

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame([], $this->saved);
	}//end testKnownIdWithADifferentStatementAnswers409()

	/**
	 * A statement without a verb id is refused with 400.
	 *
	 * @return void
	 */
	public function testMalformedStatementIsRefused(): void {
		$bad = self::SPOOFED;
		unset($bad['verb']);
		$bad['actor'] = self::SPOOFED['actor'];

		$response = $this->controller(params: [$bad], auth: '', sessionUid: 'pupil2', csrfOk: true, claims: null)->postStatements();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([], $this->saved);
	}//end testMalformedStatementIsRefused()

	/**
	 * A non-admin query is scoped to the caller's own statements.
	 *
	 * @return void
	 */
	public function testQueryIsScopedToTheCaller(): void {
		$this->objectService->expects(self::once())->method('findAll')->with(
			self::callback(static fn (array $config): bool => ($config['filters']['verified_actor_id'] ?? null) === 'pupil2')
		)->willReturn([]);

		$response = $this->controller(params: [], auth: '', sessionUid: 'pupil2', csrfOk: true, claims: null)->getStatements();

		self::assertSame(['statements' => []], $response->getData());
	}//end testQueryIsScopedToTheCaller()
}//end class
