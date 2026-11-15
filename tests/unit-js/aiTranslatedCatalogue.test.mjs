// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// ai-translated-catalogue-review (decision D24): l10n/ai-translated.json lists
// the catalogue keys whose Dutch value an AI build lane wrote and no human has
// reviewed. These tests guard its shape, so a renamed key, a duplicate, an
// unsorted insert or a stray member fails here instead of hiding a string
// from the review page.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

/**
 * Read a JSON file under the repository root.
 *
 * @param {string} relative - path under the root
 * @return {*} the parsed value
 */
function readJson(relative) {
	return JSON.parse(readFileSync(resolve(root, relative), 'utf8'))
}

const sidecar = readJson('l10n/ai-translated.json')

/**
 * Compare two strings by Unicode code point, the order the file is kept in.
 *
 * @param {string} a - left
 * @param {string} b - right
 * @return {number} negative, zero or positive
 */
function byCodePoint(a, b) {
	const left = Array.from(a)
	const right = Array.from(b)
	for (let i = 0; i < Math.min(left.length, right.length); i++) {
		const diff = left[i].codePointAt(0) - right[i].codePointAt(0)
		if (diff !== 0) {
			return diff
		}
	}
	return left.length - right.length
}

test('the sidecar is an object with only $comment, language and keys', () => {
	assert.equal(typeof sidecar, 'object')
	assert.ok(sidecar !== null && !Array.isArray(sidecar))
	assert.deepEqual(
		Object.keys(sidecar).filter(
			(member) => !['$comment', 'language', 'keys'].includes(member),
		),
		[],
		'no other top-level member',
	)
	if ('$comment' in sidecar) {
		assert.equal(typeof sidecar.$comment, 'string')
	}
})

test('the language names a catalogue that exists', () => {
	assert.equal(sidecar.language, 'nl')
	assert.ok(existsSync(resolve(root, `l10n/${sidecar.language}.json`)))
})

test('keys is a non-empty array of non-empty strings, unique and sorted by code point', () => {
	assert.ok(Array.isArray(sidecar.keys))
	assert.ok(
		sidecar.keys.length > 0,
		'an empty list means the file should go, not stay',
	)
	for (const key of sidecar.keys) {
		assert.equal(typeof key, 'string')
		assert.notEqual(key, '')
	}
	assert.equal(new Set(sidecar.keys).size, sidecar.keys.length, 'no duplicates')
	const sorted = [...sidecar.keys].sort(byCodePoint)
	const firstOut = sidecar.keys.findIndex((key, i) => key !== sorted[i])
	assert.equal(
		firstOut,
		-1,
		`sorted by code point; first out of place: ${JSON.stringify(sidecar.keys[firstOut])}`,
	)
})

test('every listed key exists in the catalogue of that language', () => {
	const catalogue = readJson(`l10n/${sidecar.language}.json`).translations
	const missing = sidecar.keys.filter((key) => !(key in catalogue))
	assert.deepEqual(
		missing,
		[],
		'a renamed or removed key must leave the list in the same pull request',
	)
})

test('the round 1 and round 2 seed is in, and a pre-round value is not', () => {
	assert.ok(
		sidecar.keys.includes('Everyone has handed in.'),
		'added on 2026-09-27 by dcde983c',
	)
	assert.ok(sidecar.keys.includes('Publish marks'))
	assert.ok(
		!sidecar.keys.includes('Status message'),
		'its Dutch value predates 2026-09-25',
	)
})

test('the l10n build does not take the sidecar for a locale', () => {
	const result = spawnSync(
		process.execPath,
		['scripts/build-l10n-js.js', '--check'],
		{ cwd: root, encoding: 'utf8' },
	)
	assert.equal(result.status, 0, result.stderr)
	assert.ok(!existsSync(resolve(root, 'l10n/ai-translated.js')))
})
