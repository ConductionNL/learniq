// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The admin settings "Default register" picker showed empty after a reload:
// LearniqSettings.vue started it at null, never read the saved value back,
// and wrote `default_register`, a key SettingsService does not persist (its
// CONFIG_KEYS name `register`), so the save was dropped too. These tests pin
// the round trip: the key, the saved value, and finding it again.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	DEFAULT_REGISTER_KEY,
	registerValue,
	selectedRegister,
} from '../../src/utils/defaultRegister.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const read = (relative) => readFileSync(resolve(root, relative), 'utf8')

const registers = [
	{
		id: 7,
		uuid: 'b7c1e2d4-0000-4000-8000-000000000007',
		slug: 'learniq',
		title: 'Learniq',
	},
	{
		id: 9,
		uuid: 'b7c1e2d4-0000-4000-8000-000000000009',
		slug: 'other',
		title: 'Other',
	},
]

test('the picker uses the key SettingsService persists', () => {
	const configKeys = read('lib/Service/SettingsService.php').match(
		/CONFIG_KEYS = \[([\s\S]*?)\];/,
	)[1]
	assert.match(configKeys, new RegExp(`'${DEFAULT_REGISTER_KEY}'`))
	const view = read('src/views/LearniqSettings.vue')
	assert.doesNotMatch(view, /default_register:/, 'the dropped key is gone')
	assert.match(view, /\[DEFAULT_REGISTER_KEY\]: value/)
	assert.match(view, /savedRegister = data\[DEFAULT_REGISTER_KEY\]/)
})

test('a picked register is saved as its slug, else its id', () => {
	assert.equal(registerValue(registers[0]), 'learniq')
	assert.equal(registerValue({ id: 12, title: 'No slug' }), '12')
	assert.equal(registerValue('learniq'), 'learniq')
	assert.equal(registerValue(null), '')
})

test('the saved value finds its register again by slug, id or uuid', () => {
	assert.equal(selectedRegister(registers, 'learniq'), registers[0])
	assert.equal(selectedRegister(registers, '9'), registers[1])
	assert.equal(selectedRegister(registers, registers[1].uuid), registers[1])
})

test('nothing saved or an unknown value leaves the picker empty', () => {
	assert.equal(selectedRegister(registers, ''), null)
	assert.equal(selectedRegister(registers, undefined), null)
	assert.equal(selectedRegister(registers, 'gone'), null)
	assert.equal(selectedRegister(null, 'learniq'), null)
})

test('a round trip returns the register that was picked', () => {
	for (const register of registers) {
		assert.equal(selectedRegister(registers, registerValue(register)), register)
	}
})
