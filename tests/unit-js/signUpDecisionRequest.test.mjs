// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Declining one pending sign-up: the request goes to the Enrolment's own
// lifecycle transition with the `decline` action and the trimmed reason,
// and the axios answer (or its refusal) reaches the caller unchanged.
//
// The real module is imported, so its coverage counts; a module hook answers
// its two Nextcloud imports (which need a browser) with the stand-ins below.
//
// @spec openspec/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager

import assert from 'node:assert/strict'
import { register } from 'node:module'
import { test } from 'node:test'
import { recordingAxios } from './support/loadSfc.mjs'

let reply = () => ({ data: {} })
const http = recordingAxios((call) => reply(call))
globalThis.__signUpDecisionRequestStubs = {
	axios: http,
	generateUrl: (path, params = {}) => `/nc${path.replace(/\{(\w+)\}/g, (_, key) => params[key])}`,
}

const STUBS = {
	'@nextcloud/axios': 'export default globalThis.__signUpDecisionRequestStubs.axios',
	'@nextcloud/router': 'export const generateUrl = globalThis.__signUpDecisionRequestStubs.generateUrl',
}
const hooks = `
const STUBS = ${JSON.stringify(STUBS)}
export async function resolve(specifier, context, next) {
	if (specifier in STUBS && context.parentURL?.endsWith('/src/utils/signUpDecisionRequest.js')) {
		return { url: 'data:text/javascript,' + encodeURIComponent(STUBS[specifier]), shortCircuit: true }
	}
	return next(specifier, context)
}`
register(`data:text/javascript,${encodeURIComponent(hooks)}`)

const { declineSignUp } = await import('../../src/utils/signUpDecisionRequest.js')

test('a decline posts the decline transition for that enrolment with the trimmed reason', async () => {
	http.calls.length = 0
	reply = () => ({ data: { lifecycle: 'declined' } })

	const answer = await declineSignUp('enr-7', '  Course is full  ')

	assert.deepEqual(answer, { data: { lifecycle: 'declined' } })
	assert.equal(http.calls.length, 1)
	assert.equal(http.calls[0].method, 'post')
	assert.equal(http.calls[0].url, '/nc/apps/openregister/api/objects/enr-7/transition')
	assert.deepEqual(http.calls[0].body, { action: 'decline', data: { declineReason: 'Course is full' } })
})

test('a missing reason is sent as an empty one, not as "undefined"', async () => {
	http.calls.length = 0
	reply = () => ({ data: {} })

	await declineSignUp('enr-8', undefined)

	assert.deepEqual(http.calls[0].body, { action: 'decline', data: { declineReason: '' } })
})

test('a refused transition rejects with the server\'s error', async () => {
	reply = () => {
		throw new Error('Transition not allowed')
	}

	await assert.rejects(declineSignUp('enr-9', 'Too late'), /Transition not allowed/)
})
