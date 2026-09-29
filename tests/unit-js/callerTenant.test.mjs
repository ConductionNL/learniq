// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The caller's tenant reaches nextcloud-vue's tenant context.
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { normaliseCallerTenant } from '../../src/utils/callerTenant.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')

test('a tenant id passes through', () => {
	assert.equal(normaliseCallerTenant('tenant-school-a'), 'tenant-school-a')
})

test('anything that is not a non-empty string becomes null', () => {
	for (const value of [null, undefined, '', '   ', 42, {}, []]) {
		assert.equal(normaliseCallerTenant(value), null, JSON.stringify(value))
	}
})

test('boot reads callerTenant from the initial state and hands it to App', () => {
	const main = read('../../src/main.js')
	assert.match(main, /normaliseCallerTenant\(\s*loadState\('learniq', 'callerTenant', null\),?\s*\)/)
	assert.match(main, /callerTenant,?\s*\n/)
})

test('App feeds it to CnAppRoot as the tenant context and keeps the badge hidden', () => {
	const app = read('../../src/App.vue')
	assert.match(app, /:initialOrganisationUuid="callerTenant"/)
	// A non-empty slot: Vue 3 renders the fallback badge for an empty one.
	assert.match(app, /<template #tenant-badge>\s*<span hidden \/>\s*<\/template>/)
})
