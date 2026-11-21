// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The exchange panel says it is read-only, and the fee item lists carry the
// breadcrumb "Betalingen / Schoolkosten", as the boards LqUitwisselingen and
// LqSchoolkosten draw them.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const readJson = (path) => JSON.parse(readFileSync(new URL(path, import.meta.url), 'utf8'))
const NL = readJson('../../l10n/nl.json').translations

/**
 * One page of a manifest fragment.
 *
 * @param {string} fragment The fragment file name.
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(fragment, id) {
	return readJson(`../../src/manifest.d/${fragment}`).pages.find((p) => p.id === id)
}

test('the exchange panel lists read "Alleen lezen"', () => {
	for (const id of ['ExchangeJobs', 'ExchangeRejections']) {
		const config = page('data-exchange.json', id).config
		assert.equal(config.headerMeta, 'Read only', id)
		assert.equal(config.showAdd, false, id)
	}
	assert.equal(NL['Read only'], 'Alleen lezen')
})

test('the fee item lists carry the breadcrumb Betalingen / Schoolkosten', () => {
	for (const id of ['FeeItems', 'Entitlements']) {
		const labels = page('payments.json', id).config.breadcrumb.map((c) => NL[c.label])
		assert.deepEqual(labels, ['Betalingen', 'Schoolkosten'], id)
	}
	assert.equal(NL['Fee items'], 'Kostenposten')
})
