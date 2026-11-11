// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The exemption decision dialog's reads and facts, pinned through the
// component's own hook and computed with stubbed request helpers.
//
// @spec openspec/specs/exam-board/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const reads = []
let objects = {}
const component = await loadSfc('src/dialogs/ExemptionDecisionDialog.vue', {
	'@nextcloud/l10n': { getLanguage: () => 'en' },
	'./dialogRequests.js': {
		announceSaved: () => {},
		readObject: async (schema, id) => {
			reads.push(`${schema}/${id}`)
			const found = objects[`${schema}/${id}`]
			if (found instanceof Error || found === undefined) throw found ?? new Error('404')
			return found
		},
		runTransition: async () => {},
	},
	'./dialogNotice.js': {
		dialogNoticeProps: () => ({}),
		requestRefusal: (error, title, fallback) => ({ error: error.message, title, fallback }),
	},
})

/**
 * A dialog for case x1.
 *
 * @param {object} found schema/id to object (or Error).
 * @return {object}
 */
function dialog(found) {
	reads.length = 0
	objects = found
	return instanceOf(component, { objectId: 'x1' })
}

test('the case is read, then its pupil and plan', async () => {
	const vm = dialog({
		'exemption-case/x1': { learnerId: 'sem', curriculumPlanId: 'p1', groundsKind: 'certificate', groundsDescription: 'VCA' },
		'learner-profile/sem': { givenName: 'Sem', familyName: 'Jong', groupLabel: '3B' },
		'curriculum-plan/p1': { name: 'Bouw', components: [] },
	})
	await component.created.call(vm)
	assert.deepEqual(reads, ['exemption-case/x1', 'learner-profile/sem', 'curriculum-plan/p1'])
	assert.equal(vm.learner.groupLabel, '3B')
	assert.equal(vm.plan.name, 'Bouw')
	assert.equal(vm.notice, null)
})

test('a case without pupil or plan reads neither; an unreadable pupil or plan is left empty', async () => {
	const bare = dialog({ 'exemption-case/x1': {} })
	await component.created.call(bare)
	assert.deepEqual(reads, ['exemption-case/x1'])
	assert.deepEqual([bare.learner, bare.plan], [{}, {}])

	const partly = dialog({ 'exemption-case/x1': { learnerId: 'sem', curriculumPlanId: 'p1' }, 'curriculum-plan/p1': { name: 'Bouw' } })
	partly.learner = { stale: true }
	await component.created.call(partly)
	assert.deepEqual(partly.learner, {})
	assert.deepEqual(partly.plan, { name: 'Bouw' })
})

test('an unreadable case shows the refusal and reads nothing else', async () => {
	const vm = dialog({})
	await component.created.call(vm)
	assert.deepEqual(reads, ['exemption-case/x1'])
	assert.deepEqual(vm.notice, { error: '404', title: 'Not found.', fallback: 'The request could not be read.' })
	assert.deepEqual(vm.exemption, {})
})

test('the facts name the pupil, the part, the grounds and the submission day', () => {
	const vm = dialog({})
	vm.learner = { givenName: 'Sem', familyName: 'Jong', groupLabel: '3B' }
	vm.plan = { name: 'Bouw', components: [{ componentId: 'k1', label: 'Veiligheid' }] }
	vm.exemption = { componentId: 'k1', groundsKind: 'certificate', groundsDescription: 'VCA', submittedAt: '2026-10-01T10:00:00' }
	const facts = vm.facts
	assert.deepEqual(facts.map((f) => f.label), ['Pupil', 'Part', 'Grounds', 'Submitted'])
	assert.deepEqual(facts.slice(1, 3).map((f) => f.value), ['Veiligheid (Bouw)', 'Certificate: VCA'])
	assert.match(facts[0].value, /, 3B$/)
	assert.match(facts[3].value, /2026/)
	for (const [exemption, grounds] of [
		[{ groundsKind: 'other' }, 'Other'],
		[{ groundsDescription: 'Eerder behaald' }, 'Eerder behaald'],
		[{ groundsKind: 'unknown', groundsDescription: 'x' }, 'x'],
		[{}, ''],
	]) {
		vm.exemption = exemption
		assert.equal(vm.facts[2].value, grounds)
		assert.equal(vm.facts[3].value, '')
	}
	vm.learner = {}
	assert.equal(vm.facts[0].value, vm.learnerName)
})
