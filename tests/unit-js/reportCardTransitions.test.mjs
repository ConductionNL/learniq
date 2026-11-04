// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The report card review and the compose dialog change a lifecycle through
// OpenRegister's transition endpoint, never by writing `lifecycle`: only a
// transition fires ObjectTransitionedEvent, which ReportCardComposer (compose
// builds the cards), ReportCardPublishHandler, the reportCardPublished
// notification and ReportCardPdfTransitionListener listen to.
//
// @spec openspec/specs/report-card/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

/**
 * A source file of this app.
 *
 * @param {string} path Path from the app root.
 * @return {string} The file contents.
 */
function read(path) {
	return readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')
}

const register = JSON.parse(read('lib/Settings/learniq_register.json'))

/**
 * The declared transitions of a schema.
 *
 * @param {string} slug The schema slug.
 * @return {object} Transitions by action.
 */
function lifecycleOf(slug) {
	return Object.values(register.components.schemas)
		.find((schema) => schema.slug === slug)['x-openregister-lifecycle'].transitions
}

/**
 * The transition actions a source posts, from `transition(card, '<action>')`
 * calls and `{ action: '<action>' }` bodies.
 *
 * @param {string} source The component source.
 * @return {string[]} The action names.
 */
function postedActions(source) {
	const calls = [...source.matchAll(/transition\(\s*card,\s*'([^']+)',?\s*\)/g)].map((m) => m[1])
	const bodies = [...source.matchAll(/action:\s*'([^']+)'/g)].map((m) => m[1])
	return [...calls, ...bodies]
}

test('the review and the compose dialog never write lifecycle directly', () => {
	for (const path of ['src/views/RapportvergaderingReviewView.vue', 'src/dialogs/ComposeReportPeriodModal.vue']) {
		const source = read(path)
		assert.doesNotMatch(source, /lifecycle:\s*(toLifecycle|'composed')/, path)
		assert.match(source, /transitionUrl\(/, `${path} does not post to the transition endpoint`)
	}
})

test('every review button posts a ReportCard transition the register declares', () => {
	const transitions = lifecycleOf('report-card')
	const actions = postedActions(read('src/views/RapportvergaderingReviewView.vue'))
	assert.deepEqual(
		[...new Set(actions)].sort(),
		['finalise', 'publishToParents', 'pullIntoReview', 'reopen'],
	)
	for (const action of actions) {
		assert.ok(transitions[action], `ReportCard declares no ${action} transition`)
	}
})

test('the compose dialog posts the ReportPeriod compose transition', () => {
	const transitions = lifecycleOf('report-period')
	assert.deepEqual(postedActions(read('src/dialogs/ComposeReportPeriodModal.vue')), ['compose'])
	assert.equal(transitions.compose.to, 'composed')
})
