// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The user settings dialog shows the library's notification preferences
// (CnAppRoot's default sections, scoped to learniq), not a learniq copy with
// a quiet-hours control that saved nothing.
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { test } from 'node:test'

const root = new URL('../../', import.meta.url)

test('App.vue leaves the user settings dialog to CnAppRoot\'s defaults', () => {
	const app = readFileSync(new URL('src/App.vue', root), 'utf8')
	assert.doesNotMatch(app, /<template #user-settings>/)
	assert.match(app, /appId="learniq"/, 'the default preferences scope to the app id')
	assert.equal(existsSync(new URL('src/views/LearniqNotificationSettings.vue', root)), false)
})
