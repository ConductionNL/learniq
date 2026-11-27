// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The Compliance singleton the admin settings page reads and writes.
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).
//
// These pin what LearniqSettings.vue's loadCompliance() and saveCompliance()
// did inline before the reading and the payload moved to
// src/utils/complianceRecord.js: the first stored object fills the form, an
// empty or failing read leaves it, and a save sends empty links as null and
// stamps the signature only when the agreement is ticked.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	COMPLIANCE_TYPE,
	complianceFormOf,
	complianceIdOf,
	compliancePayload,
	readCompliance,
	storeCompliance,
} from '../../src/utils/complianceRecord.js'

const ISO = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/

/**
 * A store that records its calls and answers a fixed collection.
 *
 * @param {Function} collection What fetchCollection resolves or rejects with.
 * @return {object} The store.
 */
function store(collection) {
	const calls = []
	return {
		calls,
		registerObjectType: (...args) => calls.push(['register', ...args]),
		fetchCollection: (...args) => {
			calls.push(['fetch', ...args])
			return collection()
		},
		saveObject: async (...args) => {
			calls.push(['save', ...args])
			return { id: 'saved-1' }
		},
	}
}

test('the type is the learniq compliance schema', () => {
	assert.equal(COMPLIANCE_TYPE, 'learniq-compliance')
})

test('reading registers the type, asks for one object and returns the first', async () => {
	const s = store(async () => [{ id: 'c-1' }, { id: 'c-2' }])
	assert.deepEqual(await readCompliance(s), { id: 'c-1' })
	assert.deepEqual(s.calls, [
		['register', 'learniq-compliance', 'compliance', 'learniq'],
		['fetch', 'learniq-compliance', { _limit: 1 }],
	])
})

test('an empty, odd or failing read is no object', async () => {
	assert.equal(await readCompliance(store(async () => [])), null)
	assert.equal(await readCompliance(store(async () => ({ results: [] }))), null)
	assert.equal(await readCompliance(store(async () => { throw new Error('down') })), null)
	assert.equal(await readCompliance({}), null)
})

test('the id comes from id, then @self.id, else null', () => {
	assert.equal(complianceIdOf({ id: 'a', '@self': { id: 'b' } }), 'a')
	assert.equal(complianceIdOf({ '@self': { id: 'b' } }), 'b')
	assert.equal(complianceIdOf({ '@self': {} }), null)
	assert.equal(complianceIdOf({}), null)
})

test('the form takes the stored values, empty strings for missing ones', () => {
	assert.deepEqual(complianceFormOf({
		privacyconvenantSigned: 1,
		verwerkersovereenkomstUrl: 'https://a',
		privacybijsluiterUrl: 'https://b',
		privacybijsluiterVersion: '2',
	}), {
		privacyconvenantSigned: true,
		verwerkersovereenkomstUrl: 'https://a',
		privacybijsluiterUrl: 'https://b',
		privacybijsluiterVersion: '2',
	})
	assert.deepEqual(complianceFormOf({}), {
		privacyconvenantSigned: false,
		verwerkersovereenkomstUrl: '',
		privacybijsluiterUrl: '',
		privacybijsluiterVersion: '',
	})
})

test('a signed form saves its links and stamps the signature and review', () => {
	const payload = compliancePayload({
		privacyconvenantSigned: true,
		verwerkersovereenkomstUrl: 'https://a',
		privacybijsluiterUrl: 'https://b',
		privacybijsluiterVersion: '2',
	}, { uid: 'admin' })

	assert.deepEqual(Object.keys(payload), [
		'privacyconvenantSigned',
		'privacyconvenantSignedAt',
		'verwerkersovereenkomstUrl',
		'privacybijsluiterUrl',
		'privacybijsluiterVersion',
		'lastReviewedAt',
		'lastReviewedBy',
	])
	assert.equal(payload.privacyconvenantSigned, true)
	assert.match(payload.privacyconvenantSignedAt, ISO)
	assert.equal(payload.verwerkersovereenkomstUrl, 'https://a')
	assert.equal(payload.privacybijsluiterUrl, 'https://b')
	assert.equal(payload.privacybijsluiterVersion, '2')
	assert.match(payload.lastReviewedAt, ISO)
	assert.equal(payload.lastReviewedBy, 'admin')
})

test('an unsigned, empty form saves nulls and no reviewer without a user', () => {
	const payload = compliancePayload({
		privacyconvenantSigned: false,
		verwerkersovereenkomstUrl: '',
		privacybijsluiterUrl: '',
		privacybijsluiterVersion: '',
	}, null)

	assert.equal(payload.privacyconvenantSigned, false)
	assert.equal(payload.privacyconvenantSignedAt, null)
	assert.equal(payload.verwerkersovereenkomstUrl, null)
	assert.equal(payload.privacybijsluiterUrl, null)
	assert.equal(payload.privacybijsluiterVersion, null)
	assert.match(payload.lastReviewedAt, ISO)
	assert.equal(payload.lastReviewedBy, null)
})

test('saving goes through the store, and is nothing without one', async () => {
	const s = store(async () => [])
	assert.deepEqual(await storeCompliance(s, { id: 'c-1' }), { id: 'saved-1' })
	assert.deepEqual(s.calls, [['save', 'learniq-compliance', { id: 'c-1' }]])
	assert.equal(await storeCompliance({}, { id: 'c-1' }), null)
})
