// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Saving the action-authorization matrix (ADR-023).
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).
//
// The admin matrix used to PUT with a bare fetch: no CSRF token header and no
// status check. A refused save read the error body as the saved matrix, so
// every tick vanished from the screen and the admin was told it was saved.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	matrixPayload,
	readMatrix,
	saveActionMatrix,
} from '../../src/utils/actionMatrix.js'

const ENDPOINT = '/index.php/apps/learniq/api/admin/action-matrix'
const actions = ['course.create', 'grade.publish']
const ticked = { 'course.create': ['admin', 'teachers'], 'grade.publish': [] }

/**
 * An axios-like client that rejects the way axios does on a non-2xx status.
 *
 * @param {number} status The status to answer with.
 * @param {object} data The body to answer with.
 * @return {{put: Function, calls: Array}} The client and the calls it saw.
 */
function client(status, data) {
	const calls = []
	return {
		calls,
		put: async (url, body) => {
			calls.push({ url, body })
			if (status >= 200 && status < 300) {
				return { status, data }
			}
			const error = new Error(`Request failed with status code ${status}`)
			error.response = { status, data }
			throw error
		},
	}
}

test('a save sends admin first plus every ticked group', async () => {
	const http = client(200, { matrix: {} })
	await saveActionMatrix(http, ENDPOINT, actions, ticked)
	assert.deepEqual(http.calls, [
		{
			url: ENDPOINT,
			body: {
				matrix: {
					'course.create': ['admin', 'teachers'],
					'grade.publish': ['admin'],
				},
			},
		},
	])
})

test('a successful save returns the matrix the server stored', async () => {
	const stored = { 'course.create': ['admin', 'teachers'], 'grade.publish': ['admin'] }
	const result = await saveActionMatrix(client(200, { matrix: stored }), ENDPOINT, actions, ticked)
	assert.deepEqual(result, { ok: true, matrix: stored })
})

test('a refused save reports the server reason and leaves the ticks alone', async () => {
	const before = structuredClone(ticked)
	const result = await saveActionMatrix(
		client(400, { error: 'Unknown action: grade.delete' }),
		ENDPOINT,
		actions,
		ticked,
	)
	assert.deepEqual(result, { ok: false, error: 'Unknown action: grade.delete' })
	assert.deepEqual(ticked, before)
})

test('a save refused without a reason says so with no reason', async () => {
	for (const data of [undefined, {}, { error: '' }, '<html>Forbidden</html>']) {
		const result = await saveActionMatrix(client(412, data), ENDPOINT, actions, ticked)
		assert.deepEqual(result, { ok: false, error: null })
	}
})

test('readMatrix keeps one list per declared action and copies it', () => {
	const source = { 'course.create': ['teachers'], stray: ['x'] }
	const matrix = readMatrix(actions, source)
	assert.deepEqual(matrix, { 'course.create': ['teachers'], 'grade.publish': [] })
	assert.notEqual(matrix['course.create'], source['course.create'])
	assert.deepEqual(readMatrix(actions, null), { 'course.create': [], 'grade.publish': [] })
})

test('matrixPayload never lists admin twice', () => {
	assert.deepEqual(matrixPayload(['a'], { a: ['admin', 'g'] }), { a: ['admin', 'g'] })
})

test('the matrix component talks to the server through @nextcloud/axios, which sends the CSRF token', () => {
	const source = readFileSync(
		new URL('../../src/components/admin/ActionAuthMatrix.vue', import.meta.url),
		'utf8',
	)
	assert.match(source, /import axios from '@nextcloud\/axios'/)
	assert.match(source, /saveActionMatrix\(/)
	assert.doesNotMatch(source, /\bfetch\(/)
})
