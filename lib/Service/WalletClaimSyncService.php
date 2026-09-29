<?php

/**
 * Learniq Wallet Claim Sync Service
 *
 * Lifecycle guard for the Credential schema's `recordWalletClaim` transition.
 * System-triggered only (never a user-facing action) — invoked by
 * {@see \OCA\Learniq\Listener\WalletOfferConcludedListener} when openconnector
 * reports the wallet holder claimed an outstanding offer. Writes the claim
 * timestamp onto the Credential.
 *
 * TRIGGER GAP (flag to a human): as documented on
 * `WalletOfferConcludedListener`, openconnector's merged
 * `eudi-wallet-credential-issuance` adapter has no mechanism that reports a
 * claim back to the offering app — no event, no webhook, no poll endpoint.
 * This guard's own logic (write `walletOfferStatus=claimed` +
 * `walletClaimedAt`) is correct and tested, but nothing in the real system
 * invokes the `recordWalletClaim` transition today.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration."
 * Referenced from the Credential schema's
 * x-openregister-lifecycle.transitions.recordWalletClaim.requires in
 * learniq_register.json, as a LifecycleGuardInterface guard; the write runs
 * in CredentialWalletTransitionListener (learniq#983).
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Guards the Credential `recordWalletClaim` transition and records the claim.
 *
 * The guard always allows. `recordWalletClaim` is a self-loop
 * (issued -> issued), on which OpenRegister runs neither guards nor actions,
 * so claim() is run after the save by
 * {@see \OCA\Learniq\Listener\CredentialWalletTransitionListener}
 * (learniq#983).
 *
 * @spec openspec/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
 */
class WalletClaimSyncService implements LifecycleGuardInterface {
	/**
	 * OpenRegister lifecycle guard entry-point for `recordWalletClaim`.
	 *
	 * @param array<string,mixed> $object The Credential as it would be saved.
	 * @param string $action The transition, `recordWalletClaim`.
	 * @param string $userId The caller, or '' without a session.
	 *
	 * @return GuardResult Always allow: this transition has no failure mode.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		return GuardResult::allow();
	}//end check()

	/**
	 * Record the wallet claim on a Credential.
	 *
	 * @param array<string,mixed> $credential The Credential data array.
	 *
	 * @return array<string,mixed> The Credential with `walletOfferStatus=claimed` and `walletClaimedAt=now`.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
	 */
	public function claim(array $credential): array {
		$credential['walletOfferStatus'] = 'claimed';
		$credential['walletClaimedAt'] = gmdate('c');

		return $credential;
	}//end claim()
}//end class
