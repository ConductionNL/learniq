// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import {
	buildManifest,
	CnPageRenderer,
	defaultPageTypes,
	installIntegrationRegistry,
	registerBuiltinDashboardWidgets,
	registerBuiltinIntegrations,
	registerIcons,
	registerLeafIntegrations,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { createApp, h } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import appIcons from './icons.js'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import pinia from './pinia.js'
import registry from './registry.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'
// gridstack is a REQUIRED peer of @conduction/nextcloud-vue that no consumer
// declares; it used to resolve by accident from a hoisted node_modules outside
// the repo. Its stylesheet is the silent half: v12 sizes items with
// `width: var(--gs-column-width)`, so without the CSS every dashboard item
// renders 0 px wide with no console error at all.
import 'gridstack/dist/gridstack.min.css'
// Global (unscoped) app styles
import './assets/app.css'

// Seed the library's built-in dashboard-widget catalog. CnDetailPage's
// config-grid body resolves a widget `type` through getWidgetTypeEntry (this
// catalog), so nothing renders until it is populated. Webpack can otherwise
// tree-shake the bare side-effect imports that do the registering, and the
// widgets then render "Widget not available" with no error anywhere.
// Must run BEFORE any page renders.
//
// ⚠️ This REPLACES an app-local `registerDashboardWidget('audit-trail', {
// renderer: CnAuditTrailWidget, … })` bootstrap. Two reasons it had to go:
//   1. `CnAuditTrailWidget` is NOT a public export of 2.1.0-vue3.13 — the
//      component exists in the package but the barrel never re-exports it
//      (verified by enumerating all 402 explicit named exports of
//      dist/esm/index.js; `CnAuditTrailCard` and the other 18 symbols this app
//      imports all resolve, so the absence is real and not a bad lookup). The
//      import would have silently bound `undefined` and registered a widget
//      type with no renderer.
//   2. It is no longer needed. The gap it worked around — nextcloud-vue#89,
//      "the library should self-seed audit-trail into the detail-page catalog
//      too" — is closed: registerBuiltinDashboardWidgets() now imports
//      CnAuditTrailWidget/dashboardRegistration.js, which registers
//      `audit-trail` with surfaces:['detail-page'] AND its config form, which
//      the app-local copy did not have (`form: null`).
registerBuiltinDashboardWidgets()

// Integration registry (ADR-019). learniq's manifest declares THREE
// `type: "integration"` widgets — `cohort-talk` ("Class space") on
// CohortDetail, `session-talk` ("Join call") and `sess-files` ("Session
// materials") on SessionDetail — and nothing ever registered a provider for
// them to resolve against.
//
// That failure is silent by design. CnDetailPage.resolveIntegrationWidget()
// returns null when the integration is not registered, and its own docblock
// says what happens next: "the grid section simply renders nothing extra". No
// error, no placeholder, no gap in the layout — three declared widgets just
// were not there, on every page load since they were declared.
//
// Note this is NOT the "Talk is not installed" path. A registered talk leaf
// whose backing app is absent renders CnTalkCard's degraded surface, title and
// all; an UNREGISTERED one renders nothing whatsoever. talk-classroom-spaces
// e2e asserted the former and got the latter, and read as a Talk problem.
//
// Same three calls, same order, as decidiq's main.js.
installIntegrationRegistry()
registerBuiltinIntegrations()
registerLeafIntegrations()

// Register library-side icon set + lib translations once at bootstrap.
registerIcons(appIcons)
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[learniq] registerTranslations failed; falling back to English', e)
}

// Fire-and-forget translation load. Some Nextcloud installs only allow the
// JS/CSS allowlist through Apache — /custom_apps/<app>/l10n/<locale>.json
// 404s in those environments. Wrapping mount in the callback means silent
// boot failure. Strings fall back to their English source on miss.
/**
 *
 */
function tryLoadTranslations() {
	try {
		const result = loadTranslations('learniq', () => {})
		if (result && typeof result.then === 'function') {
			result.then(
				() => {},
				() => {},
			)
		}
	} catch {
		// no-op
	}
}

// Shallow-clone CnPageRenderer so the router gets an extensible component
// object — lib barrel exports are non-extensible (webpack ESM module records)
// and the router/renderer may attach internal bookkeeping to the definition.
const RoutePageRenderer = { ...CnPageRenderer }

/**
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route; the route name IS page.id (per the lib's manifest contract).
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 4 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to the dashboard, preserving prior router behaviour.
	// ⚠️ vue-router 4 REMOVED the bare `path: '*'` wildcard. It does not warn:
	// the route simply never matches, so the app shell renders and `<main>`
	// stays empty on any unknown URL. The named-param form is the v4 spelling.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}

// Populate the manifest runtime context so menu `visibleIf` predicates
// (e.g. `user.primaryRole`) and the role-aware Dashboards component resolve
// against the signed-in user's role. Provided as initial state by
// PageController; absent runtime would (by lib fail-safe) hide every
// role-gated menu item. Defaults to the least-privileged role on miss.
// The set of dashboard views the signed-in user may see (resolved server-side
// by DashboardRoleService from NC's admin group and the unprefixed
// role-backing groups; admins get all three, everyone gets 'student').
// Exposed as per-role booleans so each dashboard menu item's
// `visibleIf` can gate on a scalar `eq: true` (the predicate grammar has no
// array-contains operator).
const dashboardRoles = loadState('learniq', 'dashboardRoles', ['student']) || []
bundledManifest.runtime = {
	...(bundledManifest.runtime || {}),
	user: {
		...(bundledManifest.runtime?.user || {}),
		primaryRole: loadState('learniq', 'primaryRole', 'learner'),
		canAdminDashboard: dashboardRoles.includes('admin'),
		canTeachDashboard: dashboardRoles.includes('teacher'),
		canLearnDashboard: dashboardRoles.includes('student'),
	},
}

// Collect the app's manifest.d/*.json fragments — require.context is resolved
// by this app's own webpack build, so it stays app-local — then hand the base
// manifest, fragments, and menu-layout to the shared pipeline (ADR-037 / ADR-044).
// `require.context` is a WEBPACK build-time API, not CommonJS `require`: the
// bundler rewrites this call at compile time and no `require` exists at
// runtime. eslint's browser globals therefore report `no-undef` correctly —
// the code is right and the linter is right. Scoped to this one identifier so
// a genuinely undefined name elsewhere in the file still fails.
/* global require */
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx
	.keys()
	.sort()
	.map((key) => fragmentCtx(key))
const mergedManifest = buildManifest(bundledManifest, fragments, menuLayout)

const router = createRouter({
	history: createWebHistory(generateUrl('/apps/learniq')),
	routes: routesFromManifest(mergedManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib exports
// `defaultPageTypes` (and our `registry`) as FROZEN module objects in some
// bundle shapes, and the renderer may attach bookkeeping to what it is handed.
// Cloning yields extensible objects without altering the values the lib
// resolves at render time.
const pageTypesProp = { ...defaultPageTypes }
const registryProp = { ...registry }

const app = createApp({
	render: () =>
		h(App, {
			manifest: mergedManifest,
			registry: registryProp,
			pageTypes: pageTypesProp,
		}),
})

app.use(pinia)
app.use(router)

// Vue 2's `Vue.mixin` was global to the whole runtime; Vue 3 scopes mixins to
// one app instance, so this must be applied to `app`, not to an import.
app.mixin({ methods: { t, n } })

// ⚠️ Mount host: `#learniq-app`, NOT `#content`.
//
// Vue 2's `$mount(sel)` REPLACED the matched element; Vue 3's `mount(sel)`
// renders INSIDE it. templates/index.php used to declare its own
// `<div id="content">`, which is a DUPLICATE of the `#content` wrapper
// Nextcloud's own layout.user.php already emits. Under Vue 2 the app replaced
// core's wrapper, so the duplication never showed; under Vue 3 the app would
// render inside core's wrapper and inherit its layout. Renaming the host
// element is the fix — reasoning about which of two identically-ided divs
// `mount()` picks is not.
app.mount('#learniq-app')
