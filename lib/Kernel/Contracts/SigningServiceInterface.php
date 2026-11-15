<?php

/**
 * Contract of SigningService
 *
 * The public surface of SigningService that other components call, so they depend
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

use OCA\Learniq\Exception\SigningRefusedException;
use OCA\Learniq\Service\Signing\SignatureRequest;

/**
 * Contract of SigningService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SigningServiceInterface {

	/**
	 * The roles a user may sign a record in, the most specific first, and
	 * the version that would be signed.
	 *
	 * @param string $subject The subject slug: `learning-plan` or `placement-agreement`.
	 * @param string $id      The record uuid.
	 * @param string $userId  The signing user.
	 *
	 * @return array{roles: string[], version: int, signable: bool}
	 *
	 * @throws SigningRefusedException When the subject is unknown or the record does not exist.
	 *
	 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
	 */
	public function rolesFor(string $subject, string $id, string $userId): array;

	/**
	 * Record a signature by the user on the record's current version.
	 *
	 * @param string           $userId  The signing user, from the session.
	 * @param SignatureRequest $request The subject, record, role and captured signature.
	 *
	 * @return array{id: string, signerRole: string, signedAt: string, subjectVersion: int}
	 *
	 * @throws SigningRefusedException When the signature is refused.
	 *
	 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
	 */
	public function sign(string $userId, SignatureRequest $request): array;
}//end interface
