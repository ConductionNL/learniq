<?php

/**
 * Learniq signature request.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Signing
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Signing;

/**
 * What a signer asks to sign: the subject and record, the role and the
 * captured signature. The signing user travels separately, from the session.
 *
 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
 */
final class SignatureRequest {

	/**
	 * Constructor.
	 *
	 * @param string              $subject The subject slug: `learning-plan` or `placement-agreement`.
	 * @param string              $id      The record uuid.
	 * @param string              $role    The role asked for; empty takes the first role held.
	 * @param array<string,mixed> $capture `{mode: typed|drawn, value: string, affirmed: bool}`.
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string $id,
		public readonly string $role,
		public readonly array $capture,
	) {
	}//end __construct()
}//end class
