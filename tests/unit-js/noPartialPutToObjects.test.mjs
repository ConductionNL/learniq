// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// OpenRegister's PUT on an object REPLACES it and validates the result, so a
// PUT carrying only the fields a view changed is refused with 400 ("the
// required properties ... are missing"). Found live on 2026-09-29: every
// double-marking hand-in and every "Save & return to learner" failed that way,
// and five more views sent the same kind of partial PUT. A partial update is
// a PATCH.
//
// This guard reads every `fetch(..., { method: 'PUT' ... })` in src/ whose URL
// is an OpenRegister objects path, and fails on any. It does not see
// `axios.put(...)`: those calls usually spread the loaded object first (a
// full replacement, which is valid), and telling a full body from a partial
// one statically is not reliable.
//
// @spec exclude Regression guard for a bug class, not a spec requirement.

import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')

/**
 * Every .vue and .js file under a directory.
 *
 * @param {string} dir Absolute directory.
 * @return {string[]} Absolute file paths.
 */
function sourceFiles(dir) {
	const out = []
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			out.push(...sourceFiles(full))
		} else if (/\.(vue|js)$/.test(entry.name)) {
			out.push(full)
		}
	}
	return out
}

test('no view sends a PUT to an OpenRegister object through fetch', () => {
	const offenders = []
	for (const file of sourceFiles(path.join(ROOT, 'src'))) {
		const lines = fs.readFileSync(file, 'utf8').split('\n')
		lines.forEach((line, i) => {
			if (!/method:\s*'PUT'/.test(line)) {
				return
			}
			// The URL is built a few lines above the options object.
			const context = lines.slice(Math.max(0, i - 12), i).join('\n')
			if (context.includes('/apps/openregister/api/objects/')) {
				offenders.push(`${path.relative(ROOT, file)}:${i + 1}`)
			}
		})
	}
	assert.deepEqual(offenders, [], 'partial updates must use PATCH')
})

test('the guard sees the shape it guards', () => {
	const sample = [
		"const url = generateUrl('/apps/openregister/api/objects/learniq/x/1')",
		'await fetch(url, {',
		"\tmethod: 'PUT',",
	]
	const i = 2
	assert.ok(/method:\s*'PUT'/.test(sample[i]))
	assert.ok(sample.slice(0, i).join('\n').includes('/apps/openregister/api/objects/'))
})
