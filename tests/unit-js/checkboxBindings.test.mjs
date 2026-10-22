// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Checkbox bindings match @nextcloud/vue 9.
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).
//
// NcCheckboxRadioSwitch in @nextcloud/vue 9 emits only `update:modelValue`.
// A listener on `update:checked` never fires, so the box snaps back to its
// bound value and the change is never stored. The admin action matrix lost
// every tick this way. Nc* components come from @nextcloud/vue directly, not
// through the @conduction/nextcloud-vue barrel.

import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const SRC = join(ROOT, 'src')

/**
 * @param {string} dir Directory to walk.
 * @return {string[]} Every .vue and .js file below it.
 */
function sourceFiles(dir) {
	return readdirSync(dir).flatMap((name) => {
		const path = join(dir, name)
		if (statSync(path).isDirectory()) {
			return sourceFiles(path)
		}
		return /\.(vue|js)$/.test(name) ? [path] : []
	})
}

const files = sourceFiles(SRC).map((path) => ({
	path: relative(ROOT, path),
	text: readFileSync(path, 'utf8'),
}))

test('no checkbox listens to update:checked, which @nextcloud/vue 9 never emits', () => {
	const offenders = files
		.filter(({ text }) => text.includes('@update:checked'))
		.map(({ path }) => path)
	assert.deepEqual(offenders, [])
})

test('Nc components are imported from @nextcloud/vue, not the @conduction/nextcloud-vue barrel', () => {
	const offenders = []
	for (const { path, text } of files) {
		for (const match of text.matchAll(/import\s*\{([^}]*)\}\s*from\s*['"]@conduction\/nextcloud-vue['"]/g)) {
			const names = match[1]
				.split(',')
				.map((name) => name.trim().split(/\s+as\s+/)[0])
				.filter((name) => /^Nc[A-Z]/.test(name))
			for (const name of names) {
				offenders.push(`${path}: ${name}`)
			}
		}
	}
	assert.deepEqual(offenders, [])
})

test('the action matrix is the library one, with no learniq copy left', () => {
	assert.equal(files.find(({ path }) => path.endsWith('components/admin/ActionAuthMatrix.vue')), undefined)
	const adminRoot = files.find(({ path }) => path.endsWith('views/settings/AdminRoot.vue'))
	assert.ok(adminRoot, 'AdminRoot.vue exists')
	assert.match(adminRoot.text, /CnActionAuthMatrix/)
})
