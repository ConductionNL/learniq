<?php

/**
 * Contract of WalletRevocationPropagationService
 *
 * The public surface of WalletRevocationPropagationService that other components call, so they depend
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
 * Contract of WalletRevocationPropagationService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WalletRevocationPropagationServiceInterface {

	/**
	 * Propagate a revocation to any outstanding wallet offer, best-effort.
	 *
	 * The offer link is cleared first, whatever follows: a revoked
	 * certificate is never offered. Otherwise no call is made unless there is
	 * an outstanding offer with a correlation key. On a confirmed revocation
	 * sets `walletOfferStatus=revoked`; on any failure records
	 * `walletOfferError` and never throws.
	 *
	 * @param array<string,mixed> $credential The Credential data array.
	 *
	 * @return array<string,mixed> The Credential with the propagation outcome applied.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-revoking-a-credential-propagates-to-any-outstanding-wallet-offer-fail-soft
	 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-credential-keeps-the-wallet-offer-link-while-the-offer-is-open
	 */
	public function propagate(array $credential): array;
}//end interface
