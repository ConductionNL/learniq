// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A component the library does not export is only a webpack warning: the import
// is undefined, Vue renders an unknown element of no height and nothing fails.
//
// @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states

import assert from 'node:assert/strict'
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const LIBRARY = join(ROOT, 'node_modules/@conduction/nextcloud-vue/src')

/**
 * The Cn names the source imports from the library's root, by file.
 *
 * @return {Array<{file: string, name: string}>} One entry per imported name.
 */
function importedNames() {
	const files = readdirSync(join(ROOT, 'src'), { recursive: true }).map(String).filter((file) => /\.(vue|js)$/.test(file))
	return files.flatMap((file) => {
		const source = readFileSync(join(ROOT, 'src', file), 'utf8')
		const imports = [...source.matchAll(/import\s*\{([^}]*)\}\s*from\s*'@conduction\/nextcloud-vue'/g)]
		return imports.flatMap((match) => (match[1].match(/\bCn\w+/g) || []).map((name) => ({ file, name })))
	})
}

test('the panel states draw a component the library exports', () => {
	const source = readFileSync(join(ROOT, 'src/components/openMaterial/OpenMaterialState.vue'), 'utf8')
	assert.match(source, /import \{ CnWidgetEmptyState \} from '@conduction\/nextcloud-vue'/)
	assert.doesNotMatch(source, /CnEmptyContent/)
})

test('every Cn component imported from the library root is exported by it', { skip: !existsSync(LIBRARY) && 'needs npm ci' }, () => {
	const exported = readFileSync(join(LIBRARY, 'index.js'), 'utf8') + readFileSync(join(LIBRARY, 'components/index.js'), 'utf8')
	const missing = importedNames().filter(({ name }) => !new RegExp(`\\b${name}\\b`).test(exported))
	assert.deepEqual(missing, [])
})
