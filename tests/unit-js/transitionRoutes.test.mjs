// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Open Register routes a lifecycle transition at
// POST /apps/openregister/api/objects/{id}/transition with the name in the
// body as `{ action }` (openregister appinfo/routes.php, transition#transition).
// A URL of the shape `/objects/learniq/<schema>/<id>/transition/<name>` is
// not a route, so every screen that used it failed with a 404 while the
// object stayed in its old state. This test fails on any such URL in src/.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

/**
 * Every .vue and .js file under a directory.
 *
 * @param {string} dir Directory.
 * @return {string[]} Absolute paths.
 */
function sources(dir) {
	return readdirSync(dir).flatMap((name) => {
		const path = join(dir, name)
		if (statSync(path).isDirectory()) return sources(path)
		return /\.(vue|js)$/.test(name) ? [path] : []
	})
}

/**
 * Code lines (not comments) of a file.
 *
 * @param {string} path File.
 * @return {Array<{line: number, text: string}>} Numbered lines.
 */
function codeLines(path) {
	return readFileSync(path, 'utf8')
		.split('\n')
		.map((text, i) => ({ line: i + 1, text }))
		.filter(({ text }) => !/^\s*(\*|\/\/|\/\*|-\s)/.test(text))
}

test('no screen posts a transition to a /transition/<name> URL', () => {
	const dead = []
	for (const file of sources(resolve(root, 'src'))) {
		for (const { line, text } of codeLines(file)) {
			if (/\/transition\/(\$\{|[A-Za-z])/.test(text)) {
				dead.push(`${relative(root, file)}:${line}`)
			}
		}
	}
	assert.deepEqual(dead, [], 'transition URLs Open Register does not route')
})

test('every transition endpoint call names the action in the body', () => {
	const missing = []
	for (const file of sources(resolve(root, 'src/views'))) {
		const text = readFileSync(file, 'utf8')
		if (!/[Tt]ransitionUrl\(/.test(text)) continue
		if (!/action[:,} ]/.test(text)) {
			missing.push(relative(root, file))
		}
	}
	assert.deepEqual(missing, [])
})

test('the exam-board transitions accept the fields the dossier view sends', () => {
	const register = JSON.parse(
		readFileSync(resolve(root, 'lib/Settings/learniq_register.json'), 'utf8'),
	)
	const inputs = (schema, action) =>
		(
			register.components.schemas[schema]['x-openregister-lifecycle']
				.transitions[action].inputs ?? []
		)
			.map((input) => input.field)
			.sort()

	assert.deepEqual(inputs('ExemptionCase', 'grant'), [
		'decisionRationale',
		'policyReference',
	])
	assert.deepEqual(inputs('ExemptionCase', 'reject'), [
		'decisionRationale',
		'policyReference',
	])
	assert.deepEqual(inputs('FraudCase', 'scheduleHearing'), ['hearingDate'])
	assert.deepEqual(inputs('FraudCase', 'decide'), [
		'decisionRationale',
		'sanctionDurationMonths',
		'sanctionScope',
		'sanctionType',
		'verdict',
	])
})
