// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The holder's wallet block on a certificate (learniq/LqLlCertificaat): the
// open offer, the claim, a failed offer or none; nothing for anyone else.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { walletOfferState } from '../../src/utils/walletOffer.js'

const OFFER = 'openid-credential-offer://?credential_offer_uri=https%3A%2F%2Fx'

test('the holder sees the open offer with its link', () => {
	const state = walletOfferState({ learnerUserId: 'sem', walletOfferUri: OFFER, walletOfferedAt: '2026-10-07T14:18:00Z' }, 'sem')
	assert.deepEqual(state, { state: 'offered', uri: OFFER, at: '2026-10-07T14:18:00Z' })
})

test('the holder sees the claim, a failure or no offer', () => {
	assert.equal(walletOfferState({ learnerUserId: 'sem', walletClaimedAt: '2026-10-07' }, 'sem').state, 'claimed')
	assert.equal(walletOfferState({ learnerUserId: 'sem', walletOfferError: 'no token' }, 'sem').state, 'failed')
	assert.equal(walletOfferState({ learnerUserId: 'sem' }, 'sem').state, 'none')
})

test('staff and other learners get no wallet block', () => {
	assert.equal(walletOfferState({ learnerUserId: 'sem', walletOfferUri: OFFER }, 'hr-sanne'), null)
	assert.equal(walletOfferState({ learnerUserId: 'sem' }, ''), null)
	assert.equal(walletOfferState(null, 'sem'), null)
})

test('CredentialDetail carries the wallet block, drawn as a QR code', () => {
	const people = JSON.parse(readFileSync(new URL('../../src/manifest.d/people.json', import.meta.url), 'utf8'))
	const detail = people.pages.find((page) => page.id === 'CredentialDetail')
	assert.ok(detail.config.bodyWidgets.some((widget) => widget.component === 'CredentialWalletOffer'))
	const view = readFileSync(new URL('../../src/components/sections/CredentialWalletOffer.vue', import.meta.url), 'utf8')
	assert.ok(view.includes('<CnQrCode'))
})
