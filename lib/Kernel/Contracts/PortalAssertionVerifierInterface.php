<?php

/**
 * Contract of PortalAssertionVerifier
 *
 * The public surface of PortalAssertionVerifier that other components call, so they depend
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
 * Contract of PortalAssertionVerifier.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalAssertionVerifierInterface {

	/**
	 * The header portaliq attaches the assertion to.
	 */
	public const HEADER = 'X-Portal-Subject';

	/**
	 * Verify an assertion and return its claims, or null.
	 *
	 * Checks, in order: three non-empty segments; header `alg` exactly HS256;
	 * the HMAC matches (constant time); the claims are a JSON object; `use`
	 * is `assertion`; `iss` is `portaliq`; `exp` is an integer in the future;
	 * `iat` is an integer not in the future and not after `exp`; `sub` is a
	 * non-empty string.
	 *
	 * @param string $jwt The raw header value.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
	 */
	public function verify(string $jwt): ?array;
}//end interface
