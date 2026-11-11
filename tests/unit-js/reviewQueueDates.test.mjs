// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The date and time the two review queues (timetable conflicts, proctoring
// flags) show, pinned: in the reader's language, nothing for no date, and
// the raw value when it is not a date.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27

import assert from 'node:assert/strict'
import { afterEach, beforeEach, test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const OPTIONS = { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }

beforeEach(() => {
	globalThis.t = (app, text) => text
	globalThis.window = { OC: {} }
})

afterEach(() => {
	delete globalThis.t
	delete globalThis.window
})

for (const file of ['src/views/TimetableConflictQueue.vue', 'src/views/ProctoringReviewQueue.vue']) {
	test(`${file}: a date and time in the reader's language`, async () => {
		const vm = instanceOf(await loadSfc(file))
		const iso = '2026-03-04T10:15:00Z'
		assert.equal(vm.formatDate(iso), new Intl.DateTimeFormat(navigator.language, OPTIONS).format(new Date(iso)))
		assert.equal(vm.formatDate(''), '')
		assert.equal(vm.formatDate(null), '')
		assert.equal(vm.formatDate('not a date'), 'not a date')
	})
}
