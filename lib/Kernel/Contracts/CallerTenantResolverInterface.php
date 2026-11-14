<?php

/**
 * Contract of CallerTenantResolver
 *
 * The public surface of CallerTenantResolver that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCP\IUser;

/**
 * Contract of CallerTenantResolver.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CallerTenantResolverInterface {

	/**
	 * The tenant of every user without a per-user binding.
	 *
	 * @var string
	 */
	public const DEFAULT_TENANT = '00000000-0000-4000-8000-000000000000';

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
	public function findOwned(IUser $user, string $id, string $schema): ?array;

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
	public function forUserId(string $userId): string;

	/**
	 * Resolve the tenant of the given user.
	 *
	 * @param IUser $user The authenticated caller.
	 *
	 * @return string The bound tenant id, or DEFAULT_TENANT when the user is unbound.
	 *
	 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
	 */
	public function resolve(IUser $user): string;
}//end interface
