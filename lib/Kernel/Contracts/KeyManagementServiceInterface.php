<?php

/**
 * Contract of KeyManagementService
 *
 * The public surface of KeyManagementService that other components call, so they depend
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

/**
 * Contract of KeyManagementService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface KeyManagementServiceInterface {

	/**
	 * Generate an RSA-2048 keypair for a tenant and persist it.
	 *
	 * The private key is encrypted via ICrypto before storage. When a key already
	 * exists for the tenant the previous public key is archived (verification-only)
	 * before the new key becomes active — old credentials signed under the previous
	 * key remain verifiable. Fixes #176.
	 *
	 * @param string $tenantId Tenant UUID (must be non-empty). Fixes #211.
	 *
	 * @return array{fingerprint: string, publicKey: string} Public key data.
	 *
	 * @throws \InvalidArgumentException If tenantId is empty.
	 * @throws \RuntimeException If OpenSSL key generation fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	public function generateTenantKeypair(string $tenantId): array;

	/**
	 * Return the public key and fingerprint for a tenant, or null if absent.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array{fingerprint: string, publicKey: string}|null Key data, or null if not configured.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	public function getTenantKeyStatus(string $tenantId): ?array;
}//end interface
