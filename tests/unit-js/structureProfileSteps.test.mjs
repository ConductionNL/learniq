// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The structure profile's building steps, one branch at a time: how a page
// overlay changes a page, how the nav placeholders resolve and gate, and how
// the profiled manifest is put together around buildManifest.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	applyPageOverlay,
	buildProfiledManifest,
	resolveNavPlaceholders,
	resolveProfileNav,
} from '../../src/utils/structureProfile.js'

test('an overlay patches, drops, appends and orders list items by name', () => {
	const page = {
		id: 'P',
		type: 'index',
		title: 'Old',
		component: 'X',
		slots: { a: 'A' },
		config: {
			columns: ['name', { key: 'age' }, { label: 'Open' }, { id: 'gone' }],
			widgets: 'not a list',
			kept: 1,
		},
	}
	const out = applyPageOverlay(page, {
		config: { kept: 2, fresh: true },
		configPatch: {
			columns: { name: { never: 'applied to a string' }, age: { wide: true }, gone: null },
			widgets: { w: { x: 1 } },
			missing: null,
		},
		configAppend: { columns: [{ key: 'new' }], extra: 'not a list', more: [1] },
		configOrder: { columns: ['Open', 'nobody', 'new'], other: 'x' },
		page: { type: 'custom', title: null, component: undefined },
		slots: { b: 'B' },
	})
	assert.deepEqual(out, {
		id: 'P',
		type: 'custom',
		component: 'X',
		slots: { a: 'A', b: 'B' },
		config: {
			columns: [{ label: 'Open' }, { key: 'new' }, 'name', { key: 'age', wide: true }],
			widgets: [],
			kept: 2,
			fresh: true,
			missing: [],
			extra: [],
			more: [1],
			other: [],
		},
	})
	assert.equal(page.title, 'Old')
})

test('an overlay without changes still copies the config, and a non-object slots is ignored', () => {
	const page = { id: 'P' }
	const out = applyPageOverlay(page, { slots: 'nope', configPatch: { list: undefined } })
	assert.deepEqual(out, { id: 'P', config: { list: [] } })
	assert.deepEqual(applyPageOverlay({ id: 'Q', config: { a: 1 } }, { slots: { s: 1 } }), {
		id: 'Q',
		config: { a: 1 },
		slots: { s: 1 },
	})
})

test('nav placeholders resolve one level deep, with fallbacks, and drop notes', () => {
	const nav = {
		_note: 'for the reader',
		title: '@theming.name',
		plain: 'learniq',
		count: 3,
		list: ['@theming.name'],
		brand: {
			logo: '@theming.emblem | @theming.logo|plain',
			caption: '@theming.missing',
			size: 2,
		},
		empty: null,
	}
	assert.deepEqual(resolveNavPlaceholders(nav, { name: 'School', emblem: '', logo: '/l.png', n: 1 }), {
		title: 'School',
		plain: 'learniq',
		count: 3,
		list: ['@theming.name'],
		brand: { logo: '/l.png', caption: '', size: 2 },
		empty: null,
	})
	assert.deepEqual(resolveNavPlaceholders({ t: '@theming.name', u: '@theming.n' }, 'not an object'), { t: '', u: '' })
	assert.deepEqual(resolveNavPlaceholders(undefined, null), {})
	assert.deepEqual(resolveNavPlaceholders({ t: '@theming.n' }, { n: 1 }), { t: '' })
})

test('a gated primary action is kept only for a reader the gate passes for', () => {
	const nav = { primaryAction: { label: 'Go', visibleIf: { role: 'x' } }, brand: { name: 'b' } }
	const seen = []
	const yes = (predicate, runtime) => {
		seen.push([predicate, runtime])
		return true
	}
	assert.deepEqual(resolveProfileNav(nav, null, yes, null), {
		primaryAction: { label: 'Go' },
		brand: { name: 'b' },
	})
	assert.deepEqual(seen, [[{ role: 'x' }, {}]])
	assert.deepEqual(resolveProfileNav(nav, { user: 1 }, () => 0, null), { brand: { name: 'b' } })
	assert.deepEqual(resolveProfileNav(nav, { user: 1 }, undefined, null), { brand: { name: 'b' } })
	assert.deepEqual(resolveProfileNav({ primaryAction: { label: 'Go' } }, {}, undefined, null), {
		primaryAction: { label: 'Go' },
	})
	assert.deepEqual(resolveProfileNav({ primaryAction: 'Go' }, {}, undefined, null), { primaryAction: 'Go' })
})

/**
 * A stand-in for the library's buildManifest that records what it was given.
 *
 * @param {Array} calls Where the calls are recorded.
 * @return {Function} The stand-in.
 */
function recordingBuild(calls) {
	return (base, fragments, layout) => {
		calls.push({ base, fragments, layout })
		return { pages: (base.pages || []).map((page) => ({ ...page })), nav: base.nav, menu: base.menu }
	}
}

test('a profile without menu, nav, defaults and pages goes through buildManifest unchanged', () => {
	const calls = []
	const base = { menu: [{ id: 'm' }], pages: [{ id: 'A' }] }
	const out = buildProfiledManifest(recordingBuild(calls), base, ['f'], {
		relocations: [1],
		removals: undefined,
		settingsSection: 's',
		other: 'ignored',
	})
	assert.equal(calls[0].base, base)
	assert.deepEqual(calls[0].fragments, ['f'])
	assert.deepEqual(calls[0].layout, { relocations: [1], settingsSection: 's' })
	assert.deepEqual(out, { pages: [{ id: 'A' }], nav: undefined, menu: [{ id: 'm' }] })
	assert.deepEqual(buildProfiledManifest(recordingBuild(calls), base, [], null).pages, [{ id: 'A' }])
	assert.deepEqual(calls[1].layout, {})
})

test('a profile menu goes before the base menu as copies, and its nav merges over the built nav', () => {
	const calls = []
	const entry = { id: 'p', order: 1 }
	const base = { menu: [{ id: 'm' }], nav: { brand: { name: 'x' }, keep: 1 }, runtime: { r: 1 } }
	const out = buildProfiledManifest(
		recordingBuild(calls),
		base,
		[],
		{ menu: [entry], nav: { brand: { caption: '@theming.name' }, primaryAction: { label: 'Go', visibleIf: {} } } },
		(predicate, runtime) => runtime.r === 1,
		{ theming: { name: 'School' } },
	)
	assert.deepEqual(calls[0].base.menu, [{ id: 'p', order: 1 }, { id: 'm' }])
	assert.notEqual(calls[0].base.menu[0], entry)
	assert.deepEqual(out.nav, { brand: { caption: 'School' }, keep: 1, primaryAction: { label: 'Go' } })
	const noTheming = buildProfiledManifest(recordingBuild(calls), { menu: [] }, [], { menu: [], nav: { t: '@theming.name' } })
	assert.deepEqual(noTheming.nav, { t: '' })
	assert.deepEqual(calls[1].base, { menu: [] })
	assert.deepEqual(
		buildProfiledManifest(recordingBuild(calls), {}, [], { menu: [{ id: 'q' }], nav: 'not an object' }).menu,
		[{ id: 'q' }],
	)
})

test('page overlays apply in order, skip unknown pages with a warning and respect their gate', () => {
	const warned = []
	const warn = console.warn
	console.warn = (...args) => warned.push(args)
	try {
		const base = {
			runtime: { role: 't' },
			pages: [{ id: 'A', type: 'index' }, { id: 'B', type: 'index' }, { id: 'C' }],
		}
		const file = {
			pageDefaults: { index: { headerFilters: false } },
			pages: [
				{ id: 'A', page: { title: 'First' } },
				{ id: 'Nope', page: { title: 'x' } },
				null,
				{ id: 'B', when: { role: 't' }, page: { title: 'Gated' } },
				{ id: 'C', when: { role: 'x' }, page: { title: 'Never' } },
				{ id: 'A', config: { second: true } },
			],
		}
		const passes = (when, runtime) => when.role === runtime.role
		const out = buildProfiledManifest(recordingBuild([]), base, [], file, passes)
		assert.deepEqual(out.pages, [
			{ id: 'A', type: 'index', title: 'First', config: { headerFilters: false, second: true } },
			{ id: 'B', type: 'index', title: 'Gated', config: { headerFilters: false } },
			{ id: 'C' },
		])
		assert.equal(warned.length, 2)
		assert.deepEqual(warned[0], [
			'[learniq] structureProfile: page overlay names a page the manifest does not have; skipped.',
			{ page: 'Nope' },
		])
		assert.deepEqual(warned[1][1], { page: undefined })
		const ungated = buildProfiledManifest(recordingBuild([]), base, [], { pages: [file.pages[3]] })
		assert.deepEqual(ungated.pages[1], { id: 'B', type: 'index' })
	} finally {
		console.warn = warn
	}
})
