// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the holder's wallet block on a certificate shows (learniq/LqLlCertificaat,
 * "In je wallet"): an open offer with its link, the claim, a failed offer, or
 * no offer yet. Anyone but the holder gets nothing.
 *
 * @param {object|null} credential The Credential.
 * @param {string} uid The signed-in user's id.
 * @return {{state: string, uri?: string, at?: string}|null} The state, or null for anyone but the holder.
 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-sees-the-offer-on-their-own-certificate
 */
export function walletOfferState(credential, uid) {
	if (!credential || !uid || credential.learnerUserId !== uid) {
		return null
	}
	if (credential.walletOfferUri) {
		return { state: 'offered', uri: credential.walletOfferUri, at: credential.walletOfferedAt ?? '' }
	}
	if (credential.walletClaimedAt) {
		return { state: 'claimed', at: credential.walletClaimedAt }
	}
	return { state: credential.walletOfferError ? 'failed' : 'none' }
}
