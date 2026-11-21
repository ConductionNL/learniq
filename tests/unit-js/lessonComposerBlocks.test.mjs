// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The lesson composer's list is CnBlockListEditor (learniq/LqLesMaken): the
// palette offers every block type, a new block starts empty with a UUID, and
// the composer keeps no list of its own beside the library's.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { BLOCK_TYPES, emptyBlock, serialiseLessonBlocks } from '../../src/utils/lessonBlocks.js'

test('the palette offers the six block types in the board order', () => {
	assert.deepEqual(BLOCK_TYPES, ['richText', 'media', 'quiz', 'assignment', 'ltiTool', 'teacherNote'])
})

test('a new text block starts empty and a picker block without a choice', () => {
	const text = emptyBlock('richText')
	const media = emptyBlock('media')
	assert.equal(text.text, '')
	assert.equal(media.text, null)
	assert.equal(media.materialId, null)
	assert.notEqual(text.blockId, media.blockId)
	assert.deepEqual(serialiseLessonBlocks([{ ...media, type: 'media' }])[0], { blockId: media.blockId, type: 'media', order: 0 })
})

test('the composer draws its blocks through CnBlockListEditor only', () => {
	const view = readFileSync(new URL('../../src/views/LessonComposer.vue', import.meta.url), 'utf8')
	assert.ok(view.includes('<CnBlockListEditor'))
	assert.ok(!view.includes('vuedraggable'))
	for (const type of BLOCK_TYPES) {
		assert.ok(view.includes(`#block-${type}=`), type)
	}
})
