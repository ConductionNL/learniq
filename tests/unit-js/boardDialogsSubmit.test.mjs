// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Four dialogs that had no test, pinned through their own methods with a
// recording transport: the lesson note (one lesson or a weekly series), the
// accessibility criterion result, the standby slot and the timetable import.
// Each one's submit sends the right request, reports success to its parent
// and turns a refusal into the message the user reads.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { afterEach, beforeEach, test } from 'node:test'
import { instanceOf, loadSfc, recordingAxios } from './support/loadSfc.mjs'

const OBJECTS = '/nc/apps/openregister/api/objects/learniq'

beforeEach(() => {
	globalThis.t = (app, text, vars) => String(text).replace(/\{(\w+)\}/g, (whole, key) => (vars && key in vars ? String(vars[key]) : whole))
})

afterEach(() => {
	delete globalThis.t
})

/**
 * A dialog wired to a recording transport, with its emits logged.
 *
 * @param {string} file The dialog under src/dialogs/.
 * @param {object} props The props.
 * @param {object} [options] `reply` for the transport, extra `stubs`.
 * @return {Promise<{vm: object, http: object, emitted: Array}>} The instance, its transport and its emits.
 */
async function dialog(file, props, { reply = () => ({ data: {} }), stubs = {} } = {}) {
	const http = recordingAxios(reply)
	const component = await loadSfc(`src/dialogs/${file}`, {
		'@nextcloud/axios': { default: http },
		'@nextcloud/router': { generateUrl: (url) => `/nc${url}` },
		'../api/timetable.js': { fetchCohortTimetable: async () => { throw new Error('timetable read not expected') } },
		...stubs,
	})
	const emitted = []
	const vm = instanceOf(component, { ...props, $emit: (...args) => emitted.push(args) })
	return { vm, http, emitted }
}

/**
 * A rejection shaped like an axios error.
 *
 * @param {number} status The HTTP status.
 * @param {object} [data] The response body.
 * @return {Error} The error.
 */
function refusal(status, data = {}) {
	const error = new Error(`Request failed with status code ${status}`)
	error.response = { status, data }
	return error
}

const LESSON = {
	id: 's-1',
	cohortId: 'c-1',
	courseId: 'course-1',
	title: 'Biology',
	startsAt: '2026-11-16T09:00:00Z',
	endsAt: '2026-11-16T10:00:00Z',
}

test('LessonNoteDialog: a note for this lesson only posts one trimmed lesson-note and reports one', async () => {
	const { vm, http, emitted } = await dialog('LessonNoteDialog.vue', { session: LESSON })
	vm.topic = '  Test  '
	vm.text = '  Bring your lab coat  '
	vm.audience = 'cover'

	await vm.submit()

	assert.deepEqual(http.calls.map(({ method, url, body }) => ({ method, url, body })), [{
		method: 'post',
		url: `${OBJECTS}/lesson-note`,
		body: { cohortId: 'c-1', topic: 'Test', text: 'Bring your lab coat', audience: 'cover', sessionId: 's-1' },
	}])
	assert.deepEqual(emitted, [['saved', 1], ['close']])
	assert.equal(vm.saving, false)
	assert.equal(vm.error, '')
})

test('LessonNoteDialog: a series reads the cohort timetable over the weeks and notes every repeat of the lesson', async () => {
	const asked = []
	const nextWeek = { ...LESSON, id: 's-2', startsAt: '2026-11-23T09:00:00Z', endsAt: '2026-11-23T10:00:00Z' }
	const otherCourse = { ...LESSON, id: 's-3', courseId: 'course-9', title: 'Maths', startsAt: '2026-11-23T09:00:00Z' }
	const tooLate = { ...LESSON, id: 's-4', startsAt: '2026-12-07T09:00:00Z', endsAt: '2026-12-07T10:00:00Z' }
	const { vm, http, emitted } = await dialog('LessonNoteDialog.vue', { session: LESSON }, {
		stubs: {
			'../api/timetable.js': {
				fetchCohortTimetable: async (...args) => {
					asked.push(args)
					return { sessions: [LESSON, nextWeek, otherCourse, tooLate] }
				},
			},
		},
	})
	vm.text = 'Chapter 4'
	vm.weeks = 1

	await vm.submit()

	assert.deepEqual(asked, [['c-1', '2026-11-16T09:00:00.000Z', '2026-11-24T09:00:00.000Z']])
	assert.deepEqual(http.calls.map((c) => c.body.sessionId), ['s-1', 's-2'])
	assert.deepEqual(emitted, [['saved', 2], ['close']])
})

test('LessonNoteDialog: nothing is sent without text or without a cohort', async () => {
	const blank = await dialog('LessonNoteDialog.vue', { session: LESSON })
	blank.vm.text = '   '
	assert.equal(blank.vm.canSubmit, false)
	await blank.vm.submit()

	const noCohort = await dialog('LessonNoteDialog.vue', { session: { ...LESSON, cohortId: '' } })
	noCohort.vm.text = 'Note'
	assert.equal(noCohort.vm.canSubmit, false)
	await noCohort.vm.submit()

	assert.deepEqual([...blank.http.calls, ...noCohort.http.calls], [])
	assert.deepEqual([...blank.emitted, ...noCohort.emitted], [])
})

test('LessonNoteDialog: a refused note shows that only the lesson\'s teachers may add one, and closes nothing', async () => {
	const { vm, emitted } = await dialog('LessonNoteDialog.vue', { session: LESSON }, { reply: () => { throw refusal(403) } })
	vm.text = 'Note'

	await vm.submit()

	assert.equal(vm.error, 'The note could not be saved. Only the teachers of a lesson can add a note to it.')
	assert.equal(vm.saving, false)
	assert.deepEqual(emitted, [])
})

test('LessonNoteDialog: the label names the lesson, or an untitled session, and the week choices run to ten', async () => {
	const { vm } = await dialog('LessonNoteDialog.vue', { session: { cohortId: 'c-1', startsAt: 'not a date' } })
	assert.equal(vm.lessonLabel, 'Untitled session')

	const dated = await dialog('LessonNoteDialog.vue', { session: LESSON })
	assert.match(dated.vm.lessonLabel, /^Biology, .+/)

	const weeks = vm.weekOptions
	assert.equal(weeks.length, 11)
	assert.deepEqual(weeks[0], { value: 0, label: 'Only this lesson' })
	assert.deepEqual(weeks[1], { value: 1, label: 'The next week as well' })
	assert.deepEqual(weeks[10], { value: 10, label: 'The next 10 weeks as well' })
})

/**
 * A recording object store for CriterionResultDialog.
 *
 * @param {Function} answer (type, payload) => the saved object
 * @return {{saves: Array, saveObject: Function}} The store.
 */
function store(answer) {
	const saves = []
	return { saves, saveObject: async (type, payload) => { saves.push([type, payload]); return answer(type, payload) } }
}

const CRITERION = {
	row: { criterion: '1.1.1', level: 'A', result: 'fail', method: ' Manual ', evidenceReference: '', testedOn: '2026-10-01' },
	statement: { id: 'stmt-1', tenant_id: 'school-7' },
	objectType: 'accessibility-criterion-result',
	limitations: [
		{ id: 'lim-1', wcagCriterion: '1.1.1 Non-text content', description: 'Charts lack alt text', lifecycle: 'open' },
		{ id: 'lim-2', wcagCriterion: '1.1.1', affectedSurface: 'Old reports', lifecycle: 'fixed' },
		{ id: 'lim-3', wcagCriterion: '2.4.7', description: 'Focus ring', lifecycle: 'open' },
	],
}

test('CriterionResultDialog: a new result is saved with the statement context and reported', async () => {
	const objects = store((type, payload) => ({ id: 'r-1', ...payload }))
	const { vm, emitted } = await dialog('CriterionResultDialog.vue', { ...CRITERION, record: null }, {
		stubs: { '@conduction/nextcloud-vue': { useObjectStore: () => objects } },
	})
	vm.limitationId = 'lim-1'

	await vm.submit()

	assert.deepEqual(objects.saves, [['accessibility-criterion-result', {
		accessibilityStatementId: 'stmt-1',
		wcagCriterion: '1.1.1',
		level: 'A',
		result: 'fail',
		tenant_id: 'school-7',
		method: 'Manual',
		testedOn: '2026-10-01',
		limitationId: 'lim-1',
	}]])
	assert.equal(emitted.length, 1)
	assert.equal(emitted[0][0], 'saved')
	assert.equal(emitted[0][1].id, 'r-1')
	assert.equal(vm.error, '')
	assert.equal(vm.saving, false)
})

test('CriterionResultDialog: an existing record is updated under its own id', async () => {
	const objects = store((type, payload) => payload)
	const { vm } = await dialog('CriterionResultDialog.vue', { ...CRITERION, row: { criterion: '2.4.7', level: 'AA' }, record: { id: 'r-9' } }, {
		stubs: { '@conduction/nextcloud-vue': { useObjectStore: () => objects } },
	})
	vm.result = 'pass'

	await vm.submit()

	assert.equal(objects.saves[0][1].id, 'r-9')
	assert.equal(objects.saves[0][1].result, 'pass')
	assert.equal('limitationId' in objects.saves[0][1], false)
})

test('CriterionResultDialog: a store that saves nothing, or throws, shows the edit-rights message', async () => {
	for (const answer of [() => null, () => { throw refusal(403) }]) {
		const { vm, emitted } = await dialog('CriterionResultDialog.vue', { ...CRITERION, record: null }, {
			stubs: { '@conduction/nextcloud-vue': { useObjectStore: () => store(answer) } },
		})
		await vm.submit()
		assert.equal(vm.error, 'The result could not be saved. Check that you may edit the accessibility statement.')
		assert.equal(vm.saving, false)
		assert.deepEqual(emitted, [])
	}
})

test('CriterionResultDialog: the form starts from the row, offers only open limitations of the criterion, and warns on an unlinked fail', async () => {
	const { vm } = await dialog('CriterionResultDialog.vue', { ...CRITERION, record: null })

	assert.equal(vm.result, 'fail')
	assert.deepEqual(vm.limitationOptions, [{ value: 'lim-1', label: '1.1.1 Non-text content: Charts lack alt text' }])
	assert.deepEqual(vm.resultOptions.map((o) => o.value), ['pass', 'fail', 'not-applicable', 'not-tested'])
	assert.equal(vm.blocksPublishing, true)
	vm.limitationId = 'lim-1'
	assert.equal(vm.blocksPublishing, false)

	const fresh = await dialog('CriterionResultDialog.vue', { ...CRITERION, row: { criterion: '3.1.1', level: 'A' }, record: null })
	assert.equal(fresh.vm.result, 'not-tested')
	assert.equal(fresh.vm.limitationId, null)
	assert.equal(fresh.vm.blocksPublishing, false)
})

test('StandbySlotDialog: a complete slot posts a weekly standby-slot for the school year and closes', async () => {
	const { vm, http, emitted } = await dialog('StandbySlotDialog.vue', { teacherOptions: [], initialWeekday: 'wednesday', initialStartsAt: '10:00', initialEndsAt: '11:00' })
	vm.teacherId = 'teacher-3'
	vm.validFrom = '2026-08-01'
	vm.validUntil = '2027-07-31'
	assert.equal(vm.canSave, true)

	await vm.save()

	assert.deepEqual(http.calls.map(({ method, url, body }) => ({ method, url, body })), [{
		method: 'post',
		url: `${OBJECTS}/standby-slot`,
		body: { teacherId: 'teacher-3', weekday: 'wednesday', date: null, startsAt: '10:00', endsAt: '11:00', validFrom: '2026-08-01', validUntil: '2027-07-31' },
	}])
	assert.deepEqual(emitted, [['saved'], ['close']])
})

test('StandbySlotDialog: the slot is only saveable with a teacher and an end after the start', async () => {
	const { vm } = await dialog('StandbySlotDialog.vue', { teacherOptions: [], initialWeekday: 'monday', initialStartsAt: '10:00', initialEndsAt: '11:00' })
	assert.equal(vm.weekday, 'monday')
	assert.equal(vm.canSave, false)
	vm.teacherId = 't'
	assert.equal(vm.canSave, true)
	vm.endsAt = '10:00'
	assert.equal(vm.canSave, false)
	vm.endsAt = '09:30'
	assert.equal(vm.canSave, false)
	assert.match(vm.validFrom, /^\d{4}-08-01$/)
	assert.match(vm.validUntil, /^\d{4}-07-31$/)
	assert.equal(Number(vm.validUntil.slice(0, 4)), Number(vm.validFrom.slice(0, 4)) + 1)
	assert.equal(vm.weekdayOptions.length, 5)
	assert.deepEqual(vm.weekdayOptions.map((o) => o.value), ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'])
})

test('StandbySlotDialog: a refused save names who may plan standby hours and closes nothing', async () => {
	const { vm, emitted } = await dialog('StandbySlotDialog.vue', { teacherOptions: [], initialWeekday: 'friday', initialStartsAt: '10:00', initialEndsAt: '11:00' }, { reply: () => { throw refusal(403) } })
	vm.teacherId = 't'

	await vm.save()

	assert.equal(vm.error, 'The standby could not be saved. Only team leads and compliance officers can plan standby hours.')
	assert.equal(vm.saving, false)
	assert.deepEqual(emitted, [])
})

test('TimetableImportDialog: the import posts the chosen roster source and reports the state planninq answered', async () => {
	const { vm, http, emitted } = await dialog('TimetableImportDialog.vue', {}, { reply: () => ({ data: { state: 'queued', id: 'imp-1' } }) })
	assert.deepEqual(vm.sourceOptions, [
		{ id: 'roster-zermelo', label: 'Zermelo' },
		{ id: 'roster-untis-oneroster', label: 'Untis' },
		{ id: 'roster-xedule', label: 'Xedule' },
		{ id: 'roster-timeedit', label: 'TimeEdit' },
	])
	vm.source = vm.sourceOptions[1]

	await vm.submit()

	assert.deepEqual(http.calls.map(({ method, url, body }) => ({ method, url, body })), [{
		method: 'post',
		url: '/nc/apps/learniq/api/timetable/imports',
		body: { rosterSource: 'roster-untis-oneroster' },
	}])
	assert.equal(vm.done, 'The timetable was delivered to planninq (queued).')
	assert.deepEqual(emitted, [['imported', { state: 'queued', id: 'imp-1' }]])
	assert.equal(vm.busy, false)
	assert.equal(vm.error, '')
})

test('TimetableImportDialog: a refusal shows the server\'s reason, else its error, else a generic message', async () => {
	for (const [error, expected] of [
		[refusal(409, { reason: 'An import is already running.', error: 'conflict' }), 'An import is already running.'],
		[refusal(400, { error: 'Unknown roster source' }), 'Unknown roster source'],
		[refusal(500), 'The timetable could not be imported.'],
		[new Error('Network Error'), 'The timetable could not be imported.'],
	]) {
		const { vm, emitted } = await dialog('TimetableImportDialog.vue', {}, { reply: () => { throw error } })
		vm.source = { id: 'roster-zermelo' }
		vm.done = 'stale'
		await vm.submit()
		assert.equal(vm.error, expected)
		assert.equal(vm.done, '')
		assert.equal(vm.busy, false)
		assert.deepEqual(emitted, [])
	}
})
