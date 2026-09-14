// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The Integrations page over integriq's connection registry
// (adopt-connection-registry, hydra connection-registry D8 and D9).
//
// The page is declared in JSON and resolves two formatters and one handler by
// NAME. A misspelled name renders a raw enum or an Add integration that does
// nothing, and neither logs a thing. So this test reads the real fragment and
// checks every name against the module that has to answer it.
//
// @spec openspec/changes/adopt-connection-registry/specs/integrations/spec.md#requirement-req-int-conn-003-an-admin-reads-learniqs-connections-on-an-integrations-page

import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { describe, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	CONNECTION_STATUS_LABELS,
	createConnectionFormatters,
	createConnectionHandlers,
	INTEGRIQ_CONNECTIONS_PATH,
} from '../../src/utils/connectionRegistry.js'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const readJson = (relative) => JSON.parse(fs.readFileSync(path.join(ROOT, relative), 'utf8'))
const fragment = readJson('src/manifest.d/connection-registry.json')
const page = fragment.pages.find((p) => p.id === 'ConnectionRegistry')
const menu = fragment.menu.find((m) => m.id === 'ConnectionRegistryMenu')

/** A translator that marks what it translated, so a missing call shows. */
const translate = (source) => `t:${source}`

describe('connection formatters', () => {
	const formatters = createConnectionFormatters(translate)

	test('labels all six statuses, limited included', () => {
		assert.deepEqual(
			Object.keys(CONNECTION_STATUS_LABELS).sort(),
			['configured', 'error', 'limited', 'simulated', 'unavailable', 'unconfigured'],
		)
		assert.equal(formatters.connectionStatus('limited'), 't:Limited')
		assert.equal(formatters.connectionStatus('unconfigured'), 't:Not configured')
		assert.equal(formatters.connectionStatus('unavailable'), 't:Not available')
		assert.equal(formatters.connectionStatus('simulated'), 't:Simulated')
		assert.equal(formatters.connectionStatus('configured'), 't:Configured')
		assert.equal(formatters.connectionStatus('error'), 't:Error')
	})

	test('renders an unknown status as itself and a missing one as empty', () => {
		assert.equal(formatters.connectionStatus('degraded'), 'degraded')
		assert.equal(formatters.connectionStatus('toString'), 'toString')
		assert.equal(formatters.connectionStatus(null), '')
		assert.equal(formatters.connectionStatus(undefined), '')
	})

	test('offers Open settings only when the row has a settings link', () => {
		assert.equal(formatters.connectionSettingsLabel('/settings/admin/learniq#section-data-exchange'), 't:Open settings')
		assert.equal(formatters.connectionSettingsLabel(''), '')
		assert.equal(formatters.connectionSettingsLabel(undefined), '')
	})

	test('ships an English and a Dutch label for every string the page shows', () => {
		const en = readJson('l10n/en.json').translations
		const nl = readJson('l10n/nl.json').translations
		const labels = [
			...Object.values(CONNECTION_STATUS_LABELS),
			'Open settings',
			page.title,
			page.config.headerActions[0].label,
			page.config.folderSidebar.allLabel,
			...page.config.columns.map((c) => c.label),
		]
		for (const label of labels) {
			assert.equal(en[label], label, `en: ${label}`)
			assert.ok(nl[label], `nl: ${label}`)
		}
		assert.equal(nl.Limited, 'Beperkt')
	})
})

describe('Add integration handler', () => {
	test('opens integriq on the link dialog, preset to learniq', () => {
		const opened = []
		const handlers = createConnectionHandlers({
			generateUrl: (p) => `/index.php${p}`,
			assign: (url) => opened.push(url),
		})

		handlers.openIntegriqConnections()

		assert.equal(INTEGRIQ_CONNECTIONS_PATH, '/apps/integriq/connections?app=learniq&link=1')
		assert.deepEqual(opened, ['/index.php/apps/integriq/connections?app=learniq&link=1'])
	})
})

describe('the Integrations page declaration', () => {
	test('lists integriq app_connection rows and requires integriq', () => {
		assert.equal(page.type, 'index')
		assert.equal(page.route, '/settings/integrations')
		assert.equal(page.requiresApp.id, 'integriq')
		assert.equal(page.config.register, 'integriq')
		assert.equal(page.config.schema, 'app_connection')
		assert.equal(page.config.showAdd, false)
	})

	test('scopes the rows to learniq through the menu preset, admins only, in the gear', () => {
		assert.equal(menu.route, page.id)
		assert.deepEqual(menu.query, { app: 'learniq' })
		assert.equal(menu.section, 'settings')
		assert.deepEqual(menu.visibleIf, { appInstalled: 'integriq', 'user.primaryRole': { in: ['admin'] } })
	})

	test('names only formatters, handlers and icons that exist', () => {
		const formatters = createConnectionFormatters(translate)
		const handlers = createConnectionHandlers({ generateUrl: (p) => p, assign: () => {} })
		const icons = fs.readFileSync(path.join(ROOT, 'src/icons.js'), 'utf8')

		for (const column of page.config.columns.filter((c) => c.formatter)) {
			assert.equal(typeof formatters[column.formatter], 'function', column.formatter)
		}
		for (const action of page.config.headerActions) {
			assert.equal(typeof handlers[action.handler], 'function', action.handler)
		}
		for (const icon of new Set([menu.icon, ...page.config.headerActions.map((a) => a.icon)])) {
			assert.match(icons, new RegExp(`^\\s+${icon},$`, 'm'), `icon ${icon} is not registered`)
		}
	})

	test('wires the formatters and the handler into the app shell', () => {
		const app = fs.readFileSync(path.join(ROOT, 'src/App.vue'), 'utf8')
		assert.match(app, /:formatters="connectionFormatters"/)
		assert.match(app, /:customComponents="headerActionHandlers"/)
	})

	test('takes an id and a route no other page uses', () => {
		const pages = [readJson('src/manifest.json'), ...fs.readdirSync(path.join(ROOT, 'src/manifest.d'))
			.filter((name) => name.endsWith('.json') && name !== 'connection-registry.json')
			.map((name) => readJson(`src/manifest.d/${name}`))]
			.flatMap((doc) => doc.pages ?? [])
		assert.ok(!pages.some((p) => p.id === page.id), 'page id collides')
		assert.ok(!pages.some((p) => p.route === page.route), 'page route collides')
	})
})
