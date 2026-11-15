<?php

/**
 * Contract of JwsProofVerifier
 *
 * The public surface of JwsProofVerifier that other components call, so they depend
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
 * Contract of JwsProofVerifier.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface JwsProofVerifierInterface {

	/**
	 * Recursively sort an array's keys (RFC 8785 JCS) for deterministic JSON output.
	 *
	 * Mirrors CredentialSigningService::canonicalisePayload so that the verify-side
	 * signing input is byte-for-byte identical to the sign-side input.
	 *
	 * @param array<string,mixed> $payload The payload to canonicalise.
	 *
	 * @return array<string,mixed> The same data with all object-level keys sorted.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-the-europass-form-is-checkable-on-the-verification-route
	 */
	public function canonical(array $payload): array;

	/**
	 * Whether the payload's `proof.jws` verifies against the tenant key its
	 * `kid` names. Fail-closed: no proof, unknown kid or a bad signature is false.
	 *
	 * @param array<string,mixed> $payload  The signed payload with its `proof`.
	 * @param string              $tenantId The tenant.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-the-europass-form-is-checkable-on-the-verification-route
	 */
	public function verify(array $payload, string $tenantId): bool;
}//end interface
