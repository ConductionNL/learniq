// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// learniq#947: the builders behind the custom pages that used to open empty.
// They decide what the attendance register, the gradebook, bulk enrolment,
// the export request and the learning-plan editor write, so they are pinned
// here against the register's real field names.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	auditPackUrl,
	exchangeRequestBody,
	exchangeRequestUrl,
	gradebookGrid,
	gradeEntryBody,
	handInAction,
	moveItem,
	nextGoalId,
	parseMark,
	planGraph,
} from '../../src/utils/customPages.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const register = JSON.parse(
	readFileSync(resolve(root, 'lib/Settings/learniq_register.json'), 'utf8'),
)
const schemas = register.components.schemas

/**
 * Assert every key of a body is a property of the schema, and every required
 * property is present.
 *
 * @param {object} body The body a view posts.
 * @param {string} schema Schema key in the register.
 * @param {string[]} [serverSet] Required fields the server sets itself.
 */
function assertFitsSchema(body, schema, serverSet = []) {
	const def = schemas[schema]
	for (const key of Object.keys(body)) {
		assert.ok(key in def.properties, `${schema} has no property ${key}`)
	}
	for (const key of def.required ?? []) {
		if (serverSet.includes(key)) continue
		assert.ok(key in body, `${schema} requires ${key}`)
	}
}

test('the gradebook grid skips revised and invalidated marks and posts a valid concept entry', () => {
	const components = [
		{
			componentId: 'k1',
			label: 'Test 1',
			weight: 2,
			period: 'P1',
			kind: 'test',
		},
	]
	const grid = gradebookGrid(['a', 'b'], components, [
		{ learnerId: 'a', componentId: 'k1', value: 6, lifecycle: 'revised' },
		{ learnerId: 'a', componentId: 'k1', value: 7.5, lifecycle: 'published' },
		{ learnerId: 'b', componentId: 'k1', value: 4, lifecycle: 'invalidated' },
	])
	assert.deepEqual(grid.rows, [
		{ id: 'a', label: 'a', k1: 7.5 },
		{ id: 'b', label: 'b', k1: '' },
	])
	const body = gradeEntryBody({
		learnerId: 'b',
		componentId: 'k1',
		value: 8,
		plan: { id: 'p1', gradeScaleId: 'nl-10', components, tenant_id: 't' },
		cohortId: 'c1',
		grader: 'teacher',
		gradedAt: '2026-09-27T10:00:00Z',
	})
	assertFitsSchema(body, 'GradeEntry')
	assert.equal(body.gradeScaleId, 'nl-10')
	assert.equal(parseMark('7,5'), 7.5)
	assert.equal(parseMark(''), null)
	assert.equal(parseMark('abc'), null)
})

test('an export request names a target and, optionally, one learner', () => {
	assert.deepEqual(exchangeRequestBody({ target: 'bron-rod', learnerId: '  ' }), {
		target: 'bron-rod',
	})
	assert.deepEqual(exchangeRequestBody({ target: 'oso', learnerId: ' sanne ' }), {
		target: 'oso',
		learnerId: 'sanne',
	})
	assert.equal(exchangeRequestUrl(), '/apps/learniq/api/exchange/requests')
	assert.match(
		auditPackUrl('avg', '2026-01-01', '2026-06-30'),
		/regulationSlug=avg&dateFrom=2026-01-01&dateTo=2026-06-30$/,
	)
})

test('plan graph and goal helpers', () => {
	const graph = planGraph({
		id: 'p',
		kind: 'opp',
		goals: [{ goalId: 'g1', description: 'Read' }],
		supportMeasures: [{ measureId: 'm1', description: 'Extra time' }],
	})
	assert.equal(graph.nodes[0].isRoot, true)
	assert.deepEqual(graph.edges, [
		{ source: 'p', target: 'goal:g1' },
		{ source: 'p', target: 'measure:m1' },
	])
	assert.equal(nextGoalId([{ goalId: 'goal-2' }, { goalId: 'x' }]), 'goal-3')
	assert.deepEqual(moveItem(['a', 'b', 'c'], 2, -1), ['a', 'c', 'b'])
	assert.deepEqual(moveItem(['a', 'b'], 0, -1), ['a', 'b'])
})

test('signature records fit their append-only schemas', async () => {
	const { signatureBody, defaultSignerRole, SIGNABLE_SUBJECTS } =
		await import('../../src/utils/customPages.js')
	assert.deepEqual(
		SIGNABLE_SUBJECTS['learning-plan'].roles,
		schemas.Signature.properties.signerRole.enum,
	)
	for (const role of SIGNABLE_SUBJECTS.praktijkovereenkomst.roles) {
		assert.ok(schemas.PokSignature.properties.signerRole.enum.includes(role))
	}
	const plan = {
		id: 'p',
		version: 3,
		learnerId: 'lea',
		coordinatorId: 'co',
		tenant_id: 't',
	}
	assert.equal(defaultSignerRole('learning-plan', plan, 'co'), 'coordinator')
	const body = signatureBody({
		kind: 'learning-plan',
		subject: plan,
		signerId: 'co',
		signerRole: 'coordinator',
		capture: { mode: 'typed', value: 'C. Oordinator' },
		signedAt: '2026-09-27T10:00:00Z',
	})
	assertFitsSchema(body, 'Signature')
	assert.equal(body.subjectVersion, 3)
	const pok = signatureBody({
		kind: 'praktijkovereenkomst',
		subject: { id: 'k', version: 1 },
		signerId: 'lea',
		signerRole: 'student',
		capture: { mode: 'drawn', value: 'data:image/png;base64,AA' },
		signedAt: '2026-09-27T10:00:00Z',
	})
	assertFitsSchema(pok, 'PokSignature')
	assert.equal(pok.evidenceRef, 'drawn:data:image/png;base64,AA')
})

// @spec openspec/specs/bpv/spec.md#scenario-the-signing-flow-asks-for-the-parent
test('a parent or guardian can sign a work placement agreement, and the page says when one must', async () => {
	const { SIGNABLE_SUBJECTS, parentSignatureNeeded } =
		await import('../../src/utils/customPages.js')
	assert.ok(SIGNABLE_SUBJECTS.praktijkovereenkomst.roles.includes('parent'))
	assert.ok(schemas.PokSignature.properties.signerRole.enum.includes('parent'))
	assert.equal(
		parentSignatureNeeded('praktijkovereenkomst', {
			parentSignatureRequired: true,
		}),
		true,
	)
	assert.equal(
		parentSignatureNeeded('praktijkovereenkomst', {
			parentSignatureRequired: false,
		}),
		false,
	)
	assert.equal(parentSignatureNeeded('praktijkovereenkomst', {}), false)
	assert.equal(
		parentSignatureNeeded('learning-plan', { parentSignatureRequired: true }),
		false,
	)
})

// learniq#983 part C: a guard can not redirect submit to `late`, so the hand-in
// screen picks the transition itself; SubmissionWindowGuard refuses the wrong one.
test('handInAction picks submit inside the window and submitLate after it', () => {
	const now = new Date('2026-09-27T12:00:00Z')
	assert.equal(handInAction({ dueAt: null }, now), 'submit')
	assert.equal(handInAction({}, now), 'submit')
	assert.equal(handInAction({ dueAt: '2026-09-27T13:00:00Z' }, now), 'submit')
	assert.equal(handInAction({ dueAt: '2026-09-27T12:00:00Z' }, now), 'submit')
	assert.equal(
		handInAction(
			{ dueAt: '2026-09-27T11:00:00Z', allowLateSubmission: true },
			now,
		),
		'submitLate',
	)
})

test('handInAction keeps submit when late work is not accepted, so the guard refuses it with its reason', () => {
	const now = new Date('2026-09-27T12:00:00Z')
	assert.equal(
		handInAction(
			{ dueAt: '2026-09-27T11:00:00Z', allowLateSubmission: false },
			now,
		),
		'submit',
	)
	assert.equal(handInAction({ dueAt: '2026-09-27T11:00:00Z' }, now), 'submit')
})

test('handInAction names transitions the register declares on Submission', () => {
	const register = JSON.parse(
		readFileSync(resolve(root, 'lib/Settings/learniq_register.json'), 'utf8'),
	)
	const transitions =
		register.components.schemas.Submission['x-openregister-lifecycle']
			.transitions
	assert.equal(transitions.submit.to, 'submitted')
	assert.equal(transitions.submitLate.to, 'late')
})
