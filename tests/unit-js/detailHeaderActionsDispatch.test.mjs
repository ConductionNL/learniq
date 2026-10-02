// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// "Ask for a correction" on a grade entry did nothing (live pass 2 Oct, D3):
// the console said `[dispatchAction] Handler "navigate" not found in
// context.handlers.`. A detail page's header actions run through the
// library's dispatchAction, which knows `type: navigate` (a path) and
// `type: open-page` (a route name) but reads `handler: "navigate"` as the
// name of an app handler, and learniq registers no handler by that name.
// CnIndexPage does read `handler: navigate`, so the same manifest key works on
// an index page and silently does nothing on a detail page.
//
// This test builds the manifest the way src/main.js does, takes every header
// action on every detail page, and dispatches it with the library's own
// dispatchAction, the app's own handler registry (App.vue) and a router that
// resolves names and paths against the manifest's pages. An action that
// warns, or reaches no page, fails.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'

// @nextcloud/* read window on import; plain node has none.
globalThis.window = globalThis.window ?? globalThis

const { buildManifest } = await import('../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js')
const { dispatchAction } = await import('../../node_modules/@conduction/nextcloud-vue/src/utils/actionsDispatcher.js')
const { createConnectionHandlers } = await import('../../src/utils/connectionRegistry.js')

const dir = new URL('../../src/manifest.d/', import.meta.url)
const readJson = (url) => JSON.parse(readFileSync(url, 'utf8'))
const manifest = buildManifest(
	readJson(new URL('../../src/manifest.json', import.meta.url)),
	readdirSync(dir).filter((n) => n.endsWith('.json')).sort().map((n) => readJson(new URL(n, dir))),
	readJson(new URL('../../src/menu-layout.json', import.meta.url)),
)

/**
 * Whether a concrete path matches a manifest route pattern.
 *
 * @param {string} pattern The page route, with :params.
 * @param {string} path The path pushed.
 * @return {boolean}
 */
function matches(pattern, path) {
	const re = new RegExp('^' + pattern.replace(/:[A-Za-z]+/g, '[^/]+') + '$')
	return re.test(path.split('?')[0])
}

/**
 * Dispatch one action as the detail page does and report where it went.
 *
 * @param {object} action The header action.
 * @return {{warnings: Array<string>, page: (object|null), handled: boolean}}
 */
function run(action) {
	const warnings = []
	const pushed = []
	let handled = false
	const original = console.warn
	console.warn = (...args) => warnings.push(args.join(' '))
	try {
		const handlers = createConnectionHandlers({ generateUrl: (p) => p, assign: () => { handled = true } })
		dispatchAction(action, {
			handlers,
			router: { push: (to) => pushed.push(to) },
			tokenCtx: { objectId: 'c01aa152-0000-4000-8000-000000000001', object: { id: 'c01aa152-0000-4000-8000-000000000001' } },
		})
	} finally {
		console.warn = original
	}
	let page = null
	for (const to of pushed) {
		page = typeof to === 'string'
			? manifest.pages.find((p) => matches(p.route, to)) ?? null
			: manifest.pages.find((p) => p.id === to?.name) ?? null
	}
	return { warnings, page, handled }
}

const detailActions = manifest.pages
	.filter((p) => p.type === 'detail')
	.flatMap((p) => (p.config?.headerActions ?? []).map((a) => ({ pageId: p.id, action: a })))
	.filter(({ action }) => !['api-call', 'open-form', 'toggle', 'open-modal', 'export', 'object-op', 'agent', 'run-node'].includes(action.type))

test('the detail-page header actions under test exist', () => {
	assert.ok(detailActions.some(({ action }) => action.id === 'ask-correction'), 'ask-correction is not a detail header action')
})

// Known, not this test's defect: the item bank's QTI export is a download URL
// sent through the router; CnActionButtons renders it as a link, which this
// dispatcher-level test does not model. Reported to the live pass (2 Oct).
const KNOWN = new Set(['ItemBankDetail > export-qti'])

test('every navigating detail-page header action reaches a page or a registered handler', () => {
	const failures = []
	for (const { pageId, action } of detailActions) {
		if (KNOWN.has(`${pageId} > ${action.id}`)) {
			continue
		}
		const { warnings, page, handled } = run(action)
		if (warnings.length > 0 || (page === null && handled === false)) {
			failures.push(`${pageId} > ${action.id}: ${warnings.join(' | ') || 'reached no page'}`)
		}
	}
	assert.deepEqual(failures, [])
})

test('Ask for a correction opens the corrections page', () => {
	const { action } = detailActions.find(({ action }) => action.id === 'ask-correction')
	const { page } = run(action)
	assert.equal(page?.route, '/grades/corrections')
})
