// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Learniq's settings dialog shows the quiet-hours block (board LqMeldingen).
// The block is nextcloud-vue's CnQuietHours, drawn by CnNotificationPreferences
// when CnAppRoot's `quietHours` prop is on; it reads and saves OpenRegister's
// per-user delivery window. Learniq keeps no quiet hours of its own: nothing
// in src/ writes `quietHours` to the notification-preferences endpoint or to
// browser storage.
//
// @spec openspec/changes/notification-quiet-hours-through-openregister/specs/scholiq-notifications/spec.md#requirement-quiet-hours-are-read-and-saved-through-openregisters-delivery-window
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const SRC = fileURLToPath(new URL('../../src/', import.meta.url))

/**
 * Every .vue and .js file under a directory.
 *
 * @param {string} dir The directory.
 * @return {Array<string>} The paths.
 */
function sources(dir) {
	return readdirSync(dir).flatMap((name) => {
		const path = join(dir, name)
		if (statSync(path).isDirectory()) {
			return sources(path)
		}
		return /\.(vue|js)$/.test(name) ? [path] : []
	})
}

test('the app shell turns the quiet-hours block on', () => {
	const app = readFileSync(join(SRC, 'App.vue'), 'utf8')
	const root = app.slice(app.indexOf('<CnAppRoot'), app.indexOf('</CnAppRoot>'))
	assert.match(root, /:quietHours="true"/)
})

test('learniq keeps no quiet hours of its own', () => {
	for (const path of sources(SRC)) {
		const text = readFileSync(path, 'utf8')
		assert.doesNotMatch(text, /quietHours\s*:/, `${path} writes quietHours itself`)
		assert.doesNotMatch(text, /localStorage[^\n]*quiet/i, `${path} keeps quiet hours in the browser`)
	}
})
