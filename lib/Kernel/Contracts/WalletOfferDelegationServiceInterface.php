<?php

/**
 * Contract of WalletOfferDelegationService
 *
 * The public surface of WalletOfferDelegationService that other components call, so they depend
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
 * Contract of WalletOfferDelegationService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WalletOfferDelegationServiceInterface {

	/**
	 * Push the Credential to the EUDI wallet through openconnector.
	 *
	 * On success sets `walletOfferStatus=offered`, `walletOfferedAt`,
	 * `walletAttestationRef` and `walletOfferUri` (the link a wallet app opens)
	 * and clears `walletOfferError`. On any failure sets `walletOfferError`,
	 * clears `walletOfferUri` and leaves the other offer fields as they were,
	 * so a failed offer is recorded rather than lost. Never throws.
	 *
	 * @param array<string,mixed> $credential The Credential data array.
	 *
	 * @return array<string,mixed> The Credential with the offer outcome applied.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-offertowallet-transition-pushes-an-issued-credential-to-the-eudi-wallet
	 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-credential-keeps-the-wallet-offer-link-while-the-offer-is-open
	 */
	public function offer(array $credential): array;
}//end interface
