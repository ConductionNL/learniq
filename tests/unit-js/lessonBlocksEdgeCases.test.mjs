// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The lesson block helpers given missing lists, missing fields and blocks
// without an order: nothing is lost and nothing throws.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	countPendingDrafts,
	makeDraftBlock,
	mergeTeacherNotes,
	playerVisibleBlocks,
	serialiseLessonBlocks,
	splitTeacherNotes,
	TEACHER_NOTE,
} from '../../src/utils/lessonBlocks.js'

test('serialising no blocks gives an empty list', () => {
	assert.deepEqual(serialiseLessonBlocks(null), [])
	assert.deepEqual(serialiseLessonBlocks(undefined), [])
})

test('a block of an unknown type keeps only its id, type and order', () => {
	assert.deepEqual(
		serialiseLessonBlocks([{ blockId: 'b1', type: 'heading', order: 2, text: 'Kop', draft: true }]),
		[{ blockId: 'b1', type: 'heading', order: 2 }],
	)
})

test('an undefined payload is dropped while an empty string is kept', () => {
	assert.deepEqual(
		serialiseLessonBlocks([
			{ blockId: 'a', type: 'media', order: 0 },
			{ blockId: 'b', type: 'richText', order: 1, text: '' },
		]),
		[
			{ blockId: 'a', type: 'media', order: 0 },
			{ blockId: 'b', type: 'richText', order: 1, text: '' },
		],
	)
})

test('a draft without text, action, note or provider gets empty strings and a null provider', () => {
	const block = makeDraftBlock({ blockId: 'd1' })
	assert.equal(block.text, '')
	assert.deepEqual(block.draft, { note: '', action: '', provider: null })
})

test('a draft provider is written as a string', () => {
	assert.equal(makeDraftBlock({ blockId: 'd2', text: 'x', provider: 42 }).draft.provider, '42')
})

test('no blocks means no pending drafts', () => {
	assert.equal(countPendingDrafts(null), 0)
	assert.equal(countPendingDrafts([null, { draft: null }, { draft: {} }]), 1)
})

test('the player sorts blocks without an order first and leaves notes out', () => {
	const blocks = [
		{ blockId: 'b', order: 1 },
		{ blockId: 'n', type: TEACHER_NOTE, order: 0 },
		{ blockId: 'a' },
	]
	assert.deepEqual(playerVisibleBlocks(blocks).map((b) => b.blockId), ['a', 'b'])
	assert.deepEqual(playerVisibleBlocks(null), [])
})

test('splitting no blocks gives no blocks and no notes', () => {
	assert.deepEqual(splitTeacherNotes(null), { blocks: [], notes: [] })
})

test('a note after a block without an id anchors to the start, with empty text and no id', () => {
	const { blocks, notes } = splitTeacherNotes([
		{ type: 'richText' },
		{ blockId: 'n1', type: TEACHER_NOTE },
		{ blockId: 'n2', type: TEACHER_NOTE, text: 'tweede', noteId: 'x' },
	])
	assert.equal(blocks.length, 1)
	assert.deepEqual(notes, [
		{ blockId: 'n1', afterBlockId: '', position: 0, text: '', noteId: null },
		{ blockId: 'n2', afterBlockId: '', position: 1, text: 'tweede', noteId: 'x' },
	])
})

test('merging with no blocks and no notes gives an empty list', () => {
	assert.deepEqual(mergeTeacherNotes(null, null), [])
})

test('merged notes take their id from id, uuid or @self, sorted by position', () => {
	const merged = mergeTeacherNotes(
		[{ blockId: 'b2', order: 1 }, { blockId: 'b1' }],
		[
			{ blockId: 'n3', afterBlockId: 'b1', position: 1, '@self': { id: 'self-3' } },
			{ blockId: 'n2', afterBlockId: 'b1', uuid: 'uuid-2' },
			{ blockId: 'n1', afterBlockId: 'gone', id: 'id-1', text: 'los' },
			{ blockId: 'n4', afterBlockId: 'b2' },
		],
	)
	assert.deepEqual(
		merged.map((b) => [b.blockId, b.type === TEACHER_NOTE ? b.noteId : 'block']),
		[['n1', 'id-1'], ['b1', 'block'], ['n2', 'uuid-2'], ['n3', 'self-3'], ['b2', 'block'], ['n4', null]],
	)
	assert.equal(merged[0].text, 'los')
	assert.equal(merged[5].text, '')
})
