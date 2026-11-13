<?php

/**
 * Contract of LearningRecordExportSigningService
 *
 * The public surface of LearningRecordExportSigningService that other components call, so they depend
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
 * Contract of LearningRecordExportSigningService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LearningRecordExportSigningServiceInterface {

	/**
	 * Verify a compact JWS against the tenant's public key, re-canonicalising
	 * the same bundle the caller believes was signed — mirrors
	 * `CredentialVerifyController::verifyJwsSignature()`.
	 *
	 * @param string $jws Compact JWS string produced by {@see self::sign()}.
	 * @param array<string,mixed> $bundle The bundle believed to have been signed.
	 * @param string $tenantId Tenant UUID whose public key to verify against.
	 *
	 * @return bool True when the signature is cryptographically valid.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-public-verification-page-resolves-an-active-unexpired-share-and-denies-otherwise
	 */
	public function verify(string $jws, array $bundle, string $tenantId): bool;
}//end interface
