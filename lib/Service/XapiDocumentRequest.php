<?php

/**
 * Learniq xAPI Document Request
 *
 * Reads what an xAPI State or Agent Profile request says: the document key
 * from the query string, the `agent` (checked to be the authenticated
 * learner), `since`, the body and its content type, and whether the
 * concurrency headers allow the write (xAPI 1.0.3 Communication 3.1).
 *
 * Key parameters are read from the query string only. Nextcloud decodes a
 * JSON body into the request parameters, and a `stateId` or `registration`
 * member inside a document must never change which document is addressed.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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

namespace OCA\Learniq\Service;

use OCA\Learniq\Exception\XapiRequestException;
use OCP\AppFramework\Http;
use OCP\IRequest;

/**
 * Parses and checks one xAPI document request.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class XapiDocumentRequest {

	/**
	 * A UUID, any version.
	 *
	 * @var string
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Constructor.
	 *
	 * @param XapiDocumentStore $documents Builds the document key.
	 * @param XapiRequestBody   $body      Reads the raw body.
	 */
	public function __construct(
		private readonly XapiDocumentStore $documents,
		private readonly XapiRequestBody $body,
	) {
	}//end __construct()

	/**
	 * The `agent` parameter, checked to be the authenticated learner.
	 *
	 * @param IRequest $request The request.
	 * @param string   $actorId The authenticated uid.
	 *
	 * @return array<string, mixed> The agent as sent.
	 *
	 * @throws XapiRequestException 400 when absent or not JSON, 403 when it names someone else.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function agent(IRequest $request, string $actorId): array {
		$agent = json_decode($this->query(request: $request, name: 'agent'), true);
		if (is_array($agent) === false) {
			throw new XapiRequestException(status: Http::STATUS_BAD_REQUEST, message: 'The agent parameter must be an xAPI Agent in JSON');
		}

		if ((string)($agent['account']['name'] ?? '') !== $actorId) {
			throw new XapiRequestException(status: Http::STATUS_FORBIDDEN, message: 'The agent must be the authenticated learner');
		}

		return $agent;
	}//end agent()

	/**
	 * The document key the query string names.
	 *
	 * @param IRequest $request The request.
	 * @param string   $kind    The document kind.
	 * @param string   $actorId The authenticated uid.
	 * @param bool     $needsId Whether the verb needs a stateId or profileId.
	 *
	 * @return array<string, string> The key.
	 *
	 * @throws XapiRequestException 400 on a missing or malformed parameter.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function key(IRequest $request, string $kind, string $actorId, bool $needsId): array {
		$activityId   = '';
		$registration = '';
		$documentId   = $this->query(request: $request, name: 'profileId');
		if ($kind === XapiDocumentStore::KIND_STATE) {
			$activityId   = $this->query(request: $request, name: 'activityId');
			$registration = $this->query(request: $request, name: 'registration');
			$documentId   = $this->query(request: $request, name: 'stateId');
			$this->checkStateKey(activityId: $activityId, registration: $registration);
		}

		if ($needsId === true && $documentId === '') {
			throw new XapiRequestException(status: Http::STATUS_BAD_REQUEST, message: 'A document id (stateId or profileId) is required');
		}

		return $this->documents->key(kind: $kind, actorId: $actorId, activityId: $activityId, registration: $registration, documentId: $documentId);
	}//end key()

	/**
	 * The `since` query parameter, validated.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return string The timestamp, or ''.
	 *
	 * @throws XapiRequestException 400 when it is not a timestamp.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function since(IRequest $request): string {
		$since = $this->query(request: $request, name: 'since');
		if ($since !== '' && strtotime($since) === false) {
			throw new XapiRequestException(status: Http::STATUS_BAD_REQUEST, message: 'since must be an ISO 8601 timestamp');
		}

		return $since;
	}//end since()

	/**
	 * The request body, refused when it is larger than a document may be.
	 *
	 * @return string The body.
	 *
	 * @throws XapiRequestException 413 when the body is too large.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function body(): string {
		$contents = $this->body->read(maxBytes: XapiDocumentStore::MAX_BYTES);
		if (strlen($contents) > XapiDocumentStore::MAX_BYTES) {
			throw new XapiRequestException(
				status: Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
				message: 'The document is larger than ' . XapiDocumentStore::MAX_BYTES . ' bytes'
			);
		}

		return $contents;
	}//end body()

	/**
	 * The body's content type, `application/octet-stream` when the caller sent none.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return string The content type.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function contentType(IRequest $request): string {
		$type = trim((string)$request->getHeader('Content-Type'));
		if ($type === '') {
			return 'application/octet-stream';
		}

		return $type;
	}//end contentType()

	/**
	 * Enforce If-Match and If-None-Match.
	 *
	 * @param IRequest    $request   The request.
	 * @param string|null $current   The stored document's ETag, or null when there is none.
	 * @param bool        $strictPut Whether a write without either header must not overwrite (agent profile PUT).
	 *
	 * @return void
	 *
	 * @throws XapiRequestException 412 on a failed precondition, 409 on a blind overwrite.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function checkPreconditions(IRequest $request, ?string $current, bool $strictPut): void {
		$ifMatch     = trim((string)$request->getHeader('If-Match'));
		$ifNoneMatch = trim((string)$request->getHeader('If-None-Match'));

		if ($this->ifMatchFails(ifMatch: $ifMatch, current: $current) === true) {
			throw new XapiRequestException(status: Http::STATUS_PRECONDITION_FAILED, message: 'If-Match does not match the current document');
		}

		if ($current !== null && $this->etagListHas(list: $ifNoneMatch, etag: $current) === true) {
			throw new XapiRequestException(status: Http::STATUS_PRECONDITION_FAILED, message: 'If-None-Match matches the current document');
		}

		if ($strictPut === true && $current !== null && $ifMatch . $ifNoneMatch === '') {
			throw new XapiRequestException(
				status: Http::STATUS_CONFLICT,
				message: 'This document exists: send If-Match with its ETag to replace it, or If-None-Match: * to create it only when absent'
			);
		}
	}//end checkPreconditions()

	/**
	 * Whether an If-Match header is sent and fails: no document, or none of its ETags matches.
	 *
	 * @param string      $ifMatch The header value, or ''.
	 * @param string|null $current The stored ETag, or null.
	 *
	 * @return bool True when the precondition fails.
	 */
	private function ifMatchFails(string $ifMatch, ?string $current): bool {
		if ($ifMatch === '') {
			return false;
		}

		return $current === null || $this->etagListHas(list: $ifMatch, etag: $current) === false;
	}//end ifMatchFails()

	/**
	 * Validate the activity IRI and the optional registration of a state key.
	 *
	 * @param string $activityId   The activity IRI.
	 * @param string $registration The registration, or ''.
	 *
	 * @return void
	 *
	 * @throws XapiRequestException 400 when either is malformed.
	 */
	private function checkStateKey(string $activityId, string $registration): void {
		if (preg_match('/^[a-z][a-z0-9+.-]*:\S+$/i', $activityId) !== 1) {
			throw new XapiRequestException(status: Http::STATUS_BAD_REQUEST, message: 'activityId must be an IRI');
		}

		if ($registration !== '' && preg_match(self::UUID, $registration) !== 1) {
			throw new XapiRequestException(status: Http::STATUS_BAD_REQUEST, message: 'registration must be a UUID');
		}
	}//end checkStateKey()

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
			if ($candidate === '*' || '"' . trim($candidate, '"') . '"' === $etag) {
				return true;
			}
		}

		return false;
	}//end etagListHas()

	/**
	 * One query-string parameter, from the request URI only.
	 *
	 * @param IRequest $request The request.
	 * @param string   $name    The parameter name.
	 *
	 * @return string The value, or ''.
	 */
	private function query(IRequest $request, string $name): string {
		$params = [];
		parse_str((string)parse_url($request->getRequestUri(), PHP_URL_QUERY), $params);
		$value = $params[$name] ?? '';
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end query()
}//end class
