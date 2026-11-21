// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The component registry (src/registry.js) and icon map (src/icons.js) that
// main.js hands to CnAppRoot, loaded for real: every name resolves to the
// component of that same name, so a manifest that names a page, dialog or
// icon gets exactly that one, and every registry entry has the shape
// CnPageRenderer dispatches on.
//
// @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { register } from 'node:module'
import { test } from 'node:test'

// A .vue import resolves to a stub naming the file it came from; nothing
// else is replaced.
const hooks = `
export async function resolve(specifier, context, next) {
	if (specifier.endsWith('.vue')) {
		const stub = 'export default { file: ' + JSON.stringify(specifier) + ' }'
		return { url: 'data:text/javascript,' + encodeURIComponent(stub), shortCircuit: true }
	}
	return next(specifier, context)
}
`
register('data:text/javascript,' + encodeURIComponent(hooks))

const registry = (await import('../../src/registry.js')).default
const icons = (await import('../../src/icons.js')).default

/**
 * The number of import statements in a source file.
 *
 * @param {string} file The file under src/.
 * @return {number} The count.
 */
function importCount(file) {
	const source = readFileSync(new URL(`../../src/${file}`, import.meta.url), 'utf8')
	return (source.match(/^import\s/gm) || []).length
}

/**
 * The component name a stub came from: its file name without `.vue`.
 *
 * @param {object} component The stub.
 * @return {string|undefined} The name.
 */
function componentName(component) {
	return component?.file?.split('/').pop().replace(/\.vue$/, '')
}

test('every registry name resolves to the component of that same name', () => {
	const mismatched = Object.entries(registry)
		.filter(([name, entry]) => componentName(entry.component) !== name)
		.map(([name, entry]) => `${name} -> ${entry.component?.file}`)
	assert.deepEqual(mismatched, [])
})

test('every imported component is registered exactly once', () => {
	const names = Object.keys(registry)
	assert.equal(names.length, importCount('registry.js'))
	const files = Object.values(registry).map((entry) => entry.component.file)
	assert.equal(new Set(files).size, files.length)
})

test('every entry carries a kind CnAppRoot dispatches on', () => {
	const kinds = new Set(Object.values(registry).map((entry) => entry.kind))
	assert.deepEqual([...kinds].sort(), ['modal', 'page', 'section'])
})

test('a page entry is exactly the page() wrapper shape', () => {
	const pages = Object.entries(registry).filter(([, entry]) => entry.kind === 'page')
	assert.ok(pages.length > 0)
	for (const [name, entry] of pages) {
		assert.deepEqual(Object.keys(entry).sort(), ['component', 'kind'], name)
	}
	assert.equal(registry.RollCallView.component.file, './views/RollCallView.vue')
})

test('a modal that declares its props declares an object schema', () => {
	const modals = Object.entries(registry).filter(([, entry]) => entry.kind === 'modal')
	assert.ok(modals.length > 0)
	for (const [name, entry] of modals) {
		if (entry.propsSchema === undefined) continue
		assert.equal(entry.propsSchema.type, 'object', name)
		assert.equal(typeof entry.propsSchema.properties, 'object', name)
		for (const key of entry.propsSchema.required ?? []) {
			assert.ok(key in entry.propsSchema.properties, `${name}.${key}`)
		}
	}
	assert.deepEqual(registry.SelfCheckInDialog.propsSchema.required, ['sessionId'])
})

test('every icon name resolves to the Material Design icon of that same name', () => {
	const mismatched = Object.entries(icons)
		.filter(([name, icon]) => icon.file !== `vue-material-design-icons/${name}.vue`)
		.map(([name, icon]) => `${name} -> ${icon.file}`)
	assert.deepEqual(mismatched, [])
})

test('every imported icon is in the map exactly once', () => {
	assert.equal(Object.keys(icons).length, importCount('icons.js'))
	const files = Object.values(icons).map((icon) => icon.file)
	assert.equal(new Set(files).size, files.length)
})
