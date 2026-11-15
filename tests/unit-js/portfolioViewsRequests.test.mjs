// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The requests of the portfolio screens, pinned call by call: the learner's
// PortfolioBuilder (load, evidence picker, add entry, submit) and the
// teacher's PortfolioReviewView (load, references, grade). A missing
// portfolio is input and stays out of the console; every other failure is
// logged.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).
//
// @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview

import assert from 'node:assert/strict'
import { afterEach, beforeEach, mock, test } from 'node:test'
import { transitionUrl } from '../../src/utils/customPages.js'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const STUBS = {
	'@nextcloud/router': { generateUrl: (url) => `/nc${url}` },
	'@nextcloud/auth': { getCurrentUser: () => ({ uid: 'u1' }) },
}
const READ = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }
const WRITE = { 'OCS-APIREQUEST': 'true', Accept: 'application/json', 'Content-Type': 'application/json' }
const OBJECTS = '/nc/apps/openregister/api/objects/learniq'

let calls
let answers
let errors

beforeEach(() => {
	calls = []
	answers = []
	errors = []
	globalThis.fetch = async (url, init) => {
		calls.push({ url, method: init?.method, headers: init?.headers, body: init?.body === undefined ? undefined : JSON.parse(init.body) })
		const answer = answers.find((a) => url.includes(a.part) && (a.method === undefined || a.method === init?.method)) ?? { status: 200, json: {} }
		return { ok: answer.status >= 200 && answer.status < 300, status: answer.status, json: async () => answer.json }
	}
	mock.method(console, 'error', (...args) => errors.push(args))
})

afterEach(() => {
	mock.restoreAll()
	delete globalThis.fetch
})

const when = (part, status, json, method) => ({ part, status, json, method })

test('the builder loads the portfolio, its template, its entries and the picker', async () => {
	const vm = instanceOf(await loadSfc('src/views/PortfolioBuilder.vue', STUBS), { id: 'pf1' })
	answers = [
		when('Portfolio/pf1', 200, { object: { id: 'pf1', templateId: 't1' } }),
		when('portfolio-template/t1', 200, { id: 't1' }),
		when('portfolio-entry?', 200, { results: [{ id: 'e1' }] }),
		when('Submission?', 200, { objects: [{ id: 's1', assignmentId: 'a1', feedbackText: 'ok' }, { id: 's2' }, { id: 's3', assignmentId: 'a3' }] }),
	]
	await vm.loadData('pf1')
	assert.deepEqual(calls.map((c) => [c.url, c.headers]), [
		[`${OBJECTS}/Portfolio/pf1`, READ],
		[`${OBJECTS}/portfolio-template/t1`, READ],
		[`${OBJECTS}/portfolio-entry?portfolioId=pf1&_limit=100`, READ],
		[`${OBJECTS}/Submission?learnerIds=u1&_limit=100`, READ],
	])
	assert.deepEqual(vm.template, { id: 't1' })
	assert.deepEqual(vm.entries, [{ id: 'e1' }])
	assert.deepEqual(vm.pickerOptions, [{ id: 's1', label: 'a1 — ok' }, { id: 's2', label: 's2' }, { id: 's3', label: 'a3' }])
	assert.equal(vm.loading, false)
	assert.equal(vm.error, null)
})

test('a missing portfolio is said, not logged; any other failure is logged', async () => {
	const vm = instanceOf(await loadSfc('src/views/PortfolioBuilder.vue', STUBS), { id: 'pf1' })
	answers = [when('Portfolio/pf1', 404, null)]
	await vm.loadData('pf1')
	assert.equal(vm.error, 'This portfolio no longer exists, or you do not have access to it.')
	assert.deepEqual(errors, [])
	answers = [when('Portfolio/pf1', 500, null)]
	await vm.loadData('pf1')
	assert.equal(vm.error, 'Failed to load portfolio. Please try again.')
	assert.equal(errors[0][0], '[PortfolioBuilder] loadData error')
	assert.equal(errors[0][1].message, 'Portfolio fetch failed: 500')
	assert.equal(errors[0][1].status, 500)
	assert.equal(errors[0][1].notFound, false)
	answers = [when('Portfolio/pf1', 200, {}), when('portfolio-entry', 500, null), when('Submission', 403, null)]
	calls = []
	await vm.loadData('pf1')
	assert.deepEqual(vm.entries, [])
	assert.deepEqual(vm.pickerOptions, [])
	assert.equal(calls.length, 3)
})

test('the evidence picker lists the learner\'s own records of each kind', async () => {
	const vm = instanceOf(await loadSfc('src/views/PortfolioBuilder.vue', STUBS), { id: 'pf1' })
	const cases = [
		['werkproces-assessment', 'werkproces-assessment?learnerId=u1', [{ id: 'w1', werkprocesLabel: 'L' }, { id: 'w2', werkprocesCode: 'C' }, { id: 'w3' }], ['L', 'C', 'w3']],
		['external-training-record', 'external-training-record?learnerUserId=u1', [{ id: 'x1', title: 'T' }, { id: 'x2', providerName: 'P' }, { id: 'x3' }], ['T', 'P', 'x3']],
		['credential', 'Credential?learnerUserId=u1', [{ id: 'c1', title: 'T' }, { id: 'c2' }], ['T', 'c2']],
	]
	for (const [kind, path, rows, labels] of cases) {
		calls = []
		vm.newEntry.evidenceKind = kind
		answers = [when(path, 200, rows)]
		await vm.loadPickerOptions()
		assert.deepEqual(calls.map((c) => c.url), [`${OBJECTS}/${path}&_limit=100`])
		assert.deepEqual(vm.pickerOptions.map((o) => o.label), labels)
	}
	for (const kind of ['reflection', 'file']) {
		calls = []
		vm.pickerOptions = ['stale']
		vm.newEntry.evidenceKind = kind
		await vm.loadPickerOptions()
		assert.deepEqual(calls, [])
		assert.deepEqual(vm.pickerOptions, [])
	}
	globalThis.fetch = async () => {
		throw new Error('offline')
	}
	vm.newEntry.evidenceKind = 'credential'
	vm.pickerOptions = ['stale']
	await vm.loadPickerOptions()
	assert.deepEqual(vm.pickerOptions, [])
	assert.equal(errors[0][0], '[PortfolioBuilder] loadPickerOptions error')
})

test('an evidence entry carries the field of its kind, and the form resets after it is added', async () => {
	const vm = instanceOf(await loadSfc('src/views/PortfolioBuilder.vue', STUBS), { id: 'pf1' })
	vm.portfolio = { id: 'pf1', tenant_id: 't9' }
	const cases = [
		['reflection', { reflectionText: 'I learned' }],
		['submission', { submissionId: 'ref' }],
		['werkproces-assessment', { werkprocesAssessmentId: 'ref' }],
		['external-training-record', { externalTrainingRecordId: 'ref' }],
		['credential', { credentialId: 'ref' }],
		['file', {}],
	]
	for (const [kind, extra] of cases) {
		calls = []
		vm.newEntry = { evidenceKind: kind, referenceId: 'ref', title: 'T', sectionId: kind === 'file' ? 'sec' : '', reflectionText: 'I learned' }
		await vm.addEntry()
		assert.deepEqual(calls[0], {
			url: `${OBJECTS}/portfolio-entry`,
			method: 'POST',
			headers: WRITE,
			body: { portfolioId: 'pf1', learnerId: 'u1', title: 'T', evidenceKind: kind, sectionId: kind === 'file' ? 'sec' : null, tenant_id: 't9', ...extra },
		})
		assert.deepEqual(calls.slice(1).map((c) => c.url), [`${OBJECTS}/portfolio-entry?portfolioId=pf1&_limit=100`, `${OBJECTS}/Submission?learnerIds=u1&_limit=100`])
		assert.deepEqual(vm.newEntry, { evidenceKind: 'submission', referenceId: '', title: '', sectionId: '', reflectionText: '' })
		assert.equal(vm.addingEntry, false)
	}
	vm.portfolio = { id: 'pf1' }
	vm.newEntry = { evidenceKind: 'credential', referenceId: 'c', title: 'T', sectionId: '', reflectionText: '' }
	answers = [when('portfolio-entry', 400, null, 'POST')]
	calls = []
	await vm.addEntry()
	assert.equal(calls.length, 1)
	assert.equal(calls[0].body.tenant_id, '')
	assert.equal(vm.addEntryError, 'Failed to add evidence entry. Please try again.')
	assert.equal(errors[0][0], '[PortfolioBuilder] addEntry error')
	assert.equal(errors[0][1].message, 'PortfolioEntry create failed: 400')
	assert.equal(vm.newEntry.evidenceKind, 'credential')
	vm.portfolio = null
	calls = []
	await vm.addEntry()
	assert.deepEqual(calls, [])
})

test('submitting a portfolio is refused for missing sections and reloads it when accepted', async () => {
	const vm = instanceOf(await loadSfc('src/views/PortfolioBuilder.vue', STUBS), { id: 'pf1' })
	answers = [when('Portfolio/pf1', 200, { object: { id: 'pf1', lifecycle: 'submitted' } })]
	await vm.submitPortfolio()
	assert.deepEqual(calls.map((c) => [c.url, c.method, c.headers, c.body]), [
		[`/nc${transitionUrl('pf1')}`, 'POST', WRITE, { action: 'submit' }],
		[`${OBJECTS}/Portfolio/pf1`, undefined, READ, undefined],
	])
	assert.equal(vm.submitted, true)
	assert.deepEqual(vm.portfolio, { id: 'pf1', lifecycle: 'submitted' })
	assert.equal(vm.submitting, false)
	vm.submitted = false
	answers = [when('transition', 422, {})]
	calls = []
	await vm.submitPortfolio()
	assert.equal(calls.length, 1)
	assert.equal(vm.submitError, 'Submission refused: every required section needs at least one evidence entry.')
	assert.deepEqual(errors, [])
	answers = [when('transition', 500, {})]
	await vm.submitPortfolio()
	assert.equal(vm.submitError, 'Failed to submit portfolio. Please try again.')
	assert.equal(errors[0][1].message, 'Portfolio submit transition failed: 500')
	assert.equal(vm.submitted, false)
	assert.equal(vm.submitting, false)
})

test('the review loads the portfolio and names each entry by what it refers to', async () => {
	const vm = instanceOf(await loadSfc('src/views/PortfolioReviewView.vue', STUBS), { id: 'pf1' })
	answers = [
		when('Portfolio/pf1', 200, { object: { id: 'pf1', gradeValue: 7 } }),
		when('portfolio-entry?', 200, [
			{ id: 'e1', evidenceKind: 'submission', submissionId: 's1' },
			{ id: 'e2', evidenceKind: 'reflection' },
			{ id: 'e3', evidenceKind: 'credential', credentialId: '' },
			{ id: 'e4', evidenceKind: 'credential', credentialId: 'c1' },
		]),
		when('Submission/s1', 200, { feedbackText: 'Fb' }),
		when('Credential/c1', 500, null),
	]
	await vm.loadData('pf1')
	assert.equal(vm.gradeValue, 7)
	assert.deepEqual(calls.map((c) => c.url), [
		`${OBJECTS}/Portfolio/pf1`,
		`${OBJECTS}/portfolio-entry?portfolioId=pf1&_limit=100`,
		`${OBJECTS}/Submission/s1`,
		`${OBJECTS}/Credential/c1`,
	])
	assert.deepEqual(vm.resolvedReferences, { e1: 'Fb' })
	assert.equal(errors[0][0], '[PortfolioReviewView] resolveEntryReferences error')
	assert.equal(errors[0][1].message, 'Credential fetch failed: 500')
	answers = [when('Portfolio/pf1', 404, null)]
	errors.length = 0
	await vm.loadData('pf1')
	assert.equal(vm.error, 'This portfolio no longer exists, or you do not have access to it.')
	assert.deepEqual(errors, [])
	answers = [when('Portfolio/pf1', 401, null)]
	await vm.loadData('pf1')
	assert.equal(vm.error, 'Failed to load portfolio. Please try again.')
	assert.equal(errors[0][0], '[PortfolioReviewView] loadData error')
	assert.equal(vm.loading, false)
})

test('grading a portfolio patches the grade, transitions it and reloads it', async () => {
	const vm = instanceOf(await loadSfc('src/views/PortfolioReviewView.vue', STUBS), { id: 'pf1' })
	vm.portfolio = { id: 'pf1' }
	vm.gradeValue = '8.5'
	answers = [when('Portfolio/pf1', 200, { id: 'pf1', lifecycle: 'graded' })]
	await vm.gradePortfolio()
	assert.deepEqual(calls.map((c) => [c.url, c.method, c.headers, c.body]), [
		[`${OBJECTS}/Portfolio/pf1`, 'PATCH', WRITE, { gradeValue: 8.5 }],
		[`/nc${transitionUrl('pf1')}`, 'POST', WRITE, { action: 'grade' }],
		[`${OBJECTS}/Portfolio/pf1`, undefined, READ, undefined],
	])
	assert.deepEqual(vm.portfolio, { id: 'pf1', lifecycle: 'graded' })
	assert.equal(vm.grading, false)
	for (const [answer, message] of [[when('Portfolio/pf1', 500, null, 'PATCH'), 'Portfolio update failed: 500'], [when('transition', 409, null), 'Portfolio grade transition failed: 409']]) {
		answers = [answer]
		await vm.gradePortfolio()
		assert.equal(vm.gradeError, 'Failed to save grade. Please try again.')
		assert.equal(errors.at(-1)[0], '[PortfolioReviewView] gradePortfolio error')
		assert.equal(errors.at(-1)[1].message, message)
	}
	vm.portfolio = null
	calls = []
	await vm.gradePortfolio()
	assert.deepEqual(calls, [])
})

test('both screens name the evidence kinds the same way', async () => {
	for (const file of ['src/views/PortfolioBuilder.vue', 'src/views/PortfolioReviewView.vue']) {
		const vm = instanceOf(await loadSfc(file, STUBS), { id: 'pf1' })
		assert.deepEqual(
			['file', 'submission', 'werkproces-assessment', 'external-training-record', 'credential', 'reflection', 'other'].map((kind) => vm.evidenceKindLabel(kind)),
			['File', 'Submission', 'Werkproces assessment', 'External training record', 'Credential', 'Reflection', 'other'],
		)
	}
})
