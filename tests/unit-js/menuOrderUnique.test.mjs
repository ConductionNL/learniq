// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// No two menu siblings share an `order` (learniq#1476).
//
// CnAppNav sorts every level by `order` with a stable sort, so siblings with
// the same value render in merged array position. That position depends on
// which fragment declares an entry, the fragment load order and the
// relocations in menu-layout.json. A refactor that only moves an entry
// between fragments then reorders the nav without anyone touching `order`:
// the manifest-fragment-split pixel diff swapped "My learning record" with
// "BPV" exactly this way.
//
// The menu is BUILT here, not read, with the library's own buildManifest()
// and the app's menu-layout.json, as src/main.js does: relocations move
// entries between groups, so ties only show in the built tree.
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'

const dir = new URL('../../src/manifest.d/', import.meta.url)
const readJson = (url) => JSON.parse(readFileSync(url, 'utf8'))

/**
 * The menu as src/main.js builds it: fragments in require.context key order.
 *
 * @return {Array<object>} The built top-level menu.
 */
function builtMenu() {
	const fragments = readdirSync(dir)
		.filter((name) => name.endsWith('.json'))
		.map((name) => './' + name)
		.sort()
		.map((key) => readJson(new URL(key.slice(2), dir)))

	return buildManifest(
		readJson(new URL('../../src/manifest.json', import.meta.url)),
		fragments,
		readJson(new URL('../../src/menu-layout.json', import.meta.url)),
	).menu
}

/**
 * Every `order` value shared by two or more siblings, per level.
 *
 * @param {Array<object>} items One menu level.
 * @param {string} path The level's name, for the message.
 * @param {Array<string>} out Receives one line per tie.
 * @return {Array<string>} out
 */
function ties(items, path, out = []) {
	const byOrder = new Map()
	for (const item of items) {
		if (typeof item.order === 'number') {
			byOrder.set(item.order, [...(byOrder.get(item.order) ?? []), item.id])
		}
		if (Array.isArray(item.children)) {
			ties(item.children, `${path} > ${item.id}`, out)
		}
	}
	for (const [order, ids] of byOrder) {
		if (ids.length > 1) {
			out.push(`${path}: order ${order} is shared by ${ids.join(', ')}`)
		}
	}
	return out
}

test('no two menu siblings share an order, at any level of the built menu', () => {
	assert.deepEqual(ties(builtMenu(), 'top'), [])
})

test('the tie check sees a tie inside a group', () => {
	const menu = [
		{
			id: 'G',
			order: 1,
			children: [
				{ id: 'a', order: 5 },
				{ id: 'b', order: 5 },
			],
		},
	]
	assert.deepEqual(ties(menu, 'top'), ['top > G: order 5 is shared by a, b'])
})
