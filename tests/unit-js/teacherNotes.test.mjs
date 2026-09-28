// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the composer's teacher note helpers (teacher-notes-protection):
// notes are shown among the blocks but never saved into a lesson; they go to the
// staff-only lesson-teacher-note schema. Run via `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	diffTeacherNotes,
	mergeTeacherNotes,
	serialiseLessonBlocks,
	splitTeacherNotes,
	TEACHER_NOTE_SCHEMA,
} from '../../src/utils/lessonBlocks.js'

const composerList = [
	{ blockId: 'a', type: 'richText', order: 1, text: 'Wat is licht?' },
	{ blockId: 'n1', type: 'teacherNote', order: 2, text: 'Vraag naar de rol van licht.', noteId: 'uuid-n1' },
	{ blockId: 'b', type: 'media', order: 3, materialId: 'm-1' },
	{ blockId: 'n2', type: 'teacherNote', order: 4, text: 'Sem heeft hier extra uitleg nodig.' },
	{ blockId: 'n3', type: 'teacherNote', order: 5, text: 'Laat de foto groot zien.' },
]

test('notes leave the lesson blocks with their anchor and position', () => {
	const { blocks, notes } = splitTeacherNotes(composerList)

	assert.deepEqual(blocks.map((b) => b.blockId), ['a', 'b'])
	assert.deepEqual(notes, [
		{ blockId: 'n1', afterBlockId: 'a', position: 0, text: 'Vraag naar de rol van licht.', noteId: 'uuid-n1' },
		{ blockId: 'n2', afterBlockId: 'b', position: 0, text: 'Sem heeft hier extra uitleg nodig.', noteId: null },
		{ blockId: 'n3', afterBlockId: 'b', position: 1, text: 'Laat de foto groot zien.', noteId: null },
	])
})

test('a note before the first block has no anchor', () => {
	const { notes } = splitTeacherNotes([
		{ blockId: 'n', type: 'teacherNote', text: 'Eerst' },
		{ blockId: 'a', type: 'richText', text: 'Les' },
	])
	assert.equal(notes[0].afterBlockId, '')
})

test('the serialiser never sends a note inside a lesson, whatever it is given', () => {
	const serialised = serialiseLessonBlocks(composerList)
	assert.deepEqual(serialised.map((b) => b.type), ['richText', 'media'])
	assert.ok(!JSON.stringify(serialised).includes('Sem heeft hier'))
})

test('merge puts each note back after its block, in position order', () => {
	const merged = mergeTeacherNotes(
		[
			{ blockId: 'b', type: 'media', order: 2, materialId: 'm-1' },
			{ blockId: 'a', type: 'richText', order: 1, text: 'Wat is licht?' },
		],
		[
			{ id: 'uuid-n3', blockId: 'n3', afterBlockId: 'b', position: 1, text: 'Laat de foto groot zien.' },
			{ id: 'uuid-n1', blockId: 'n1', afterBlockId: 'a', position: 0, text: 'Vraag naar de rol van licht.' },
			{ id: 'uuid-n2', blockId: 'n2', afterBlockId: 'b', position: 0, text: 'Sem heeft hier extra uitleg nodig.' },
		],
	)

	assert.deepEqual(merged.map((b) => b.blockId), ['a', 'n1', 'b', 'n2', 'n3'])
	assert.equal(merged[1].type, 'teacherNote')
	assert.equal(merged[1].noteId, 'uuid-n1')
})

test('a note whose block was deleted is shown first, not lost', () => {
	const merged = mergeTeacherNotes(
		[{ blockId: 'a', type: 'richText', order: 1 }],
		[{ id: 'x', blockId: 'n', afterBlockId: 'gone', position: 0, text: 'Wees' }],
	)
	assert.deepEqual(merged.map((b) => b.blockId), ['n', 'a'])
})

test('split after merge gives back the stored notes', () => {
	const stored = [
		{ id: 'uuid-n1', blockId: 'n1', afterBlockId: 'a', position: 0, text: 'Eén' },
		{ id: 'uuid-n2', blockId: 'n2', afterBlockId: 'a', position: 1, text: 'Twee' },
	]
	const { notes } = splitTeacherNotes(
		mergeTeacherNotes([{ blockId: 'a', type: 'richText', order: 1 }], stored),
	)
	assert.deepEqual(
		notes.map(({ blockId, afterBlockId, position, text }) => ({ blockId, afterBlockId, position, text })),
		stored.map(({ blockId, afterBlockId, position, text }) => ({ blockId, afterBlockId, position, text })),
	)
	assert.deepEqual(diffTeacherNotes(stored, notes), { create: [], update: [], remove: [] })
})

test('diff creates new notes, updates changed ones and removes deleted ones by blockId', () => {
	const loaded = [
		{ id: 'uuid-keep', blockId: 'keep', afterBlockId: 'a', position: 0, text: 'Gelijk' },
		{ id: 'uuid-edit', blockId: 'edit', afterBlockId: 'a', position: 1, text: 'Oud' },
		{ id: 'uuid-move', blockId: 'move', afterBlockId: 'a', position: 2, text: 'Verplaatst' },
		{ id: 'uuid-gone', blockId: 'gone', afterBlockId: 'b', position: 0, text: 'Weg' },
	]
	const current = [
		{ blockId: 'keep', afterBlockId: 'a', position: 0, text: 'Gelijk' },
		{ blockId: 'edit', afterBlockId: 'a', position: 1, text: 'Nieuw' },
		{ blockId: 'move', afterBlockId: 'b', position: 0, text: 'Verplaatst' },
		{ blockId: 'new', afterBlockId: 'b', position: 1, text: 'Toegevoegd' },
	]

	const diff = diffTeacherNotes(loaded, current)

	assert.deepEqual(diff.create, [{ blockId: 'new', afterBlockId: 'b', position: 1, text: 'Toegevoegd' }])
	assert.deepEqual(diff.update.map((n) => [n.id, n.text, n.afterBlockId]), [
		['uuid-edit', 'Nieuw', 'a'],
		['uuid-move', 'Verplaatst', 'b'],
	])
	assert.deepEqual(diff.remove, ['uuid-gone'])
})

test('the notes store is the staff-only schema', () => {
	assert.equal(TEACHER_NOTE_SCHEMA, 'lesson-teacher-note')
})
