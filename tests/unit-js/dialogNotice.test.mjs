// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The refusal banner the board dialogs share (dialogNotice.js): bound on
// CnDialog only once the installed library takes `notices`, so nothing
// unknown falls through to NcDialog, and worded from the server's own
// sentence when it sent one.
//
// @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component

import assert from 'node:assert/strict'
import { register } from 'node:module'
import { test } from 'node:test'

// The util imports CnDialog from the component library, which needs a
// browser to load. Resolve it to a stub whose props the test sets.
const stub = 'export const CnDialog = globalThis.__cnDialog'
const hooks = `
const stub = ${JSON.stringify(stub)}
export async function resolve(specifier, context, next) {
	if (specifier === '@conduction/nextcloud-vue') {
		return { url: 'data:text/javascript,' + encodeURIComponent(stub), shortCircuit: true }
	}
	return next(specifier, context)
}
`
register('data:text/javascript,' + encodeURIComponent(hooks))
globalThis.__cnDialog = { name: 'CnDialog', props: { name: String, notices: Array } }

const { dialogDrawsNotices, dialogNoticeProps, requestRefusal } = await import('../../src/dialogs/dialogNotice.js')

const withNotices = { props: { notices: Array, noticesPlacement: String } }
const withoutNotices = { props: { name: String } }
const notice = { variant: 'error', role: 'alert', layout: 'stacked', title: 'Not saved', text: 'No.' }

test('a library dialog that takes notices draws them itself', () => {
	assert.equal(dialogDrawsNotices(withNotices), true)
	assert.equal(dialogDrawsNotices(withoutNotices), false)
})

test('a dialog without props, or no dialog, does not draw notices', () => {
	assert.equal(dialogDrawsNotices({}), false)
	assert.equal(dialogDrawsNotices(null), false)
})

test('the installed CnDialog is asked when no dialog is given', () => {
	assert.equal(dialogDrawsNotices(), true)
	assert.deepEqual(dialogNoticeProps(notice), { notices: [notice], noticesPlacement: 'bottom' })
})

test('a notice is bound below the buttons once the library takes it', () => {
	assert.deepEqual(dialogNoticeProps(notice, withNotices), { notices: [notice], noticesPlacement: 'bottom' })
})

test('no notice binds an empty list, so a cleared refusal disappears', () => {
	assert.deepEqual(dialogNoticeProps(null, withNotices), { notices: [], noticesPlacement: 'bottom' })
})

test('nothing is bound while the library does not take notices', () => {
	assert.deepEqual(dialogNoticeProps(notice, withoutNotices), {})
	assert.deepEqual(dialogNoticeProps(null, withoutNotices), {})
})

test('a refusal shows the server\'s error sentence first', () => {
	const error = { response: { data: { error: 'The deadline has passed.', message: 'Forbidden' } } }
	assert.deepEqual(requestRefusal(error, 'Not handed in', 'Try again.'), {
		variant: 'error',
		role: 'alert',
		layout: 'stacked',
		title: 'Not handed in',
		text: 'The deadline has passed.',
	})
})

test('a refusal falls back to the server\'s message', () => {
	const error = { response: { data: { message: 'Transition not allowed' } } }
	assert.equal(requestRefusal(error, 'Not saved', 'Try again.').text, 'Transition not allowed')
})

test('a refusal without a server sentence uses the fallback', () => {
	assert.equal(requestRefusal({ response: { data: {} } }, 'Not saved', 'Try again.').text, 'Try again.')
	assert.equal(requestRefusal(new Error('Network Error'), 'Not saved', 'Try again.').text, 'Try again.')
	assert.equal(requestRefusal(undefined, 'Not saved', 'Try again.').text, 'Try again.')
	assert.equal(requestRefusal({ response: { data: { error: '' } } }, 'Not saved', 'Try again.').text, 'Try again.')
})
