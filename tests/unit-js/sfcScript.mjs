// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Load the <script> block of a .vue file as a plain object, so a test can call
// its methods with a hand-made `this`. Plain `node --test` cannot load a .vue
// file: every import is replaced by the stub the test passes for its module
// specifier (a relative `.js` module the test does not stub is imported for
// real), and `export default` becomes the returned component options.

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const IMPORT = /^import\s+([\s\S]*?)\s+from\s+'([^']+)'\s*;?$/gm

/**
 * The binding statements one import clause becomes.
 *
 * @param {string} clause what sits between `import` and `from`
 * @param {string} key the stub expression of the module
 * @return {string}
 */
function bindings(clause, key) {
	const named = clause.match(/\{([\s\S]*)\}/)
	const lines = []
	const head = clause.replace(/\{[\s\S]*\}/, '').replace(/,\s*$/, '').trim()
	if (head !== '') {
		lines.push(`const ${head} = (${key} ?? {}).default`)
	}
	if (named) {
		const parts = named[1].split(',').map((part) => part.trim()).filter(Boolean)
		const pairs = parts.map((part) => part.replace(/\s+as\s+/, ': '))
		lines.push(`const { ${pairs.join(', ')} } = ${key} ?? {}`)
	}
	return lines.join('\n')
}

/**
 * The component options of `relative` (a path from the app root).
 *
 * @param {string} relative e.g. 'src/views/Foo.vue'
 * @param {object} stubs module specifier → module namespace stand-in
 * @return {Promise<object>}
 */
export async function loadSfcScript(relative, stubs = {}) {
	const file = resolve(ROOT, relative)
	const source = readFileSync(file, 'utf8')
	const script = source.slice(source.indexOf('<script>') + 8, source.indexOf('</script>'))
	const modules = { ...stubs }
	for (const [, , specifier] of script.matchAll(IMPORT)) {
		if (!(specifier in modules) && specifier.startsWith('.') && specifier.endsWith('.js')) {
			modules[specifier] = await import(pathToFileURL(resolve(dirname(file), specifier)).href)
		}
	}
	const body = script
		.replace(IMPORT, (_, clause, specifier) => bindings(clause, `__modules[${JSON.stringify(specifier)}]`))
		.replace(/^export default\s+/m, '__exports.default = ')
	const exportsBox = {}
	new Function('__modules', '__exports', body)(modules, exportsBox)
	return exportsBox.default
}
