<?php

/**
 * Tests for the xAPI State and Agent Profile resources.
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
 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\LrsDocumentController;
use OCA\Learniq\Service\XapiCallerResolver;
use OCA\Learniq\Service\XapiDocumentCodec;
use OCA\Learniq\Service\XapiDocumentRequest;
use OCA\Learniq\Service\XapiDocumentStore;
use OCA\Learniq\Service\XapiRequestBody;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\Learniq\Tests\Support\XapiDocumentsInMemory;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * Every verb on both resources, learner scoping and the concurrency headers.
 */
class LrsDocumentControllerTest extends TestCase {
	use XapiDocumentsInMemory;

	/**
	 * The launched activity.
	 *
	 * @var string
	 */
	private const ACTIVITY = 'https://school.example/apps/learniq/lessons/lesson-1';

	/**
	 * The launch registration.
	 *
	 * @var string
	 */
	private const REGISTRATION = '0f8e7a2c-1b3d-4c5e-9f60-718293a4b5c6';

	/**
	 * The rows, shared by every controller a test builds.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $rows;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rows = new RegisterFaithfulStore();
	}//end setUp()

	/**
	 * Build the controller for one request.
	 *
	 * @param array<string, string> $query   Query-string parameters.
	 * @param string                $body    Raw body.
	 * @param array<string, string> $headers Request headers.
	 * @param string|null           $actorId The authenticated learner, or null for none.
	 *
	 * @return LrsDocumentController
	 */
	private function controller(array $query, string $body = '', array $headers = [], ?string $actorId = 'pupil1'): LrsDocumentController {
		$request = $this->createMock(IRequest::class);
		$request->method('getRequestUri')->willReturn('/apps/learniq/api/lrs/activities/state?' . http_build_query($query));
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $headers[$name] ?? '');
		// A JSON body is decoded into the parameters by Nextcloud; it must never address a document.
		$request->method('getParam')->willReturn('injected');
		$request->method('getParams')->willReturn(['stateId' => 'injected', 'registration' => 'injected']);

		$callers  = $this->createMock(XapiCallerResolver::class);
		$identity = null;
		if ($actorId !== null) {
			$identity = ['actorId' => $actorId, 'launch' => ['lessonId' => 'lesson-1', 'courseId' => '', 'activityId' => self::ACTIVITY, 'registration' => self::REGISTRATION]];
		}

		$callers->method('resolve')->willReturn($identity);

		$raw = $this->createMock(XapiRequestBody::class);
		$raw->method('read')->willReturn($body);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('tenant-a');
		$documents = new XapiDocumentStore(objectService: $this->xapiObjectService(store: $this->rows), config: $config, codec: new XapiDocumentCodec());

		return new LrsDocumentController(
			request: $request,
			callers: $callers,
			documents: $documents,
			reader: new XapiDocumentRequest(documents: $documents, body: $raw),
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * The state query for pupil1.
	 *
	 * @param string $stateId The stateId, or '' for none.
	 *
	 * @return array<string, string>
	 */
	private function stateQuery(string $stateId = 'bookmark'): array {
		$query = [
			'activityId'   => self::ACTIVITY,
			'agent'        => '{"objectType":"Agent","account":{"homePage":"https://school.example/","name":"pupil1"}}',
			'registration' => self::REGISTRATION,
		];
		if ($stateId !== '') {
			$query['stateId'] = $stateId;
		}

		return $query;
	}//end stateQuery()

	/**
	 * The agent profile query for pupil1.
	 *
	 * @param string $profileId The profileId, or '' for none.
	 *
	 * @return array<string, string>
	 */
	private function profileQuery(string $profileId = 'cmi5LearnerPreferences'): array {
		$query = ['agent' => '{"objectType":"Agent","account":{"homePage":"https://school.example/","name":"pupil1"}}'];
		if ($profileId !== '') {
			$query['profileId'] = $profileId;
		}

		return $query;
	}//end profileQuery()

	/**
	 * The headers the response set itself. Response::getHeaders() merges in
	 * server defaults through \OCP\Server, which the unit environment lacks.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private static function headersOf(Response $response): array {
		return (array)(new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headersOf()

	/**
	 * Assert status and the xAPI version header.
	 *
	 * @param int      $status   The expected status.
	 * @param Response $response The response.
	 *
	 * @return void
	 */
	private static function assertAnswer(int $status, Response $response): void {
		self::assertSame($status, $response->getStatus());
		self::assertSame('1.0.3', self::headersOf($response)['X-Experience-API-Version'] ?? null);
	}//end assertAnswer()

	/**
	 * PUT stores a state document; GET returns it with its content type and ETag.
	 *
	 * @return void
	 */
	public function testPutThenGetState(): void {
		$put = $this->controller(query: $this->stateQuery(), body: '{"page":3}', headers: ['Content-Type' => 'application/json'])->putState();
		self::assertAnswer(Http::STATUS_NO_CONTENT, $put);
		self::assertSame('"' . sha1('{"page":3}') . '"', self::headersOf($put)['ETag'] ?? null);

		$get = $this->controller(query: $this->stateQuery())->getState();
		self::assertAnswer(Http::STATUS_OK, $get);
		self::assertInstanceOf(DataDisplayResponse::class, $get);
		self::assertSame('{"page":3}', $get->render());
		self::assertSame('application/json', self::headersOf($get)['Content-Type'] ?? null);
		self::assertSame('"' . sha1('{"page":3}') . '"', self::headersOf($get)['ETag'] ?? null);
		self::assertSame('bookmark', $this->rows->rows['xapi-document'][0]['documentId'], 'the query addressed the document, not the decoded body');
	}//end testPutThenGetState()

	/**
	 * GET of a missing state is 404; GET without stateId lists the stateIds.
	 *
	 * @return void
	 */
	public function testGetMissingAndListState(): void {
		self::assertAnswer(Http::STATUS_NOT_FOUND, $this->controller(query: $this->stateQuery('nothing'))->getState());

		$this->controller(query: $this->stateQuery('b'), body: '1')->putState();
		$this->controller(query: $this->stateQuery('a'), body: '2')->putState();
		$list = $this->controller(query: $this->stateQuery(''))->getState();

		self::assertAnswer(Http::STATUS_OK, $list);
		self::assertSame(['a', 'b'], $list->getData());
		self::assertAnswer(Http::STATUS_BAD_REQUEST, $this->controller(query: $this->stateQuery('') + ['since' => 'not a date'])->getState());
	}//end testGetMissingAndListState()

	/**
	 * POST merges JSON objects; a non-object body is 400.
	 *
	 * @return void
	 */
	public function testPostStateMerges(): void {
		$this->controller(query: $this->stateQuery(), body: '{"a":1}')->postState();
		$post = $this->controller(query: $this->stateQuery(), body: '{"b":2}')->postState();

		self::assertAnswer(Http::STATUS_NO_CONTENT, $post);
		self::assertSame(['a' => 1, 'b' => 2], json_decode($this->controller(query: $this->stateQuery())->getState()->render(), true));
		self::assertAnswer(Http::STATUS_BAD_REQUEST, $this->controller(query: $this->stateQuery(), body: 'not json')->postState());
	}//end testPostStateMerges()

	/**
	 * DELETE removes one state, or all under the key without stateId.
	 *
	 * @return void
	 */
	public function testDeleteState(): void {
		$this->controller(query: $this->stateQuery('a'), body: '1')->putState();
		$this->controller(query: $this->stateQuery('b'), body: '2')->putState();

		self::assertAnswer(Http::STATUS_NO_CONTENT, $this->controller(query: $this->stateQuery('a'))->deleteState());
		self::assertSame(['b'], $this->controller(query: $this->stateQuery(''))->getState()->getData());

		self::assertAnswer(Http::STATUS_NO_CONTENT, $this->controller(query: $this->stateQuery(''))->deleteState());
		self::assertSame([], $this->controller(query: $this->stateQuery(''))->getState()->getData());
	}//end testDeleteState()

	/**
	 * Unauthenticated, another learner's agent, and malformed keys are refused, and nothing is stored.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		self::assertAnswer(Http::STATUS_UNAUTHORIZED, $this->controller(query: $this->stateQuery(), body: 'x', actorId: null)->putState());

		$other          = $this->stateQuery();
		$other['agent'] = '{"objectType":"Agent","account":{"homePage":"https://school.example/","name":"pupil2"}}';
		self::assertAnswer(Http::STATUS_FORBIDDEN, $this->controller(query: $other, body: 'x')->putState());
		self::assertAnswer(Http::STATUS_FORBIDDEN, $this->controller(query: $other)->getState());

		$cases = [
			'no agent'          => array_diff_key($this->stateQuery(), ['agent' => true]),
			'no activityId'     => array_diff_key($this->stateQuery(), ['activityId' => true]),
			'bad registration'  => ['registration' => 'nope'] + $this->stateQuery(),
			'PUT without an id' => $this->stateQuery(''),
		];
		foreach ($cases as $label => $query) {
			self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller(query: $query, body: 'x')->putState()->getStatus(), $label);
		}

		$tooLarge = str_repeat('x', XapiDocumentStore::MAX_BYTES + 1);
		self::assertAnswer(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $this->controller(query: $this->stateQuery(), body: $tooLarge)->putState());
		self::assertSame([], $this->rows->rows['xapi-document'] ?? []);
	}//end testRefusals()

	/**
	 * If-Match and If-None-Match are honoured on writes and deletes.
	 *
	 * @return void
	 */
	public function testConcurrencyHeaders(): void {
		$etag  = '"' . sha1('v1') . '"';
		$query = $this->stateQuery();
		self::assertAnswer(Http::STATUS_PRECONDITION_FAILED, $this->controller(query: $query, body: 'v1', headers: ['If-Match' => $etag])->putState(), 'If-Match on a missing document');
		self::assertAnswer(Http::STATUS_NO_CONTENT, $this->controller(query: $query, body: 'v1', headers: ['If-None-Match' => '*'])->putState());
		self::assertAnswer(Http::STATUS_PRECONDITION_FAILED, $this->controller(query: $query, body: 'v2', headers: ['If-None-Match' => '*'])->putState());
		self::assertAnswer(Http::STATUS_PRECONDITION_FAILED, $this->controller(query: $query, body: 'v2', headers: ['If-Match' => '"stale"'])->putState());
		self::assertAnswer(Http::STATUS_PRECONDITION_FAILED, $this->controller(query: $query, headers: ['If-Match' => '"stale"'])->deleteState());
		self::assertAnswer(Http::STATUS_NO_CONTENT, $this->controller(query: $query, body: 'v2', headers: ['If-Match' => $etag])->putState());
		self::assertSame('v2', $this->controller(query: $query)->getState()->render());
		self::assertAnswer(Http::STATUS_NO_CONTENT, $this->controller(query: $query, body: 'v3')->putState(), 'a state PUT without headers overwrites');
	}//end testConcurrencyHeaders()

	/**
	 * Agent profile: every verb, and a blind PUT over an existing profile is 409.
	 *
	 * @return void
	 */
	public function testAgentProfileVerbs(): void {
		$query = $this->profileQuery();
		self::assertAnswer(Http::STATUS_NO_CONTENT, $this->controller(query: $query, body: '{"languagePreference":"nl-NL"}', headers: ['Content-Type' => 'application/json'])->putAgentProfile());
		self::assertAnswer(Http::STATUS_CONFLICT, $this->controller(query: $query, body: '{}')->putAgentProfile());

		$etag = '"' . sha1('{"languagePreference":"nl-NL"}') . '"';
		self::assertAnswer(Http::STATUS_NO_CONTENT, $this->controller(query: $query, body: '{"audioPreference":"on"}', headers: ['If-Match' => $etag])->postAgentProfile());
		self::assertSame(
			['languagePreference' => 'nl-NL', 'audioPreference' => 'on'],
			json_decode($this->controller(query: $query)->getAgentProfile()->render(), true)
		);
		self::assertSame(['cmi5LearnerPreferences'], $this->controller(query: $this->profileQuery(''))->getAgentProfile()->getData());

		self::assertAnswer(Http::STATUS_NO_CONTENT, $this->controller(query: $query)->deleteAgentProfile());
		self::assertAnswer(Http::STATUS_NOT_FOUND, $this->controller(query: $query)->getAgentProfile());
		self::assertAnswer(Http::STATUS_BAD_REQUEST, $this->controller(query: $this->profileQuery(''), body: '{}')->putAgentProfile());
	}//end testAgentProfileVerbs()
}//end class
