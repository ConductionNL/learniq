// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Learniq Contributors
//
// The guard that decides whether this suite may touch the instance it is aimed
// at, tested without an instance.
//
// PORTED FROM hydra/templates/e2e/shared-instance.test.ts.tmpl, which is
// written for vitest. Learniq has no vitest: its JS unit runner is Node's own
// `node --test tests/unit-js/*.test.mjs` (package.json `test:js-unit`), and a
// vitest file dropped next to the guard would have passed when invoked by hand
// and never run anywhere else. Same cases, `node:test` and `node:assert`
// instead of `describe`/`expect`. Node 24 imports the `.ts` guard directly by
// stripping its types, so this needs no build step.
//
// Worth testing rather than reading, because every case here is one somebody
// already got wrong: `http://127.0.0.1` parses with an EMPTY port, so a port
// comparison that trusts `URL.port` misses the shared instance written without
// one, and a flag holding `1` used to permit every shared instance the suite
// ever met.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	APP_ID,
	assertInstancePermitted,
	isSharedOrigin,
	normaliseOrigin,
	occPrefix,
	SHARED_INSTANCE_FLAG,
} from '../e2e/shared-instance.ts'

/** An environment with neither flag set. */
const NONE = {}

test(`${APP_ID} guard folds every loopback spelling onto localhost`, () => {
	assert.equal(normaliseOrigin('http://127.0.0.1:8080'), 'http://localhost:8080')
	assert.equal(normaliseOrigin('http://[::1]:8080'), 'http://localhost:8080')
	assert.equal(normaliseOrigin('http://localhost:8080/'), 'http://localhost:8080')
})

test('the implicit port is made explicit', () => {
	assert.equal(normaliseOrigin('http://127.0.0.1'), 'http://localhost:80')
	assert.equal(normaliseOrigin('https://example.org'), 'https://example.org:443')
})

test('loopback 80 and 8080 are shared, and nothing else is', () => {
	assert.equal(isSharedOrigin('http://localhost:8080'), true)
	assert.equal(isSharedOrigin('http://127.0.0.1'), true)
	assert.equal(isSharedOrigin('http://localhost:8095'), false)
	assert.equal(isSharedOrigin('http://nextcloud.example.org:8080'), false)
})

test('a shared instance that no flag names is refused', () => {
	assert.throws(
		() => assertInstancePermitted('http://localhost:8080', NONE),
		/SHARED development instance/,
	)
})

test('a flag holding a boolean rather than an origin is refused', () => {
	assert.throws(() =>
		assertInstancePermitted('http://localhost:8080', {
			[SHARED_INSTANCE_FLAG]: '1',
		}),
	)
})

test('a flag that names a different origin is refused', () => {
	assert.throws(() =>
		assertInstancePermitted('http://localhost:8080', {
			[SHARED_INSTANCE_FLAG]: 'http://localhost:80',
		}),
	)
})

test('the origin the flag names is permitted, in any loopback spelling', () => {
	assert.equal(
		assertInstancePermitted('http://localhost:8080', {
			[SHARED_INSTANCE_FLAG]: 'http://127.0.0.1:8080',
		}),
		'http://localhost:8080',
	)
})

test('the fleet-wide spelling is accepted too', () => {
	assert.equal(
		assertInstancePermitted('http://localhost:8080', {
			E2E_ALLOW_SHARED_INSTANCE: 'http://localhost:8080',
		}),
		'http://localhost:8080',
	)
})

test('a disposable rig needs no flag', () => {
	assert.equal(
		assertInstancePermitted('http://localhost:8095', NONE),
		'http://localhost:8095',
	)
})

test('occ binds to a named container, and falls back to the server root', () => {
	assert.equal(occPrefix(NONE).join(' '), 'php occ')
	assert.equal(
		occPrefix({ NEXTCLOUD_CONTAINER: 'nextcloud' }).join(' '),
		'docker exec -u www-data nextcloud php occ',
	)
})
