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
// The first guard reads every `fetch(..., { method: 'PUT' ... })` in src/
// whose URL is an OpenRegister objects path, and fails on any.
//
// The second guard (r5 put sweep, same day) reads every `axios.put(` and every
// fetch whose `method` expression names 'PUT' (including `isEdit ? 'PUT' :
// 'POST'`), and fails on any that targets an objects path, unless the site is
// on FULL_REPLACE below. Telling a full body from a partial one statically is
// not reliable, so each allowed site was read by hand and carries the reason
// it is a whole-object replace. A new PUT to an object fails this test until
// someone reads its body and either makes it a PATCH or adds it here.
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
	assert.ok(
		sample.slice(0, i).join('\n').includes('/apps/openregister/api/objects/'),
	)
})

/**
 * Reviewed PUTs to an OpenRegister object that send the whole object, per
 * file, with the number of such PUTs in that file. Any other PUT to an
 * objects path is a partial update and must be a PATCH.
 */
const FULL_REPLACE = {
	// saveCard(): the card as loaded from the objects list, edited in place.
	'src/views/RapportvergaderingReviewView.vue': 1,
	// save(): spreads the loaded hour plan.
	'src/views/HourPlanEditor.vue': 1,
	// save(): spreads the loaded learning plan.
	'src/views/LearningPlanEditorView.vue': 1,
	// submit(): spreads the submission it just created.
	'src/views/SubmitWorkView.vue': 1,
	// saveAndSubmit(): carries every schema property but lifecycle, which the
	// submit transition right after it sets.
	'src/views/SelfAssessmentView.vue': 1,
	// save(): attendanceRecord() carries every required field, and a replace
	// is what clears a reason the teacher removed.
	'src/views/AttendanceRegisterView.vue': 1,
}

const OBJECTS_PATH = /\/apps\/openregister\/api\/objects\/|objectsUrl\(|\bOBJECTS\b/

/**
 * Every PUT to an OpenRegister objects path in one file's source.
 *
 * @param {string} source File contents.
 * @return {number[]} 1-based line numbers of the PUT calls.
 */
export function objectPuts(source) {
	const lines = source.split('\n')
	const hits = []
	lines.forEach((line, i) => {
		const isPut = /axios\.put\(/.test(line) || /method:[^,\n]*'PUT'/.test(line)
		if (!isPut) {
			return
		}
		// The URL is built above the call (fetch, or a `url` const) or in
		// its first argument, on the next lines.
		const context = lines.slice(Math.max(0, i - 12), i + 4).join('\n')
		if (OBJECTS_PATH.test(context)) {
			hits.push(i + 1)
		}
	})
	return hits
}

test('every PUT to an OpenRegister object is a reviewed full replace', () => {
	const offenders = []
	for (const file of sourceFiles(path.join(ROOT, 'src'))) {
		const rel = path.relative(ROOT, file)
		const hits = objectPuts(fs.readFileSync(file, 'utf8'))
		const allowed = FULL_REPLACE[rel] ?? 0
		if (hits.length !== allowed) {
			offenders.push(
				`${rel}: ${hits.length} PUT(s) to an object at line(s) ${hits.join(', ') || '-'}, ${allowed} reviewed`,
			)
		}
	}
	for (const rel of Object.keys(FULL_REPLACE)) {
		if (!fs.existsSync(path.join(ROOT, rel))) {
			offenders.push(`${rel}: listed in FULL_REPLACE but gone`)
		}
	}
	assert.deepEqual(offenders, [], 'partial updates must use PATCH')
})

test('the second guard sees axios.put, a ternary method and a PATCH', () => {
	const axiosPut = [
		"const url = generateUrl('/apps/openregister/api/objects/learniq/x/{id}', { id })",
		"await axios.put(url, { lifecycle: 'scheduled' })",
	].join('\n')
	assert.deepEqual(objectPuts(axiosPut), [2])

	const helperPut =
		"await axios.put(generateUrl(objectsUrl('grade-entry', id)), body)"
	assert.deepEqual(objectPuts(helperPut), [1])

	const ternary = [
		'const url = isEdit',
		'\t? generateUrl(`/apps/openregister/api/objects/learniq/Item/${id}`)',
		"\t: generateUrl('/apps/openregister/api/objects/learniq/Item')",
		'await fetch(url, {',
		"\tmethod: isEdit ? 'PUT' : 'POST',",
	].join('\n')
	assert.deepEqual(objectPuts(ternary), [5])

	const patch =
		"await axios.patch(url, { lifecycle: 'scheduled' }) // '/apps/openregister/api/objects/'"
	assert.deepEqual(objectPuts(patch), [])

	const otherApi =
		"await axios.put(generateUrl('/apps/learniq/api/preferences/x'), body)"
	assert.deepEqual(objectPuts(otherApi), [])
})
