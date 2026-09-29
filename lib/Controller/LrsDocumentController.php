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

use InvalidArgumentException;
use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Exception\XapiRequestException;
use OCA\Learniq\Service\XapiCallerResolver;
use OCA\Learniq\Service\XapiDocumentStore;
use OCA\Learniq\Service\XapiRequestBody;
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
	 * @param XapiRequestBody    $body      Reads the raw request body.
	 * @param LoggerInterface    $logger    PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly XapiCallerResolver $callers,
		private readonly XapiDocumentStore $documents,
		private readonly XapiRequestBody $body,
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
			$agent = $this->agentOf(actorId: $caller['actorId']);
			$key   = $this->keyFor(kind: $kind, actorId: $caller['actorId'], needsId: $verb === 'PUT' || $verb === 'POST');
			return $this->run(verb: $verb, key: $key, context: ['agent' => $agent, 'lessonId' => $caller['launch']['lessonId'] ?? '']);
		} catch (XapiRequestException $e) {
			return $this->error(status: $e->getStatus(), message: $e->getMessage());
		} catch (InvalidArgumentException $e) {
			return $this->error(status: Http::STATUS_BAD_REQUEST, message: $e->getMessage());
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
	 * @throws XapiRequestException When a precondition fails or the body is too large.
	 */
	private function run(string $verb, array $key, array $context): Response {
		if ($key['documentId'] === '') {
			if ($verb === 'DELETE') {
				$this->documents->deleteAll(key: $key);
				return $this->noContent();
			}

			return $this->withVersion(response: new JSONResponse(data: $this->documents->listIds(key: $key, since: $this->since())));
		}

		$existing = $this->documents->get(key: $key);
		if ($verb === 'GET') {
			if ($existing === null) {
				return $this->error(status: Http::STATUS_NOT_FOUND, message: 'No such document');
			}

			return $this->withVersion(
				response: new DataDisplayResponse(
					data: $existing['contents'],
					statusCode: Http::STATUS_OK,
					headers: ['Content-Type' => $existing['contentType'], 'ETag' => $existing['etag']]
				)
			);
		}

		$this->checkPreconditions(existing: $existing, strictPut: $verb === 'PUT' && $key['kind'] === XapiDocumentStore::KIND_AGENT_PROFILE);
		if ($verb === 'DELETE') {
			$this->documents->delete(key: $key);
			return $this->noContent();
		}

		$contents = $this->body->read(maxBytes: XapiDocumentStore::MAX_BYTES);
		if (strlen($contents) > XapiDocumentStore::MAX_BYTES) {
			throw new XapiRequestException(status: Http::STATUS_REQUEST_ENTITY_TOO_LARGE, message: 'The document is larger than ' . XapiDocumentStore::MAX_BYTES . ' bytes');
		}

		$etag = $verb === 'PUT'
			? $this->documents->put(key: $key, contents: $contents, contentType: (string)$this->request->getHeader('Content-Type'), context: $context)
			: $this->documents->merge(key: $key, contents: $contents, context: $context);

		$response = $this->noContent();
		$response->addHeader('ETag', $etag);
		return $response;
	}//end run()

	/**
	 * Enforce If-Match and If-None-Match (xAPI 1.0.3 Communication 3.1).
	 *
	 * @param array{etag: string}|array<string, string>|null $existing  The stored document, or null.
	 * @param bool                                           $strictPut Whether a PUT without either header must not overwrite (agent profile).
	 *
	 * @return void
	 *
	 * @throws XapiRequestException 412 on a failed precondition, 409 on a blind overwrite.
	 */
	private function checkPreconditions(?array $existing, bool $strictPut): void {
		$ifMatch     = trim((string)$this->request->getHeader('If-Match'));
		$ifNoneMatch = trim((string)$this->request->getHeader('If-None-Match'));
		$current     = $existing['etag'] ?? null;

		if ($ifMatch !== '' && ($current === null || $this->etagListHas(list: $ifMatch, etag: $current) === false)) {
			throw new XapiRequestException(status: Http::STATUS_PRECONDITION_FAILED, message: 'If-Match does not match the current document');
		}

		if ($ifNoneMatch !== '' && $current !== null && $this->etagListHas(list: $ifNoneMatch, etag: $current) === true) {
			throw new XapiRequestException(status: Http::STATUS_PRECONDITION_FAILED, message: 'If-None-Match matches the current document');
		}

		if ($strictPut === true && $current !== null && $ifMatch === '' && $ifNoneMatch === '') {
			throw new XapiRequestException(
				status: Http::STATUS_CONFLICT,
				message: 'This document exists: send If-Match with its ETag to replace it, or If-None-Match: * to create it only when absent'
			);
		}
	}//end checkPreconditions()

	/**
	 * Whether a header's ETag list (or `*`) matches an ETag.
	 *
	 * @param string $list The header value.
	 * @param string $etag The quoted ETag.
	 *
	 * @return bool True on a match.
	 */
	private function etagListHas(string $list, string $etag): bool {
		foreach (explode(',', $list) as $candidate) {
			$candidate = trim($candidate);
			if ($candidate === '*' || $candidate === $etag || '"' . trim($candidate, '"') . '"' === $etag) {
				return true;
			}
		}

		return false;
	}//end etagListHas()

	/**
	 * The `agent` parameter, checked to be the authenticated learner.
	 *
	 * @param string $actorId The authenticated uid.
	 *
	 * @return array<string, mixed> The agent as sent.
	 *
	 * @throws XapiRequestException 400 when absent or not JSON, 403 when it names someone else.
	 */
	private function agentOf(string $actorId): array {
		$agent = json_decode($this->query(name: 'agent'), true);
		if (is_array($agent) === false) {
			throw new XapiRequestException(status: Http::STATUS_BAD_REQUEST, message: 'The agent parameter must be an xAPI Agent in JSON');
		}

		if ((string)($agent['account']['name'] ?? '') !== $actorId) {
			throw new XapiRequestException(status: Http::STATUS_FORBIDDEN, message: 'The agent must be the authenticated learner');
		}

		return $agent;
	}//end agentOf()

	/**
	 * Build the document key from the query string.
	 *
	 * @param string $kind    The document kind.
	 * @param string $actorId The authenticated uid.
	 * @param bool   $needsId Whether the verb needs a stateId or profileId.
	 *
	 * @return array<string, string> The key.
	 *
	 * @throws XapiRequestException 400 on a missing or malformed parameter.
	 */
	private function keyFor(string $kind, string $actorId, bool $needsId): array {
		$activityId   = '';
		$registration = '';
		$documentId   = $this->query(name: 'profileId');
		if ($kind === XapiDocumentStore::KIND_STATE) {
			$activityId   = $this->query(name: 'activityId');
			$registration = $this->query(name: 'registration');
			$documentId   = $this->query(name: 'stateId');
			if (preg_match('/^[a-z][a-z0-9+.-]*:\S+$/i', $activityId) !== 1) {
				throw new XapiRequestException(status: Http::STATUS_BAD_REQUEST, message: 'activityId must be an IRI');
			}

			if ($registration !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $registration) !== 1) {
				throw new XapiRequestException(status: Http::STATUS_BAD_REQUEST, message: 'registration must be a UUID');
			}
		}

		if ($needsId === true && $documentId === '') {
			throw new XapiRequestException(status: Http::STATUS_BAD_REQUEST, message: 'A document id (stateId or profileId) is required');
		}

		return $this->documents->key(kind: $kind, actorId: $actorId, activityId: $activityId, registration: $registration, documentId: $documentId);
	}//end keyFor()

	/**
	 * The `since` query parameter, validated.
	 *
	 * @return string The timestamp, or ''.
	 *
	 * @throws XapiRequestException 400 when it is not a timestamp.
	 */
	private function since(): string {
		$since = $this->query(name: 'since');
		if ($since !== '' && strtotime($since) === false) {
			throw new XapiRequestException(status: Http::STATUS_BAD_REQUEST, message: 'since must be an ISO 8601 timestamp');
		}

		return $since;
	}//end since()

	/**
	 * One query-string parameter. Read from the query only: a JSON document
	 * body is decoded into the request parameters by Nextcloud, and a key in
	 * the document must never change which document is addressed.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string The value, or ''.
	 */
	private function query(string $name): string {
		$value = $this->request->get[$name] ?? '';
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end query()

	/**
	 * A 204 with the xAPI version header.
	 *
	 * @return Response The response.
	 */
	private function noContent(): Response {
		return $this->withVersion(response: new Response(status: Http::STATUS_NO_CONTENT));
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
