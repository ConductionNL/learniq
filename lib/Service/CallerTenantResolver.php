<?php

/**
 * Learniq Caller Tenant Resolver
 *
 * Resolves the tenant an authenticated caller belongs to, so a controller can
 * scope a caller-supplied id to that tenant. The ADR-023 action matrix only
 * answers "may this user's group call this action"; whether a given object
 * belongs to the caller's tenant is a separate check, and this is its input.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IUser;

/**
 * The caller's tenant: the per-user `tenant_id` binding, else the default tenant.
 *
 * Every learniq path that stamps or scopes a tenant resolves it here, so a row
 * written through one path carries the tenant every other path compares with.
 *
 * The fallback used to be the Nextcloud instance id. That is not a UUID, so
 * the 125 schemas that type `tenant_id` as `format: uuid` refused every write
 * carrying it (xAPI statements answered 500), and it matched none of the
 * seeded or example-set rows, so tenant-scoped lookups answered 404 on them.
 * The default tenant is the one the example sets carry (decision by Ruben,
 * 2026-09-29, option A). A real multi-tenant install binds each user with
 * `occ user:setting <uid> learniq tenant_id <uuid>` (docs/Technical/tenants.md).
 *
 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
 */
class CallerTenantResolver {
	/**
	 * The tenant of every user without a per-user binding.
	 *
	 * @var string
	 */
	public const DEFAULT_TENANT = '00000000-0000-4000-8000-000000000000';

	/**
	 * Constructor.
	 *
	 * @param IConfig $config Nextcloud config holding the per-user tenant binding.
	 * @param ObjectService $objectService OR object lookup.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve the tenant of the given user.
	 *
	 * @param IUser $user The authenticated caller.
	 *
	 * @return string The bound tenant id, or DEFAULT_TENANT when the user is unbound.
	 *
	 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
	 */
	public function resolve(IUser $user): string {
		return $this->forUserId(userId: $user->getUID());
	}//end resolve()

	/**
	 * Resolve the tenant of a user by uid, for paths that only hold the uid
	 * (a listener, a background write on a learner's behalf).
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return string The bound tenant id, or DEFAULT_TENANT when the user is unbound.
	 *
	 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
	 */
	public function forUserId(string $userId): string {
		$tenantId = '';
		if ($userId !== '') {
			$tenantId = (string)$this->config->getUserValue(
				userId: $userId,
				appName: Application::APP_ID,
				key: 'tenant_id',
				default: ''
			);
		}

		if (trim($tenantId) !== '') {
			return trim($tenantId);
		}

		return self::DEFAULT_TENANT;
	}//end forUserId()

	/**
	 * Whether an object row belongs to the given user's tenant.
	 *
	 * A row without a `tenant_id` never matches: every schema this guards
	 * requires the field, so a missing value is not the caller's.
	 *
	 * @param IUser $user The authenticated caller.
	 * @param array<string,mixed> $row The serialised object.
	 *
	 * @return bool True when the row's tenant equals the caller's tenant.
	 *
	 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
	 */
	public function owns(IUser $user, array $row): bool {
		$rowTenant = (string)($row['tenant_id'] ?? '');
		if ($rowTenant === '') {
			return false;
		}

		return $rowTenant === $this->resolve(user: $user);
	}//end owns()

	/**
	 * Fetch a learniq object by id, only when it belongs to the caller's tenant.
	 *
	 * An unknown id and another tenant's id both return null, so a caller
	 * cannot tell them apart: the endpoint answers 404 either way.
	 *
	 * @param IUser $user The authenticated caller.
	 * @param string $id Caller-supplied object id.
	 * @param string $schema Learniq schema slug.
	 *
	 * @return array<string,mixed>|null The serialised object, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
	 */
	public function findOwned(IUser $user, string $id, string $schema): ?array {
		// ObjectService::find() THROWS DoesNotExistException for an unknown id.
		try {
			$object = $this->objectService->find(id: $id, register: Application::APP_ID, schema: $schema);
		} catch (DoesNotExistException $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		$row = $object->jsonSerialize();
		if ($this->owns(user: $user, row: $row) === false) {
			return null;
		}

		return $row;
	}//end findOwned()
}//end class
