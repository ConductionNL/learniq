// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// timetabling-standby-slots: the standby page's CnSlotGrid configuration
// against the StandbySlot schema, and the candidate options in the server's
// order with their reasons. The school year bounds are the server's
// (SchoolYear, StandbySlotDefaultsStamp).
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { candidateOptions } from '../../src/utils/standby.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const learning = JSON.parse(readFileSync(resolve(root, 'src/manifest.d/learning.json'), 'utf8'))
const register = JSON.parse(readFileSync(resolve(root, 'lib/Settings/learniq_register.json'), 'utf8'))
const slot = Object.values(register.components.schemas).find((s) => s.slug === 'standby-slot')
const content = learning.pages.find((p) => p.id === 'StandbyPlanning').widgets.find((w) => w.slot === 'body').props.content

test('the standby page lays the slots out by time window and weekday', () => {
	assert.deepEqual(content.source, { register: 'learniq', schema: 'standby-slot' })
	assert.deepEqual(content.rowFields, ['startsAt', 'endsAt'])
	assert.equal(content.columnField, 'weekday')
	assert.deepEqual(content.columns.map((c) => c.value), slot.properties.weekday.enum)
	assert.equal(content.skipWhenField, 'date')
	assert.equal(content.itemLabelKind, 'user')
})

test('the add form asks every field the schema still requires, and the teacher as a user', () => {
	for (const field of slot.required) {
		assert.ok(content.cellAction.includeFields.includes(field), field)
	}
	for (const field of content.cellAction.includeFields) {
		assert.ok(field in slot.properties, field)
	}
	assert.equal(content.cellAction.fieldOverrides.teacherId.widget, 'user')
})


test('the options keep the order and name the reason', () => {
	const t = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, k) => vars[k])
	const options = candidateOptions(
		[
			{
				userId: 'eva',
				displayName: 'Eva',
				group: 'standby',
				slot: { startsAt: '10:15', endsAt: '11:05' },
			},
			{ userId: 'free1', displayName: 'Free', group: 'free' },
			{ userId: 'busy', displayName: 'Busy', group: 'busy' },
		],
		t,
	)
	assert.deepEqual(
		options.map((o) => o.value),
		['eva', 'free1', 'busy'],
	)
	assert.equal(options[0].label, 'Eva (On standby 10:15 to 11:05)')
	assert.equal(options[2].label, 'Busy (On standby, but has a lesson then)')
})
