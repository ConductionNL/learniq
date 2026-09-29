<?php

/**
 * Learniq xAPI Document Store
 *
 * The document half of learniq's LRS: xAPI 1.0.3 State documents (keyed by
 * activity, agent, optional registration and stateId) and Agent Profile
 * documents (keyed by agent and profileId), stored as `xapi-document`
 * objects. Every document belongs to one authenticated learner: the key
 * includes `verified_actor_id`, which the controller takes from the
 * credential, never from the request's `agent` parameter.
 *
 * A document's object id is derived from its full key, so a read or a write
 * of one document is a single lookup by id, and two writers of the same key
 * address the same object.
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
 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Learniq\Exception\XapiRequestException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use Throwable;

/**
 * Stores, reads, merges and deletes xAPI State and Agent Profile documents for one learner.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class XapiDocumentStore {

	/**
	 * The learniq register slug.
	 *
	 * @var string
	 */
	public const REGISTER = 'learniq';

	/**
	 * The xAPI document schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'xapi-document';

	/**
	 * Document kind: an activity State document.
	 *
	 * @var string
	 */
	public const KIND_STATE = 'state';

	/**
	 * Document kind: an Agent Profile document.
	 *
	 * @var string
	 */
	public const KIND_AGENT_PROFILE = 'agent-profile';

	/**
	 * Largest document accepted, in bytes.
	 *
	 * @var int
	 */
	public const MAX_BYTES = 1048576;

	/**
	 * Most documents a list or a bulk delete reads.
	 *
	 * @var int
	 */
	private const MAX_LIST = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectService     $objectService OpenRegister object access.
	 * @param CallerTenantResolver $tenants Resolves the tenant: the per-user binding, else the default tenant.
	 * @param XapiDocumentCodec $codec         Encodes and fingerprints bodies.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CallerTenantResolver $tenants,
		private readonly XapiDocumentCodec $codec,
	) {
	}//end __construct()

	/**
	 * Build a document key. `activityId` and `registration` are '' for an agent profile.
	 *
	 * @param string $kind         KIND_STATE or KIND_AGENT_PROFILE.
	 * @param string $actorId      The authenticated learner's uid.
	 * @param string $activityId   The activity IRI (state only).
	 * @param string $registration The registration UUID, or ''.
	 * @param string $documentId   The stateId or profileId, or '' for a list or bulk delete.
	 *
	 * @return array{kind: string, actorId: string, tenantId: string, activityId: string, registration: string, documentId: string} The key.
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function key(string $kind, string $actorId, string $activityId, string $registration, string $documentId): array {
		return [
			'kind'         => $kind,
			'actorId'      => $actorId,
			'tenantId'     => $this->tenantFor(userId: $actorId),
			'activityId'   => $activityId,
			'registration' => strtolower($registration),
			'documentId'   => $documentId,
		];
	}//end key()

	/**
	 * Read one document.
	 *
	 * @param array<string, string> $key A key from key(), with a documentId.
	 *
	 * @return array{contents: string, contentType: string, etag: string, updated: string}|null The document, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function get(array $key): ?array {
		$row = $this->findRow(key: $key);
		if ($row === null) {
			return null;
		}

		return $this->document(row: $row);
	}//end get()

	/**
	 * Replace (or create) one document.
	 *
	 * @param array<string, string> $key         A key from key(), with a documentId.
	 * @param string                $contents    The raw document body.
	 * @param string                $contentType The body's content type.
	 * @param array<string, mixed>  $context     `agent` (the xAPI agent as sent) and `lessonId`.
	 *
	 * @return string The new ETag.
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function put(array $key, string $contents, string $contentType, array $context): string {
		$row = $this->row(key: $key, contents: $contents, contentType: $contentType, context: $context);
		$this->objectService->saveObject(
			object: $row,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $row['id'],
			_rbac: false
		);

		return $row['etag'];
	}//end put()

	/**
	 * Merge a JSON object into a document (xAPI POST), creating it when absent.
	 *
	 * @param array<string, string> $key      A key from key(), with a documentId.
	 * @param string                $contents The posted JSON object.
	 * @param array<string, mixed>  $context  `agent` and `lessonId`, as for put().
	 *
	 * @return string The new ETag.
	 *
	 * @throws XapiRequestException 400 when the posted or the stored document is not a JSON object.
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function merge(array $key, string $contents, array $context): string {
		$posted = $this->codec->jsonObject(contents: $contents);
		if ($posted === null) {
			throw new XapiRequestException(status: Http::STATUS_BAD_REQUEST, message: 'A POST merges JSON objects: the body must be a JSON object');
		}

		$existing = $this->get(key: $key);
		if ($existing !== null) {
			$stored = $this->codec->jsonObject(contents: $existing['contents']);
			if ($stored === null) {
				throw new XapiRequestException(status: Http::STATUS_BAD_REQUEST, message: 'The stored document is not a JSON object, so it cannot be merged');
			}

			$posted = array_replace($stored, $posted);
		}

		return $this->put(
			key: $key,
			contents: (string)json_encode((object)$posted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			contentType: 'application/json',
			context: $context
		);
	}//end merge()

	/**
	 * Delete one document. Deleting a document that does not exist is not an error.
	 *
	 * @param array<string, string> $key A key from key(), with a documentId.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function delete(array $key): void {
		if ($this->findRow(key: $key) === null) {
			return;
		}

		$this->objectService->deleteObject(
			uuid: $this->documentUuid(key: $key),
			register: self::REGISTER,
			schema: self::SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
	}//end delete()

	/**
	 * The documentIds under a key without a documentId, optionally only those updated after `since`.
	 *
	 * @param array<string, string> $key   A key from key(), documentId ''.
	 * @param string                $since An ISO 8601 timestamp, or ''.
	 *
	 * @return array<int, string> The stateIds or profileIds.
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function listIds(array $key, string $since): array {
		$after = null;
		if ($since !== '') {
			$after = $this->timestamp(value: $since);
		}

		$ids = [];
		foreach ($this->rowsUnder(key: $key) as $row) {
			if ($after !== null && $this->timestamp(value: (string)($row['updated'] ?? '')) <= $after) {
				continue;
			}

			$ids[] = (string)$row['documentId'];
		}

		sort($ids);
		return $ids;
	}//end listIds()

	/**
	 * Delete every document under a key without a documentId (xAPI DELETE of all states).
	 *
	 * @param array<string, string> $key A key from key(), documentId ''.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-29-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function deleteAll(array $key): void {
		foreach ($this->rowsUnder(key: $key) as $row) {
			$this->objectService->deleteObject(
				uuid: $this->documentUuid(key: array_replace($key, ['documentId' => (string)$row['documentId']])),
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		}
	}//end deleteAll()

	/**
	 * The object id of a document: a UUID derived from its full key.
	 *
	 * @param array<string, string> $key A key from key(), with a documentId.
	 *
	 * @return string The UUID.
	 */
	private function documentUuid(array $key): string {
		$hash = hash(
			'sha256',
			implode("\n", [self::SCHEMA, $key['tenantId'], $key['actorId'], $key['kind'], $key['activityId'], $key['registration'], $key['documentId']])
		);
		// Version 8 (custom) UUID, RFC 9562 section 5.8.
		$hash[12] = '8';
		$hash[16] = dechex((hexdec($hash[16]) & 0x3) | 0x8);

		return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
	}//end documentUuid()

	/**
	 * Read the stored row of one document, checking every key field.
	 *
	 * @param array<string, string> $key A key with a documentId.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	private function findRow(array $key): ?array {
		try {
			$entity = $this->objectService->find(
				id: $this->documentUuid(key: $key),
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$row = $this->toArray(row: $entity);
		if ($this->matches(row: $row, key: $key) === false || (string)($row['documentId'] ?? '') !== $key['documentId']) {
			return null;
		}

		return $row;
	}//end findRow()

	/**
	 * Every stored row under a key without a documentId.
	 *
	 * The query narrows on the indexed fields; each row is then checked on the
	 * full key here, so an empty registration never matches a set one.
	 *
	 * @param array<string, string> $key A key, documentId ignored.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsUnder(array $key): array {
		$filters = [
			'register'          => self::REGISTER,
			'schema'            => self::SCHEMA,
			'kind'              => $key['kind'],
			'verified_actor_id' => $key['actorId'],
		];
		if ($key['activityId'] !== '') {
			$filters['activityId'] = $key['activityId'];
		}

		$rows = [];
		foreach ($this->objectService->findAll(config: ['filters' => $filters, 'limit' => self::MAX_LIST], _rbac: false, _multitenancy: false) as $entity) {
			$row = $this->toArray(row: $entity);
			if ($this->matches(row: $row, key: $key) === true && (string)($row['documentId'] ?? '') !== '') {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsUnder()

	/**
	 * Whether a stored row belongs to a key (everything but the documentId).
	 *
	 * @param array<string, mixed>  $row The stored row.
	 * @param array<string, string> $key The key.
	 *
	 * @return bool True on a match.
	 */
	private function matches(array $row, array $key): bool {
		return (string)($row['kind'] ?? '') === $key['kind']
			&& (string)($row['verified_actor_id'] ?? '') === $key['actorId']
			&& (string)($row['tenant_id'] ?? '') === $key['tenantId']
			&& (string)($row['activityId'] ?? '') === $key['activityId']
			&& (string)($row['registration'] ?? '') === $key['registration'];
	}//end matches()

	/**
	 * Build the row to store for a document.
	 *
	 * @param array<string, string> $key         The key, with a documentId.
	 * @param string                $contents    The raw body.
	 * @param string                $contentType The content type.
	 * @param array<string, mixed>  $context     `agent` and `lessonId`.
	 *
	 * @return array{id: string, etag: string}&array<string, mixed> The row.
	 */
	private function row(array $key, string $contents, string $contentType, array $context): array {
		$stored = $this->codec->encode(contents: $contents);
		$row = [
			'id'                => $this->documentUuid(key: $key),
			'kind'              => $key['kind'],
			'documentId'        => $key['documentId'],
			'activityId'        => $key['activityId'],
			'registration'      => $key['registration'],
			'agent'             => (array)($context['agent'] ?? []),
			'contents'          => $stored['contents'],
			'contentEncoding'   => $stored['contentEncoding'],
			'contentType'       => $contentType,
			'etag'              => $this->codec->etag(contents: $contents),
			'updated'           => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'verified_actor_id' => $key['actorId'],
			'tenant_id'         => $key['tenantId'],
		];
		$lessonId = (string)($context['lessonId'] ?? '');
		if ($lessonId !== '') {
			$row['lessonId'] = $lessonId;
		}

		return $row;
	}//end row()

	/**
	 * The document view of a stored row, with the body decoded.
	 *
	 * @param array<string, mixed> $row The stored row.
	 *
	 * @return array{contents: string, contentType: string, etag: string, updated: string} The document.
	 */
	private function document(array $row): array {
		$contents = $this->codec->decode(row: $row);

		return [
			'contents'    => $contents,
			'contentType' => (string)($row['contentType'] ?? 'application/octet-stream'),
			'etag'        => $this->codec->etag(contents: $contents),
			'updated'     => (string)($row['updated'] ?? ''),
		];
	}//end document()

	/**
	 * Parse an ISO 8601 timestamp.
	 *
	 * @param string $value The timestamp.
	 *
	 * @return int The Unix time, or 0 when it cannot be parsed.
	 */
	private function timestamp(string $value): int {
		try {
			return (new DateTimeImmutable($value))->getTimestamp();
		} catch (Throwable $e) {
			return 0;
		}
	}//end timestamp()

	/**
	 * Normalise an ObjectService row (entity or array) to a plain array.
	 *
	 * @param mixed $row The row returned by ObjectService.
	 *
	 * @return array<string, mixed> The serialized object data.
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

	/**
	 * The tenant a learner belongs to: their `tenant_id` binding, else the default tenant.
	 *
	 * @param string $userId The uid.
	 *
	 * @return string The tenant id.
	 */
	private function tenantFor(string $userId): string {
		return $this->tenants->forUserId(userId: $userId);
	}//end tenantFor()
}//end class
