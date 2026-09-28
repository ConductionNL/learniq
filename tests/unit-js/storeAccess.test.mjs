// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the store access boot helper and the manifest keys it relies
// on (store-rights-for-teachers, D27). Run via `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	applyStoreAccess,
	normaliseStoreAccess,
} from '../../src/utils/storeAccess.js'
import { storeRegistryUrl } from '../../src/utils/storeRegistrySettings.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const readJson = (path) => JSON.parse(readFileSync(resolve(root, path), 'utf8'))

test('a teacher gets Install and no Publish on every store page, and nothing else changes', () => {
	const manifest = {
		pages: [
			{
				id: 'Store',
				type: 'store',
				config: { app: 'learniq', publishRoute: 'CoursePackageExport' },
			},
			{ id: 'Reports', type: 'reports', config: { cards: [] } },
		],
	}

	applyStoreAccess(manifest, { install: true, publish: false })

	assert.deepEqual(manifest.pages[0].config, {
		app: 'learniq',
		publishRoute: 'CoursePackageExport',
		canInstall: true,
		canPublish: false,
	})
	assert.deepEqual(manifest.pages[1].config, { cards: [] })
})

test('missing or malformed state shows no store action', () => {
	assert.deepEqual(normaliseStoreAccess(null), { install: false, publish: false })
	assert.deepEqual(normaliseStoreAccess({ install: 'true', publish: 1 }), {
		install: false,
		publish: false,
	})

	const manifest = { pages: [{ id: 'Store', type: 'store' }] }
	applyStoreAccess(manifest, undefined)
	assert.deepEqual(manifest.pages[0].config, {
		canInstall: false,
		canPublish: false,
	})
})

test('an empty config that round-tripped through PHP as [] still gets the flags', () => {
	const manifest = { pages: [{ id: 'Store', type: 'store', config: [] }] }
	applyStoreAccess(manifest, { install: true, publish: true })
	assert.deepEqual(manifest.pages[0].config, {
		canInstall: true,
		canPublish: true,
	})
})

test('the Store page sends Publish to the export screen, which exists', () => {
	const manifest = readJson('src/manifest.json')
	const store = manifest.pages.find((p) => p.id === 'Store')
	assert.equal(store.type, 'store')
	assert.equal(store.config.publishRoute, 'CoursePackageExport')

	const learning = readJson('src/manifest.d/learning.json')
	assert.ok(
		learning.pages.some((p) => p.id === 'CoursePackageExport'),
		'publishRoute must name an existing page id (the route name)',
	)
})

test('team leads reach the export screen where Publish lives', () => {
	const learning = readJson('src/manifest.d/learning.json')
	const findEntry = (items) => {
		for (const item of items ?? []) {
			if (item.id === 'CoursePackageExportMenu') {
				return item
			}
			const nested = findEntry(item.children)
			if (nested) {
				return nested
			}
		}
		return null
	}
	const entry = findEntry(learning.menu)
	assert.ok(entry, 'the export menu entry exists')
	assert.ok(entry.visibleIf['user.primaryRole'].in.includes('team-lead'))
	assert.ok(entry.visibleIf['user.primaryRole'].in.includes('instructor'))
})

test('the registry settings section calls the routed admin endpoint', () => {
	const routes = readFileSync(resolve(root, 'appinfo/routes.php'), 'utf8')
	const path = storeRegistryUrl().replace('/apps/learniq', '')
	for (const verb of ['GET', 'PUT']) {
		assert.match(
			routes,
			new RegExp(`'url' => '${path}', 'verb' => '${verb}'`),
			`${verb} ${path} is routed`,
		)
	}
})
