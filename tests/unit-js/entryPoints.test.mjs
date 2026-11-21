// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The two small bundles that boot outside the app shell:
//
// - display.js, the public hall screen: mounts only when its host element is
//   on the page, and hands the screen the token from the initial state.
// - settings.js, the admin panel: mounts whatever happens to the translation
//   load. It used to mount inside the loadTranslations callback, and an
//   install that 404s the l10n file then rendered a blank panel.
//
// @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user

import assert from 'node:assert/strict'
import { register } from 'node:module'
import { test } from 'node:test'

// Each package and .vue import resolves to a stub that records into
// globalThis.__boot; only Vue, Pinia, the Nextcloud packages and the
// components are replaced, the entry files run for real.
const stubs = {
	vue: `export const h = (c, p) => ({ h: c, props: p })
export function createApp(root, props) {
	const app = { root, props, plugins: [], mixins: [], mountedOn: null,
		use(p) { app.plugins.push(p); return app },
		mixin(m) { app.mixins.push(m); return app },
		mount(el) { app.mountedOn = el; globalThis.__boot.mounted.push(app); return app } }
	globalThis.__boot.apps.push(app)
	return app
}`,
	pinia: 'export const createPinia = () => ({ pinia: true })',
	'@nextcloud/initial-state': 'export const loadState = (...a) => globalThis.__boot.loadState(...a)',
	'@nextcloud/l10n': `export const translate = (app, s) => s
export const translatePlural = (app, s, p, c) => (c === 1 ? s : p)
export const loadTranslations = (...a) => globalThis.__boot.loadTranslations(...a)`,
}
const hooks = `
const stubs = ${JSON.stringify(stubs)}
export async function resolve(specifier, context, next) {
	if (specifier in stubs) {
		return { url: 'data:text/javascript,' + encodeURIComponent(stubs[specifier]), shortCircuit: true }
	}
	if (specifier.endsWith('.vue')) {
		const name = specifier.split('/').pop()
		return { url: 'data:text/javascript,' + encodeURIComponent('export default { name: ' + JSON.stringify(name) + ' }'), shortCircuit: true }
	}
	return next(specifier, context)
}
`
register('data:text/javascript,' + encodeURIComponent(hooks))

let run = 0

/**
 * Boot one entry file fresh, with the page and the Nextcloud state given.
 *
 * @param {string} file The entry file under src/.
 * @param {object} [page] `elements` by id, `states` by key, `loadTranslations` stub.
 * @return {Promise<object>} What the boot recorded.
 */
async function boot(file, { elements = {}, states = {}, loadTranslations = () => undefined } = {}) {
	const boot = {
		apps: [],
		mounted: [],
		asked: [],
		translationsAsked: [],
		loadState: (app, key, fallback) => {
			boot.asked.push([app, key, fallback])
			return key in states ? states[key] : fallback
		},
		loadTranslations: (...args) => {
			boot.translationsAsked.push(args[0])
			return loadTranslations(...args)
		},
	}
	globalThis.__boot = boot
	globalThis.document = { getElementById: (id) => elements[id] ?? null }
	run += 1
	await import(`../../src/${file}?run=${run}`)
	return boot
}

test('the hall screen mounts on its host with the display token', async () => {
	const host = { id: 'learniq-display' }
	const booted = await boot('display.js', { elements: { 'learniq-display': host }, states: { 'display-token': 'tok-123' } })
	assert.equal(booted.apps.length, 1)
	assert.deepEqual(booted.apps[0].root, { name: 'DisplayScreenView.vue' })
	assert.deepEqual(booted.apps[0].props, { token: 'tok-123' })
	assert.equal(booted.apps[0].mountedOn, host)
	assert.deepEqual(booted.asked, [['learniq', 'display-token', '']])
})

test('the hall screen without a token in the state mounts with an empty one', async () => {
	const booted = await boot('display.js', { elements: { 'learniq-display': {} } })
	assert.deepEqual(booted.apps[0].props, { token: '' })
})

test('a page without the hall-screen host mounts nothing and reads no state', async () => {
	const booted = await boot('display.js')
	assert.deepEqual(booted.apps, [])
	assert.deepEqual(booted.asked, [])
})

test('the admin panel mounts on #learniq-settings with pinia and t/n', async () => {
	const booted = await boot('settings.js')
	assert.equal(booted.mounted.length, 1)
	const app = booted.mounted[0]
	assert.equal(app.mountedOn, '#learniq-settings')
	assert.deepEqual(app.plugins, [{ pinia: true }])
	assert.deepEqual(app.root.render(), { h: { name: 'AdminRoot.vue' }, props: undefined })
	const { t, n } = app.mixins[0].methods
	assert.equal(t('learniq', 'Save'), 'Save')
	assert.equal(n('learniq', 'one course', '{count} courses', 2), '{count} courses')
	assert.deepEqual(booted.translationsAsked, ['learniq'])
})

test('the admin panel still mounts when loading the translations throws', async () => {
	const booted = await boot('settings.js', {
		loadTranslations: () => {
			throw new Error('l10n/nl.json 404')
		},
	})
	assert.equal(booted.mounted.length, 1)
	assert.equal(booted.mounted[0].mountedOn, '#learniq-settings')
})

test('the admin panel still mounts, without an unhandled rejection, when the load rejects', async () => {
	const unhandled = []
	const listener = (reason) => unhandled.push(reason)
	process.on('unhandledRejection', listener)
	try {
		const booted = await boot('settings.js', { loadTranslations: () => Promise.reject(new Error('404')) })
		await new Promise((resolve) => setImmediate(resolve))
		assert.equal(booted.mounted.length, 1)
		assert.deepEqual(unhandled, [])
	} finally {
		process.off('unhandledRejection', listener)
	}
})

test('the admin panel mounts before a slow translation load settles', async () => {
	let settle
	const booted = await boot('settings.js', { loadTranslations: () => new Promise((resolve) => { settle = resolve }) })
	assert.equal(booted.mounted.length, 1)
	settle()
})
