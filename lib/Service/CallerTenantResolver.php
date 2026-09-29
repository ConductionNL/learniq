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
 * @spec openspec/changes/fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IUser;

/**
 * The caller's tenant: the per-user `tenant_id` binding, else the instance id.
 *
 * Same resolution as AuditPackBuilder and QtiImportController, so a row the
 * caller wrote through those paths carries the tenant this resolver returns.
 *
 * @spec openspec/changes/fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
 */
class CallerTenantResolver {
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
	 * @return string The bound tenant id, or the instance id when the user is unbound.
	 *
	 * @spec openspec/changes/fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
	 */
	public function resolve(IUser $user): string {
		$tenantId = (string)$this->config->getUserValue(
			userId: $user->getUID(),
			appName: Application::APP_ID,
			key: 'tenant_id',
			default: ''
		);

		if ($tenantId !== '') {
			return $tenantId;
		}

		return (string)$this->config->getSystemValue('instanceid', '');
	}//end resolve()

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
	 * @spec openspec/changes/fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
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
	 * @spec openspec/changes/fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
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
