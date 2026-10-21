// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The admin page's signing key action calls the real rotation endpoint, asks
// before replacing an existing key, and reports success only on a 201.
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	rotateSigningKey,
	SIGNING_KEY_ROTATE_URL,
	SIGNING_KEY_STATUS_URL,
	signingKeyStatusOf,
} from '../../src/utils/signingKeyRotation.js'

const t = (text) => text

test('the status reads configured and the fingerprint only', () => {
	assert.equal(SIGNING_KEY_STATUS_URL, '/apps/learniq/api/settings/signing-key')
	assert.deepEqual(signingKeyStatusOf({ configured: true, fingerprint: 'abc' }), { configured: true, fingerprint: 'abc' })
	assert.deepEqual(signingKeyStatusOf({ configured: false }), { configured: false, fingerprint: '' })
	assert.deepEqual(signingKeyStatusOf(null), { configured: false, fingerprint: '' })
})

test('a first key is generated without a question and without confirm', async () => {
	const posted = []
	const outcome = await rotateSigningKey({ configured: false }, {
		confirm: async () => assert.fail('no question for a first key'),
		post: async (url, body) => {
			posted.push([url, body])
			return { status: 201, data: { fingerprint: 'f1', rotated: false } }
		},
		t,
	})
	assert.deepEqual(posted, [[SIGNING_KEY_ROTATE_URL, {}]])
	assert.equal(outcome.status, 'done')
	assert.equal(outcome.fingerprint, 'f1')
	assert.equal(outcome.message, 'A signing key was generated.')
})

test('a rotation asks first and sends confirm', async () => {
	const posted = []
	const outcome = await rotateSigningKey({ configured: true }, {
		confirm: async () => true,
		post: async (url, body) => {
			posted.push([url, body])
			return { status: 201, data: { fingerprint: 'f2', rotated: true } }
		},
		t,
	})
	assert.deepEqual(posted, [[SIGNING_KEY_ROTATE_URL, { confirm: 'true' }]])
	assert.equal(outcome.status, 'done')
	assert.match(outcome.message, /rotated/)
})

test('a declined or dismissed question posts nothing', async () => {
	for (const confirm of [async () => false, async () => { throw new Error('closed') }]) {
		const outcome = await rotateSigningKey({ configured: true }, {
			confirm,
			post: async () => assert.fail('posted after a no'),
			t,
		})
		assert.equal(outcome.status, 'cancelled')
	}
})

test('a refusal is never reported as a rotation', async () => {
	const refusal = (status) => async () => {
		const error = new Error('refused')
		error.response = { status, data: { error: 'x' } }
		throw error
	}
	const throttled = await rotateSigningKey({ configured: true }, { confirm: async () => true, post: refusal(429), t })
	assert.equal(throttled.status, 'failed')
	assert.match(throttled.message, /less than a day ago/)

	const forbidden = await rotateSigningKey({ configured: true }, { confirm: async () => true, post: refusal(403), t })
	assert.equal(forbidden.status, 'failed')

	const wrongAnswer = await rotateSigningKey({ configured: false }, {
		confirm: async () => true,
		post: async () => ({ status: 200, data: { status: 'success' } }),
		t,
	})
	assert.equal(wrongAnswer.status, 'failed')
	assert.equal(wrongAnswer.message, 'The signing key was not changed.')
})

test('the admin page no longer posts the register reload as a key rotation', () => {
	const source = readFileSync(new URL('../../src/views/LearniqSettings.vue', import.meta.url), 'utf8')
	assert.doesNotMatch(source, /api\/settings\/load/)
	assert.match(source, /signingKeyRotation\.js/)
})
