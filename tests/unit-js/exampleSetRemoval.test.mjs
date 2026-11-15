// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The admin page's Example data section (wizard-drops-the-removal-step): the
// setup wizard no longer removes example data, so the admin page asks and
// then posts the existing remove-example-set-<id> action.
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	EXAMPLE_SETS_URL,
	loadedSetsOf,
	removalQuestion,
	removeActionUrl,
	removeExampleSet,
} from '../../src/utils/exampleSetRemoval.js'

const t = (text, vars = {}) => text.replace(/{(\w+)}/g, (_, k) => vars[k] ?? '')
const company = { id: 'corporate', label: 'Company' }

test('the section reads the admin-only list and keeps usable sets only', () => {
	assert.equal(EXAMPLE_SETS_URL, '/apps/learniq/api/setup/example-sets')
	assert.deepEqual(
		loadedSetsOf({
			sets: [company, { id: 'Bad Id', label: 'x' }, { id: 'po' }, null],
		}),
		[company],
	)
	assert.deepEqual(loadedSetsOf(null), [])
})

test('a removal posts the per-set setup action, not the occ hard delete', async () => {
	const posted = []
	const outcome = await removeExampleSet(company, {
		confirm: async () => true,
		post: async (url) => {
			posted.push(url)
			return {
				data: {
					success: true,
					message: 'Moved 12 example object(s) to the trash.',
				},
			}
		},
		t,
	})
	assert.deepEqual(posted, [
		'/apps/learniq/api/setup/action/remove-example-set-corporate',
	])
	assert.equal(
		removeActionUrl('po'),
		'/apps/learniq/api/setup/action/remove-example-set-po',
	)
	assert.deepEqual(outcome, {
		status: 'removed',
		message: 'Moved 12 example object(s) to the trash.',
	})
})

test('the question names how many objects go to the trash when the list says so', () => {
	assert.equal(
		removalQuestion({ ...company, objects: 412 }, t),
		'Remove the example set "Company"? Its 412 objects move to the trash of OpenRegister. Anything you made yourself stays.',
	)
	assert.equal(
		removalQuestion(company, t),
		'Remove the example set "Company"? Its objects move to the trash of OpenRegister. Anything you made yourself stays.',
	)
})

test('nothing is posted when the admin cancels or closes the question', async () => {
	let posts = 0
	const post = async () => {
		posts++
		return { data: { success: true } }
	}
	assert.equal(
		(await removeExampleSet(company, { confirm: async () => false, post, t }))
			.status,
		'cancelled',
	)
	assert.equal(
		(
			await removeExampleSet(company, {
				confirm: async () => {
					throw new Error('closed')
				},
				post,
				t,
			})
		).status,
		'cancelled',
	)
	assert.equal(posts, 0)
})

test('a refusal or an error is reported, with the server message when there is one', async () => {
	const refused = await removeExampleSet(company, {
		confirm: async () => true,
		post: async () => ({
			data: {
				success: false,
				message: 'Moved 3 example object(s) to the trash; 1 error.',
			},
		}),
		t,
	})
	assert.deepEqual(refused, {
		status: 'failed',
		message: 'Moved 3 example object(s) to the trash; 1 error.',
	})

	const broken = await removeExampleSet(company, {
		confirm: async () => true,
		post: async () => {
			throw new Error('500')
		},
		t,
	})
	assert.deepEqual(broken, {
		status: 'failed',
		message: 'The example set "Company" could not be removed.',
	})
})

test('the admin page carries the section and every new string has Dutch', () => {
	const root = readFileSync(
		new URL('../../src/views/settings/AdminRoot.vue', import.meta.url),
		'utf8',
	)
	assert.match(root, /<ExampleDataSettingsSection \/>/)
	const section = readFileSync(
		new URL(
			'../../src/views/settings/ExampleDataSettingsSection.vue',
			import.meta.url,
		),
		'utf8',
	)
	const nl = JSON.parse(
		readFileSync(new URL('../../l10n/nl.json', import.meta.url), 'utf8'),
	).translations
	const keys = [
		...section.matchAll(/t\(\s*'learniq',\s*'((?:[^'\\]|\\.)+)'/g),
	].map((m) => m[1])
	assert.ok(keys.length >= 8, 'the section translates its strings')
	for (const key of keys) {
		assert.ok(nl[key], `"${key}" has a Dutch translation`)
	}
})

test('only a plain yes removes; the fallback texts name the set; an error carries the server message', async () => {
	let posts = 0
	const post = async () => {
		posts++
		return { data: { success: true, message: '' } }
	}
	assert.deepEqual(await removeExampleSet(company, { confirm: async () => 'yes', post, t }), {
		status: 'cancelled',
		message: '',
	})
	assert.equal(posts, 0)
	assert.deepEqual(await removeExampleSet(company, { confirm: () => true, post, t }), {
		status: 'removed',
		message: 'The example set "Company" was removed.',
	})
	assert.equal(posts, 1)
	assert.deepEqual(
		await removeExampleSet(company, { confirm: async () => true, post: async () => ({ data: null }), t }),
		{ status: 'failed', message: 'The example set "Company" was removed.' },
	)
	const refused = await removeExampleSet(company, {
		confirm: async () => true,
		post: async () => {
			throw Object.assign(new Error('403'), { response: { data: { message: 'Only an administrator can do this.' } } })
		},
		t,
	})
	assert.deepEqual(refused, { status: 'failed', message: 'Only an administrator can do this.' })
})
