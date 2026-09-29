<?php

/**
 * Learniq LRS Document Controller
 *
 * The xAPI 1.0.3 document resources of learniq's LRS, next to the statement
 * resource in {@see LrsController}:
 * - State: GET, PUT, POST (JSON merge) and DELETE on `/api/lrs/activities/state`,
 *   keyed by `activityId`, `agent`, optional `registration` and `stateId`; a GET
 *   or DELETE without `stateId` lists or deletes every stateId under the rest.
 * - Agent Profile: the same verbs on `/api/lrs/agents/profile`, keyed by `agent`
 *   and `profileId`.
 *
 * A launched cmi5 AU needs both: it reads the `LMS.LaunchData` state document
 * the launch wrote, and reads the learner's `cmi5LearnerPreferences` profile.
 * Callers authenticate exactly as for statements ({@see XapiCallerResolver}),
 * and a document always belongs to the authenticated learner: the `agent`
 * parameter must name that learner, and the stored key uses the identity from
 * the credential.
 *
 * Concurrency follows xAPI 1.0.3 Communication 3.1: every document carries an
 * ETag (the SHA-1 of its bytes); `If-Match` and `If-None-Match` are honoured on
 * every write and delete, and a PUT of an existing agent profile without either
 * header is refused with 409.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Exception\XapiRequestException;
use OCA\Learniq\Service\XapiCallerResolver;
use OCA\Learniq\Service\XapiDocumentStore;
use OCA\Learniq\Service\XapiDocumentRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * xAPI State and Agent Profile documents for the authenticated learner.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class LrsDocumentController extends Controller {

	/**
	 * The xAPI version every response announces.
	 *
	 * @var string
	 */
	private const XAPI_VERSION = '1.0.3';

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request   The current request.
	 * @param XapiCallerResolver $callers   Resolves the token or session caller.
	 * @param XapiDocumentStore  $documents Stores the documents.
	 * @param XapiDocumentRequest $reader   Reads the key, body and concurrency headers.
	 * @param LoggerInterface    $logger    PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly XapiCallerResolver $callers,
		private readonly XapiDocumentStore $documents,
		private readonly XapiDocumentRequest $reader,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Read one state document, or the list of stateIds when `stateId` is absent.
	 *
	 * @return Response The document, the stateIds, or an error.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function getState(): Response {
		return $this->handle(kind: XapiDocumentStore::KIND_STATE, verb: 'GET');
	}//end getState()

	/**
	 * Replace one state document.
	 *
	 * @return Response 204, or an error.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function putState(): Response {
		return $this->handle(kind: XapiDocumentStore::KIND_STATE, verb: 'PUT');
	}//end putState()

	/**
	 * Merge a JSON object into one state document.
	 *
	 * @return Response 204, or an error.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function postState(): Response {
		return $this->handle(kind: XapiDocumentStore::KIND_STATE, verb: 'POST');
	}//end postState()

	/**
	 * Delete one state document, or every one under the key when `stateId` is absent.
	 *
	 * @return Response 204, or an error.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function deleteState(): Response {
		return $this->handle(kind: XapiDocumentStore::KIND_STATE, verb: 'DELETE');
	}//end deleteState()

	/**
	 * Read one agent profile document, or the list of profileIds when `profileId` is absent.
	 *
	 * @return Response The document, the profileIds, or an error.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function getAgentProfile(): Response {
		return $this->handle(kind: XapiDocumentStore::KIND_AGENT_PROFILE, verb: 'GET');
	}//end getAgentProfile()

	/**
	 * Replace one agent profile document.
	 *
	 * @return Response 204, or an error.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function putAgentProfile(): Response {
		return $this->handle(kind: XapiDocumentStore::KIND_AGENT_PROFILE, verb: 'PUT');
	}//end putAgentProfile()

	/**
	 * Merge a JSON object into one agent profile document.
	 *
	 * @return Response 204, or an error.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function postAgentProfile(): Response {
		return $this->handle(kind: XapiDocumentStore::KIND_AGENT_PROFILE, verb: 'POST');
	}//end postAgentProfile()

	/**
	 * Delete one agent profile document.
	 *
	 * @return Response 204, or an error.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function deleteAgentProfile(): Response {
		return $this->handle(kind: XapiDocumentStore::KIND_AGENT_PROFILE, verb: 'DELETE');
	}//end deleteAgentProfile()

	/**
	 * Authenticate, read the key and run one verb on one resource.
	 *
	 * @param string $kind The document kind.
	 * @param string $verb GET, PUT, POST or DELETE.
	 *
	 * @return Response The answer.
	 */
	private function handle(string $kind, string $verb): Response {
		$caller = $this->callers->resolve(request: $this->request);
		if ($caller === null) {
			return $this->error(status: Http::STATUS_UNAUTHORIZED, message: 'Not authenticated');
		}

		try {
			$agent = $this->reader->agent(request: $this->request, actorId: $caller['actorId']);
			$key   = $this->reader->key(request: $this->request, kind: $kind, actorId: $caller['actorId'], needsId: in_array($verb, ['PUT', 'POST'], true));
			return $this->run(verb: $verb, key: $key, context: ['agent' => $agent, 'lessonId' => $caller['launch']['lessonId'] ?? '']);
		} catch (XapiRequestException $e) {
			return $this->error(status: $e->getStatus(), message: $e->getMessage());
		} catch (Throwable $e) {
			$this->logger->error('[LrsDocumentController] {verb} {kind} failed: {msg}', ['verb' => $verb, 'kind' => $kind, 'msg' => $e->getMessage()]);
			return $this->error(status: Http::STATUS_INTERNAL_SERVER_ERROR, message: 'The document could not be processed');
		}
	}//end handle()

	/**
	 * Run one verb on a resolved key.
	 *
	 * @param string                $verb    GET, PUT, POST or DELETE.
	 * @param array<string, string> $key     The document key.
	 * @param array<string, mixed>  $context `agent` and `lessonId` for writes.
	 *
	 * @return Response The answer.
	 *
	 * @throws XapiRequestException On a failed precondition, a body that is too large, or a merge of a non-object.
	 */
	private function run(string $verb, array $key, array $context): Response {
		if ($key['documentId'] === '') {
			return $this->runOnAll(verb: $verb, key: $key);
		}

		$existing = $this->documents->get(key: $key);
		if ($verb === 'GET') {
			return $this->show(document: $existing);
		}

		$strictPut = $verb === 'PUT' && $key['kind'] === XapiDocumentStore::KIND_AGENT_PROFILE;
		$this->reader->checkPreconditions(request: $this->request, current: $existing['etag'] ?? null, strictPut: $strictPut);
		if ($verb === 'DELETE') {
			$this->documents->delete(key: $key);
			return $this->noContent(etag: '');
		}

		$contents = $this->reader->body();
		if ($verb === 'PUT') {
			$contentType = $this->reader->contentType(request: $this->request);
			return $this->noContent(etag: $this->documents->put(key: $key, contents: $contents, contentType: $contentType, context: $context));
		}

		return $this->noContent(etag: $this->documents->merge(key: $key, contents: $contents, context: $context));
	}//end run()

	/**
	 * List the document ids under a key, or delete them all.
	 *
	 * @param string                $verb GET or DELETE (a write without an id never gets here).
	 * @param array<string, string> $key  The key, documentId ''.
	 *
	 * @return Response The ids, or 204.
	 *
	 * @throws XapiRequestException 400 on a malformed `since`.
	 */
	private function runOnAll(string $verb, array $key): Response {
		if ($verb === 'DELETE') {
			$this->documents->deleteAll(key: $key);
			return $this->noContent(etag: '');
		}

		$ids = $this->documents->listIds(key: $key, since: $this->reader->since(request: $this->request));
		return $this->withVersion(response: new JSONResponse(data: $ids));
	}//end runOnAll()

	/**
	 * A stored document with its content type and ETag, or 404.
	 *
	 * @param array{contents: string, contentType: string, etag: string, updated: string}|null $document The document.
	 *
	 * @return Response The answer.
	 */
	private function show(?array $document): Response {
		if ($document === null) {
			return $this->error(status: Http::STATUS_NOT_FOUND, message: 'No such document');
		}

		$headers = ['Content-Type' => $document['contentType'], 'ETag' => $document['etag']];
		return $this->withVersion(response: new DataDisplayResponse(data: $document['contents'], statusCode: Http::STATUS_OK, headers: $headers));
	}//end show()

	/**
	 * A 204, with the new ETag after a write.
	 *
	 * @param string $etag The document's new ETag, or '' after a delete.
	 *
	 * @return Response The response.
	 */
	private function noContent(string $etag): Response {
		$response = new Response(status: Http::STATUS_NO_CONTENT);
		if ($etag !== '') {
			$response->addHeader('ETag', $etag);
		}

		return $this->withVersion(response: $response);
	}//end noContent()

	/**
	 * A JSON error with the xAPI version header.
	 *
	 * @param int    $status  The HTTP status.
	 * @param string $message The explanation.
	 *
	 * @return Response The response.
	 */
	private function error(int $status, string $message): Response {
		return $this->withVersion(response: new JSONResponse(data: ['error' => $message], statusCode: $status));
	}//end error()

	/**
	 * Add `X-Experience-API-Version`, which xAPI requires on every LRS response.
	 *
	 * @param Response $response The response.
	 *
	 * @return Response The same response.
	 */
	private function withVersion(Response $response): Response {
		$response->addHeader('X-Experience-API-Version', self::XAPI_VERSION);
		return $response;
	}//end withVersion()
}//end class
