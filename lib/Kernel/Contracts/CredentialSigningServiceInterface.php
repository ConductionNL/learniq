<?php

/**
 * Contract of CredentialSigningService
 *
 * The public surface of CredentialSigningService that other components call, so they depend
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
 * Contract of CredentialSigningService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CredentialSigningServiceInterface {

	/**
	 * Sign a Credential before it is saved.
	 *
	 * Gives the credential its id first when it has none, because the signed
	 * payload names the public verification URL, which carries that id. The
	 * caller saves the result under the same id (`saveObject(uuid: ...)`) so
	 * the verify route finds the credential the payload points at.
	 *
	 * @param array<string,mixed> $credential The Credential object to be saved.
	 *
	 * @return array<string,mixed>|null The credential with `id`, `openbadges3Payload`,
	 *                                  `signature`, `issuerDid` and `verificationUrl`
	 *                                  set, or null when it cannot be signed (no
	 *                                  key for the tenant, or signing failed).
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
	 */
	public function sign(array $credential): ?array;
}//end interface
