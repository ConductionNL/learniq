// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the lesson onboarding review page helpers and the teacherNote
// block (office-file-lesson-onboarding). Run via `npm run test:js-unit`.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	playerVisibleBlocks,
	serialiseLessonBlocks,
} from '../../src/utils/lessonBlocks.js'
import {
	dismissRequest,
	formatLabel,
	importErrorKey,
	importRequest,
	rowsFrom,
	rowsUrl,
} from '../../src/utils/lessonOnboarding.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const register = JSON.parse(
	readFileSync(resolve(root, 'lib/Settings/learniq_register.json'), 'utf8'),
)
const rowSchema = register.components.schemas.LessonOnboardingFile

test('the list URL asks OpenRegister for one teacher\'s rows in one state', () => {
	const url = new URL(rowsUrl('jdevries', 'detected'), 'https://x.invalid')
	assert.equal(url.pathname, '/apps/openregister/api/objects/learniq/lesson-onboarding-file')
	assert.equal(url.searchParams.get('teacherId'), 'jdevries')
	assert.equal(url.searchParams.get('lifecycle'), 'detected')
	assert.equal(url.searchParams.get('_limit'), '100')
	assert.equal(new URL(rowsUrl('a', 'imported', 20), 'https://x.invalid').searchParams.get('_limit'), '20')
	// The filters name real properties of the schema.
	assert.ok('teacherId' in rowSchema.properties)
	assert.ok('lifecycle' in rowSchema.properties)
})

test('dismissing sends only the lifecycle, and the lifecycle can take it', () => {
	const request = dismissRequest('row/1')
	assert.equal(request.url, '/apps/openregister/api/objects/learniq/lesson-onboarding-file/row%2F1')
	assert.deepEqual(request.body, { lifecycle: 'dismissed' })
	assert.equal(rowSchema['x-openregister-lifecycle'].transitions.dismiss.to, 'dismissed')
})

test('importing posts the course to learniq\'s import route', () => {
	const request = importRequest('00000000-0000-0000-0000-000000000001', 'c1')
	assert.equal(request.url, '/apps/learniq/api/lesson-onboarding/files/00000000-0000-0000-0000-000000000001/import')
	assert.deepEqual(request.body, { courseId: 'c1' })
	const routes = readFileSync(resolve(root, 'appinfo/routes.php'), 'utf8')
	assert.match(routes, /'url' => '\/api\/lesson-onboarding\/files\/\{id\}\/import',\s*'verb' => 'POST'/)
})

test('refusals map to one message key each', () => {
	assert.equal(importErrorKey(503, { reason: 'reader-unavailable' }), 'reader-unavailable')
	assert.equal(importErrorKey(410, {}), 'file-gone')
	assert.equal(importErrorKey(409, {}), 'not-detected')
	assert.equal(importErrorKey(404, {}), 'not-found')
	assert.equal(importErrorKey(422, { reason: 'course-not-found' }), 'course-not-found')
	assert.equal(importErrorKey(422, { reason: 'unreadable' }), 'unreadable')
	assert.equal(importErrorKey(400, null), 'no-course')
	assert.equal(importErrorKey(500, null), 'failed')
	assert.equal(importErrorKey(0, null), 'failed')
})

test('list answers are read from any envelope', () => {
	assert.deepEqual(rowsFrom([{ id: 1 }]), [{ id: 1 }])
	assert.deepEqual(rowsFrom({ results: [{ id: 2 }] }), [{ id: 2 }])
	assert.deepEqual(rowsFrom({ objects: [{ id: 3 }] }), [{ id: 3 }])
	assert.deepEqual(rowsFrom(null), [])
	assert.equal(formatLabel('docx'), 'Word')
	assert.equal(formatLabel('pptx'), 'PowerPoint')
})

test('teacherNote keeps its text when serialised', () => {
	const [note] = serialiseLessonBlocks([
		{ blockId: 'n1', type: 'teacherNote', order: 2, text: 'Vraag naar de rol van licht.', materialId: null },
	])
	assert.deepEqual(note, { blockId: 'n1', type: 'teacherNote', order: 2, text: 'Vraag naar de rol van licht.' })
	assert.ok(
		register.components.schemas.Lesson.properties.blocks.items.properties.type.enum.includes('teacherNote'),
	)
})

test('the player\'s block list leaves teacher notes out and keeps the order', () => {
	const blocks = playerVisibleBlocks([
		{ blockId: 'b', type: 'richText', order: 3 },
		{ blockId: 'n', type: 'teacherNote', order: 2 },
		{ blockId: 'a', type: 'media', order: 1 },
	])
	assert.deepEqual(blocks.map((b) => b.blockId), ['a', 'b'])
	assert.deepEqual(playerVisibleBlocks(undefined), [])
})
