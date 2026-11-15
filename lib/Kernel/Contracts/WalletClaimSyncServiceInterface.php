<?php

/**
 * Contract of WalletClaimSyncService
 *
 * The public surface of WalletClaimSyncService that other components call, so they depend
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
 * Contract of WalletClaimSyncService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WalletClaimSyncServiceInterface {

	/**
	 * Record the wallet claim on a Credential.
	 *
	 * @param array<string,mixed> $credential The Credential data array.
	 *
	 * @return array<string,mixed> The Credential with `walletOfferStatus=claimed`, `walletClaimedAt=now` and no offer link.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
	 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-credential-keeps-the-wallet-offer-link-while-the-offer-is-open
	 */
	public function claim(array $credential): array;
}//end interface
