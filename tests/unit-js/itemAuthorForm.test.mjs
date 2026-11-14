// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// ItemAuthorView seeds its form from a stored QTI Item: plain fields with the
// editor defaults, the prompt stripped from the QTI body, and for a choice
// interaction the options and the correct answer. These pin what the view's
// loadItem built inline before the logic moved into src/utils/itemAuthorForm.js.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	choicesFromQti,
	correctChoiceIndex,
	itemFormFields,
	promptFromQtiBody,
} from '../../src/utils/itemAuthorForm.js'

/**
 * A stand-in for a parsed QTI document holding simpleChoice elements.
 *
 * @param {Array<{identifier?: string, text?: string|null}>} choices The choices.
 * @return {object} The document.
 */
function qtiDoc(choices) {
	const elements = choices.map((c) => ({
		getAttribute: (name) => (name === 'identifier' && c.identifier !== undefined ? c.identifier : null),
		textContent: c.text,
	}))
	return {
		getElementsByTagName: (tag) => (tag === 'simpleChoice' ? elements : []),
	}
}

test('itemFormFields applies the editor defaults', () => {
	assert.deepEqual(itemFormFields({}), { title: '', interactionType: 'choice', maxScore: 1 })
	assert.deepEqual(itemFormFields({ title: 'T', interactionType: 'textEntry', maxScore: 0 }), {
		title: 'T', interactionType: 'textEntry', maxScore: 0,
	})
})

test('promptFromQtiBody strips tags and collapses whitespace', () => {
	assert.equal(promptFromQtiBody('<p>What  is\n<b>2+2</b>?</p>'), 'What is 2+2 ?')
	assert.equal(promptFromQtiBody(null), '')
	assert.equal(promptFromQtiBody(undefined), '')
})

test('choicesFromQti reads identifiers and labels, lettering unnamed choices', () => {
	const doc = qtiDoc([
		{ identifier: 'X', text: '  Four ' },
		{ text: 'Five' },
		{ identifier: 'Z', text: null },
	])
	assert.deepEqual(choicesFromQti(doc), [
		{ id: 'X', label: 'Four' },
		{ id: 'B', label: 'Five' },
		{ id: 'Z', label: '' },
	])
	assert.deepEqual(choicesFromQti(qtiDoc([])), [])
})

test('correctChoiceIndex finds the correct option, else the first', () => {
	const choices = [{ id: 'A' }, { id: 'B' }]
	assert.equal(correctChoiceIndex(choices, 'B'), 1)
	assert.equal(correctChoiceIndex(choices, 'Q'), 0)
	assert.equal(correctChoiceIndex([], 'A'), 0)
})
