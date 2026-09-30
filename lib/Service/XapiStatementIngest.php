<?php

/**
 * Learniq xAPI Statement Ingest
 *
 * The write and read half of learniq's LRS. A statement arrives from an
 * authenticated caller (a launched cmi5 AU with its launch token, or a signed-in
 * learner's lesson player); this service stamps `verified_actor_id` from that
 * authenticated identity, never from the statement's own `actor`, and stores
 * it as an `xapi-statement` object. Every downstream consumer
 * (`XapiCompletionHandler`, `LessonProgress`, `XapiEnrolmentCompletion`,
 * `EngagementSignalHandler`) trusts that field, so this is the only place it is
 * set.
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
 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#3-lrs-ingest-controller
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Learniq\Exception\XapiRequestException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;

/**
 * Stamps and stores xAPI statements, and reads them back scoped to a learner.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class XapiStatementIngest {

	/**
	 * The learniq register slug.
	 *
	 * @var string
	 */
	public const REGISTER = 'learniq';

	/**
	 * The xAPI statement schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'xapi-statement';

	/**
	 * Statement keys copied from the incoming envelope (xAPI 1.0.3 §2.2).
	 *
	 * @var array<int, string>
	 */
	private const STATEMENT_KEYS = ['actor', 'verb', 'object', 'result', 'context', 'timestamp', 'authority', 'version'];

	/**
	 * Most statements accepted in one POST.
	 *
	 * @var int
	 */
	private const MAX_BATCH = 50;

	/**
	 * Largest page a GET returns.
	 *
	 * @var int
	 */
	private const MAX_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param CallerTenantResolver $tenants Resolves the tenant: the per-user binding, else the default tenant.
	 * @param XapiStatementComparator $comparator Decides whether a re-sent statement matches the stored one.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CallerTenantResolver $tenants,
		private readonly XapiStatementComparator $comparator = new XapiStatementComparator(),
	) {
	}//end __construct()

	/**
	 * Store a batch of statements for one authenticated actor.
	 *
	 * @param array<int, array<string, mixed>> $statements The statements.
	 * @param string                           $actorId    The authenticated Nextcloud uid.
	 * @param array{lessonId?: string, courseId?: string} $launch The launch context from a token, if any; it wins over the body.
	 *
	 * @return array<int, string> The stored statement ids, in order.
	 *
	 * @throws InvalidArgumentException When the batch is empty, too large, or a statement is malformed.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#3-lrs-ingest-controller
	 */
	public function ingest(array $statements, string $actorId, array $launch = []): array {
		if ($statements === [] || count($statements) > self::MAX_BATCH) {
			throw new InvalidArgumentException('Send between 1 and ' . self::MAX_BATCH . ' statements');
		}

		// Classify the whole batch before writing any of it: a known id with a
		// matching statement is a no-op, a known id with a different statement
		// is a 409 for the batch, and nothing is stored when one conflicts.
		$tenantId = $this->tenantFor(userId: $actorId);
		$ids      = [];
		$toStore  = [];
		foreach ($statements as $statement) {
			$row = $this->stamp(statement: $statement, actorId: $actorId, tenantId: $tenantId, launch: $launch);
			if (in_array($row['id'], $ids, true) === true) {
				throw new InvalidArgumentException('A batch may not carry the same statement id twice');
			}

			$ids[] = $row['id'];
			if ($this->alreadyStored(row: $row, statement: $statement, actorId: $actorId) === false) {
				$toStore[] = $row;
			}
		}

		foreach ($toStore as $row) {
			$this->objectService->saveObject(
				object: $row,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $row['id'],
				_rbac: false
			);
		}

		return $ids;
	}//end ingest()

	/**
	 * Read statements; a non-admin caller only ever sees their own.
	 *
	 * @param string              $callerId The signed-in uid.
	 * @param bool                $isAdmin  Whether the caller is an admin.
	 * @param array<string, string> $filters Optional `lessonId` and `courseId` filters.
	 * @param int                 $limit    Page size, capped at MAX_LIMIT.
	 *
	 * @return array<int, mixed> The matching statements.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#3-lrs-ingest-controller
	 */
	public function query(string $callerId, bool $isAdmin, array $filters, int $limit): array {
		$where = ['register' => self::REGISTER, 'schema' => self::SCHEMA];
		foreach (['lessonId', 'courseId'] as $key) {
			if (($filters[$key] ?? '') !== '') {
				$where[$key] = $filters[$key];
			}
		}

		if ($isAdmin === false) {
			$where['verified_actor_id'] = $callerId;
		}

		return $this->objectService->findAll(
			config: ['filters' => $where, 'limit' => max(1, min($limit, self::MAX_LIMIT))]
		);
	}//end query()

	/**
	 * Validate one statement and build the row to store.
	 *
	 * @param mixed                $statement The incoming statement.
	 * @param string               $actorId   The authenticated uid.
	 * @param string               $tenantId  The actor's tenant.
	 * @param array<string, string> $launch   Launch context from the token.
	 *
	 * @return array<string, mixed> The row, with `id` set.
	 *
	 * @throws InvalidArgumentException When a required part is missing.
	 */
	private function stamp(mixed $statement, string $actorId, string $tenantId, array $launch): array {
		if (is_array($statement) === false
			|| is_array($statement['actor'] ?? null) === false
			|| (string)($statement['verb']['id'] ?? '') === ''
			|| is_array($statement['object'] ?? null) === false
		) {
			throw new InvalidArgumentException('Every statement needs an actor, a verb with an id, and an object');
		}

		$row = array_intersect_key($statement, array_flip(self::STATEMENT_KEYS));
		$row['id']                = $this->statementId(candidate: $statement['id'] ?? null);
		$row['stored']            = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$row['verified_actor_id'] = $actorId;
		$row['tenant_id']         = $tenantId;
		foreach (['lessonId', 'courseId'] as $key) {
			$value = (string)($launch[$key] ?? ($statement[$key] ?? ''));
			if ($value !== '') {
				$row[$key] = $value;
			}
		}

		return $row;
	}//end stamp()

	/**
	 * Whether a statement with this id is already stored and matches (xAPI 1.0.3 Communication 2.1.3).
	 *
	 * Only a statement that sent its own id can be known. The stored one must
	 * belong to the same authenticated learner and be equivalent; anything else
	 * is a conflict, answered without saying whose statement holds the id.
	 *
	 * @param array<string, mixed> $row       The stamped row.
	 * @param array<string, mixed> $statement The statement as sent.
	 * @param string               $actorId   The authenticated uid.
	 *
	 * @return bool True when the statement is a no-op re-send.
	 *
	 * @throws XapiRequestException 409 when a different statement holds the id.
	 */
	private function alreadyStored(array $row, array $statement, string $actorId): bool {
		if (strtolower((string)($statement['id'] ?? '')) !== $row['id']) {
			return false;
		}

		$stored = $this->storedStatement(id: $row['id']);
		if ($stored === null) {
			return false;
		}

		if ((string)($stored['verified_actor_id'] ?? '') !== $actorId || $this->comparator->equivalent(incoming: $statement, stored: $stored) === false) {
			throw new XapiRequestException(
				status: Http::STATUS_CONFLICT,
				message: 'A different statement is already stored with id ' . $row['id']
			);
		}

		return true;
	}//end alreadyStored()

	/**
	 * The stored statement with this id, or null.
	 *
	 * @param string $id The statement id.
	 *
	 * @return array<string, mixed>|null The stored statement.
	 */
	private function storedStatement(string $id): ?array {
		try {
			$entity = $this->objectService->find(id: $id, register: self::REGISTER, schema: self::SCHEMA, _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $entity->jsonSerialize();
	}//end storedStatement()

	/**
	 * The statement's own UUID when it sent a valid one, else a fresh v4 UUID.
	 *
	 * @param mixed $candidate The incoming `id`.
	 *
	 * @return string The id to store under.
	 */
	private function statementId(mixed $candidate): string {
		if (is_string($candidate) === true
			&& preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $candidate) === 1
		) {
			return strtolower($candidate);
		}

		$bytes    = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end statementId()

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
