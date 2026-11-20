// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Declining a pending sign-up (DeclineSignUpDialog): the request goes to the
// Enrolment's own lifecycle transition, carries the trimmed reason as the
// decline input, and hands back what axios answered, refusals included.
//
// @spec openspec/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager

import assert from 'node:assert/strict'
import { register } from 'node:module'
import { test } from 'node:test'

// The util imports @nextcloud/axios and @nextcloud/router, which need a
// browser window at import time. Resolve both to stubs that read the
// recording client from globalThis, so only the HTTP boundary is replaced.
const stubs = {
	'@nextcloud/axios': 'export default { post: (...args) => globalThis.__signUpHttp.post(...args) }',
	'@nextcloud/router': 'export const generateUrl = (path, params = {}) => "/nc" + path.replace(/{(\\w+)}/g, (m, k) => encodeURIComponent(params[k]))',
}
const hooks = `
const stubs = ${JSON.stringify(stubs)}
export async function resolve(specifier, context, next) {
	if (specifier in stubs) {
		return { url: 'data:text/javascript,' + encodeURIComponent(stubs[specifier]), shortCircuit: true }
	}
	return next(specifier, context)
}
`
register('data:text/javascript,' + encodeURIComponent(hooks))

const { declineSignUp } = await import('../../src/utils/signUpDecisionRequest.js')

/**
 * An axios-like client that rejects the way axios does on a non-2xx status.
 *
 * @param {number} status The status to answer with.
 * @param {object} data The body to answer with.
 * @return {{calls: Array}} The client and the calls it saw.
 */
function client(status, data) {
	const calls = []
	globalThis.__signUpHttp = {
		post: async (url, body) => {
			calls.push({ url, body })
			if (status >= 200 && status < 300) {
				return { status, data }
			}
			const error = new Error(`Request failed with status code ${status}`)
			error.response = { status, data }
			throw error
		},
	}
	return { calls }
}

test('a decline posts the trimmed reason to the enrolment\'s transition', async () => {
	const http = client(200, { lifecycle: 'declined' })
	await declineSignUp('3f2a-uuid', '  De cursus is vol  ')
	assert.deepEqual(http.calls, [
		{
			url: '/nc/apps/openregister/api/objects/3f2a-uuid/transition',
			body: { action: 'decline', data: { declineReason: 'De cursus is vol' } },
		},
	])
})

test('a decline hands back what the transition answered', async () => {
	client(200, { id: '3f2a-uuid', lifecycle: 'declined' })
	const answer = await declineSignUp('3f2a-uuid', 'vol')
	assert.equal(answer.status, 200)
	assert.deepEqual(answer.data, { id: '3f2a-uuid', lifecycle: 'declined' })
})

test('a missing reason is sent as an empty decline input, not as "null"', async () => {
	const http = client(200, {})
	await declineSignUp('abc', null)
	assert.deepEqual(http.calls[0].body, { action: 'decline', data: { declineReason: '' } })
})

test('a refused transition rejects with the server\'s answer', async () => {
	client(409, { message: 'Transition not allowed' })
	await assert.rejects(declineSignUp('abc', 'vol'), (error) => {
		assert.equal(error.response.status, 409)
		assert.deepEqual(error.response.data, { message: 'Transition not allowed' })
		return true
	})
})

test('each sign-up is declined at its own url', async () => {
	const http = client(200, {})
	await declineSignUp('one', 'a')
	await declineSignUp('two', 'b')
	assert.deepEqual(http.calls.map((c) => c.url), [
		'/nc/apps/openregister/api/objects/one/transition',
		'/nc/apps/openregister/api/objects/two/transition',
	])
})

test('a missing reason is sent as an empty decline input, not as "undefined"', async () => {
	const http = client(200, {})
	await declineSignUp('enr-8', undefined)
	assert.deepEqual(http.calls[0].body, { action: 'decline', data: { declineReason: '' } })
})
