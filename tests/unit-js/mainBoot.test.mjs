// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// main.js, the app shell's boot: it seeds the library registries, builds the
// manifest runtime from the initial state, picks the menu layout for the
// structure profile, builds one route per manifest page under a base taken
// from the URL the visitor arrived on, and mounts on #learniq-app whatever
// happens to the translation load.
//
// The library, Vue, vue-router and the Nextcloud packages are stubs that
// record into globalThis.__boot; .vue and .css imports are empty modules;
// .json imports load the real files. The app's own utils run for real.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { register } from 'node:module'
import { test } from 'node:test'

const stubs = {
	vue: `export const h = (c, p) => ({ h: c, props: p })
export function createApp(root) {
	const app = { root, plugins: [], mixins: [], mountedOn: null,
		use(p) { app.plugins.push(p); return app },
		mixin(m) { app.mixins.push(m); return app },
		mount(el) { app.mountedOn = el; globalThis.__boot.mounted.push(app); return app } }
	globalThis.__boot.apps.push(app)
	return app
}`,
	'vue-router': `export const createWebHistory = (base) => ({ base })
export const createRouter = (options) => { const router = { router: true, ...options }; globalThis.__boot.routers.push(router); return router }`,
	pinia: 'export const createPinia = () => ({ pinia: true })',
	'@conduction/nextcloud-vue': `const call = (name) => (...a) => globalThis.__boot.call(name, a)
export const CnPageRenderer = { name: 'CnPageRenderer' }
export const defaultPageTypes = { index: 'IndexType', detail: 'DetailType' }
export const buildManifest = (...a) => globalThis.__boot.buildManifest(...a)
export const installIntegrationRegistry = call('installIntegrationRegistry')
export const registerBuiltinDashboardWidgets = call('registerBuiltinDashboardWidgets')
export const registerBuiltinIntegrations = call('registerBuiltinIntegrations')
export const registerIcons = call('registerIcons')
export const registerLeafIntegrations = call('registerLeafIntegrations')
export const registerTranslations = (...a) => globalThis.__boot.registerTranslations(...a)`,
	'@conduction/nextcloud-vue/src/utils/visibleIfContext.js': 'export const passesContextPredicates = () => true',
	'@nextcloud/capabilities': 'export const getCapabilities = () => globalThis.__boot.capabilities',
	'@nextcloud/initial-state': 'export const loadState = (...a) => globalThis.__boot.loadState(...a)',
	'@nextcloud/l10n': `export const translate = (app, s) => s
export const translatePlural = (app, s, p, c) => (c === 1 ? s : p)
export const loadTranslations = (...a) => globalThis.__boot.loadTranslations(...a)`,
	'@nextcloud/router': 'export const generateUrl = (path) => "/generated" + path',
}
const hooks = `
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
const stubs = ${JSON.stringify(stubs)}
const empty = 'data:text/javascript,' + encodeURIComponent('export default {}')
export async function resolve(specifier, context, next) {
	if (specifier in stubs) {
		return { url: 'data:text/javascript,' + encodeURIComponent(stubs[specifier]), shortCircuit: true }
	}
	if (specifier.endsWith('.css')) {
		return { url: empty, shortCircuit: true }
	}
	if (specifier.endsWith('.vue')) {
		const name = specifier.split('/').pop()
		return { url: 'data:text/javascript,' + encodeURIComponent('export default { name: ' + JSON.stringify(name) + ' }'), shortCircuit: true }
	}
	return next(specifier, context)
}
export async function load(url, context, next) {
	if (url.startsWith('file:') && url.split('?')[0].endsWith('.json')) {
		return { format: 'module', source: 'export default ' + readFileSync(fileURLToPath(url.split('?')[0]), 'utf8'), shortCircuit: true }
	}
	return next(url, context)
}
`
register('data:text/javascript,' + encodeURIComponent(hooks))

const appIcons = (await import('../../src/icons.js')).default
const registry = (await import('../../src/registry.js')).default
const menuLayoutFull = (await import('../../src/menu-layout.json')).default
const menuLayoutSimple = (await import('../../src/menu-layout.simple.json')).default

let run = 0

/**
 * Boot main.js fresh with the initial state, URL and fragments given.
 *
 * @param {object} [page] `states` by key, `pathname`, `fragments` by file name, and stand-ins for the library calls.
 * @return {Promise<object>} What the boot recorded.
 */
async function boot({
	states = {},
	pathname = '/apps/learniq/',
	fragments = {},
	registerTranslations = () => undefined,
	loadTranslations = () => undefined,
	pages = [{ id: 'Dashboard', route: '/' }, { id: 'CourseDetail', route: '/courses/:id' }],
} = {}) {
	const boot = {
		apps: [],
		mounted: [],
		routers: [],
		calls: [],
		builds: [],
		translationsAsked: [],
		capabilities: { theming: { name: 'Het Lyceum' } },
		call: (name, args) => boot.calls.push([name, ...args]),
		registerTranslations: (...args) => {
			boot.calls.push(['registerTranslations'])
			return registerTranslations(...args)
		},
		loadTranslations: (...args) => {
			boot.translationsAsked.push(args[0])
			return loadTranslations(...args)
		},
		loadState: (app, key, fallback) => (key in states ? states[key] : fallback),
		buildManifest: (base, frags, layout) => {
			boot.builds.push({ base, fragments: frags, layout })
			return { ...base, pages }
		},
	}
	globalThis.__boot = boot
	globalThis.window = { location: { pathname } }
	const context = (key) => fragments[key]
	context.keys = () => Object.keys(fragments)
	globalThis.require = { context: (dir, deep, pattern) => { boot.context = [dir, deep, pattern]; return context } }
	run += 1
	await import(`../../src/main.js?run=${run}`)
	return boot
}

test('boot seeds the widget catalog and the integrations in order, then the icons and translations', async () => {
	const booted = await boot()

	assert.deepEqual(booted.calls.map(([name]) => name), [
		'registerBuiltinDashboardWidgets',
		'installIntegrationRegistry',
		'registerBuiltinIntegrations',
		'registerLeafIntegrations',
		'registerIcons',
		'registerTranslations',
	])
	assert.equal(booted.calls[4][1], appIcons)
	assert.deepEqual(booted.translationsAsked, ['learniq'])
})

test('the app mounts on #learniq-app with pinia, the router and t/n, rendering App with the shell props', async () => {
	const booted = await boot({ states: { callerTenant: '  school-7  ' } })

	assert.equal(booted.mounted.length, 1)
	const app = booted.mounted[0]
	assert.equal(app.mountedOn, '#learniq-app')
	assert.deepEqual(app.plugins[0], { pinia: true })
	assert.equal(app.plugins[1], booted.routers[0])
	const { t, n } = app.mixins[0].methods
	assert.equal(t('learniq', 'Courses'), 'Courses')
	assert.equal(n('learniq', 'one course', '{count} courses', 1), 'one course')

	const rendered = app.root.render()
	assert.deepEqual(rendered.h, { name: 'App.vue' })
	assert.equal(rendered.props.callerTenant, 'school-7')
	assert.deepEqual(rendered.props.pageTypes, { index: 'IndexType', detail: 'DetailType' })
	assert.deepEqual(rendered.props.registry, { ...registry })
	assert.notEqual(rendered.props.registry, registry)
	assert.deepEqual(rendered.props.manifest.pages.map((p) => p.id), ['Dashboard', 'CourseDetail'])
})

test('a blank or missing caller tenant reaches App as null', async () => {
	const blank = await boot({ states: { callerTenant: '   ' } })
	assert.equal(blank.mounted[0].root.render().props.callerTenant, null)
	const missing = await boot()
	assert.equal(missing.mounted[0].root.render().props.callerTenant, null)
})

test('each manifest page becomes a route named by its id, with props only for a parameterised route, then the catch-all', async () => {
	const booted = await boot()
	const { routes } = booted.routers[0]

	assert.equal(routes.length, 3)
	assert.deepEqual(routes.slice(0, 2).map(({ name, path, props }) => ({ name, path, props })), [
		{ name: 'Dashboard', path: '/', props: false },
		{ name: 'CourseDetail', path: '/courses/:id', props: true },
	])
	assert.equal(routes[0].component.name, 'CnPageRenderer')
	assert.equal(routes[0].component, routes[1].component)
	assert.deepEqual(routes[2], { path: '/:pathMatch(.*)*', redirect: '/' })
})

test('the router base is the app path the visitor arrived on, with or without /index.php', async () => {
	const pretty = await boot({ pathname: '/apps/learniq/courses' })
	assert.deepEqual(pretty.routers[0].history, { base: '/apps/learniq' })

	const indexPhp = await boot({ pathname: '/index.php/apps/learniq/courses/42' })
	assert.deepEqual(indexPhp.routers[0].history, { base: '/index.php/apps/learniq' })

	const bare = await boot({ pathname: '/index.php/apps/learniq' })
	assert.deepEqual(bare.routers[0].history, { base: '/index.php/apps/learniq' })
})

test('a URL outside the app path falls back to the generated base, and a look-alike app name does not match', async () => {
	const elsewhere = await boot({ pathname: '/settings/admin' })
	assert.deepEqual(elsewhere.routers[0].history, { base: '/generated/apps/learniq' })

	const lookAlike = await boot({ pathname: '/apps/learniqx/courses' })
	assert.deepEqual(lookAlike.routers[0].history, { base: '/generated/apps/learniq' })
})

test('the runtime user is built from the initial state, with exact booleans for the group flags', async () => {
	const booted = await boot({
		states: {
			primaryRole: 'instructor',
			dashboardRoles: ['teacher', 'admin'],
			confidentialCounsellor: true,
			managesLearners: 'yes',
		},
	})
	const { user } = booted.builds[0].base.runtime

	assert.equal(user.primaryRole, 'instructor')
	assert.equal(user.canAdminDashboard, true)
	assert.equal(user.canTeachDashboard, true)
	assert.equal(user.canLearnDashboard, false)
	assert.equal(user.isConfidentialCounsellor, true)
	assert.equal(user.managesLearners, false)
})

test('without initial state the user is a learner on the student dashboard only', async () => {
	const booted = await boot()
	const { user, workspace } = booted.builds[0].base.runtime

	assert.equal(user.primaryRole, 'learner')
	assert.equal(user.canLearnDashboard, true)
	assert.equal(user.canTeachDashboard, false)
	assert.equal(user.canAdminDashboard, false)
	assert.equal(user.isConfidentialCounsellor, false)
	assert.equal(user.managesLearners, false)
	assert.equal(workspace.chosenSegment, null)
	assert.equal(typeof workspace.segment, 'string')
})

test('a null dashboardRoles state grants no dashboard at all instead of failing', async () => {
	const booted = await boot({ states: { dashboardRoles: null } })
	const { user } = booted.builds[0].base.runtime

	assert.equal(user.canLearnDashboard, false)
	assert.equal(user.canTeachDashboard, false)
	assert.equal(user.canAdminDashboard, false)
	assert.equal(booted.mounted.length, 1)
})

test('the manifest.d fragments are read from ./manifest.d/ in file-name order', async () => {
	const booted = await boot({ fragments: { './b-reports.json': { id: 'b' }, './a-courses.json': { id: 'a' } } })

	assert.deepEqual(booted.context, ['./manifest.d/', false, /\.json$/])
	assert.deepEqual(booted.builds[0].fragments, [{ id: 'a' }, { id: 'b' }])
})

test('the full structure profile builds from menu-layout.json, anything else from the simple layout', async () => {
	const full = await boot({ states: { menu_structure: 'full' } })
	const simple = await boot({ states: { menu_structure: 'simple' } })
	const unknown = await boot({ states: { menu_structure: 'compact' } })

	assert.notDeepEqual(full.builds[0].layout, simple.builds[0].layout)
	assert.deepEqual(unknown.builds[0].layout, simple.builds[0].layout)
	assert.notDeepEqual(menuLayoutFull, menuLayoutSimple)
})

test('a failing registerTranslations is warned about and the app still mounts', async () => {
	const warned = []
	const original = console.warn
	console.warn = (...args) => warned.push(args)
	try {
		const booted = await boot({ registerTranslations: () => { throw new Error('no lib l10n') } })
		assert.equal(booted.mounted.length, 1)
		const ours = warned.filter((args) => /registerTranslations failed/.test(String(args[0])))
		assert.equal(ours.length, 1)
		assert.equal(ours[0][1].message, 'no lib l10n')
	} finally {
		console.warn = original
	}
})

test('the app mounts when loading the translations throws or rejects, without an unhandled rejection', async () => {
	const unhandled = []
	const listener = (reason) => unhandled.push(reason)
	process.on('unhandledRejection', listener)
	try {
		const thrown = await boot({ loadTranslations: () => { throw new Error('l10n/nl.json 404') } })
		assert.equal(thrown.mounted.length, 1)

		const rejected = await boot({ loadTranslations: () => Promise.reject(new Error('404')) })
		await new Promise((resolve) => setImmediate(resolve))
		assert.equal(rejected.mounted.length, 1)
		assert.deepEqual(unhandled, [])
	} finally {
		process.off('unhandledRejection', listener)
	}
})
