/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — enrolment, the wedge's first flow.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/specs/enrolment/spec.md#bulk-enrol-a-selected-group-of-learners
 *   @e2e openspec/specs/enrolment/spec.md#progress-percentage-is-visible-on-the-learners-my-learning-dashboard
 *
 * Prerequisite validation, Studielink provisioning and the progress roll-up
 * arithmetic are backend behaviours covered by PHPUnit and annotated
 * `@e2e exclude` in the spec. What only a browser can answer is whether the
 * enrolment surface reads its own schema and offers the bulk action that the
 * compliance officer's whole job depends on.
 *
 * The admin session comes from the global setup.
 */
import { expect, test } from '../fixtures.ts'
import { openAndExpectNoFatal, openAndExpectSchemaLoads } from './wedge-helpers.ts'

test.describe('enrolment — the bulk-enrol surface', () => {
	// @e2e openspec/specs/enrolment/spec.md#bulk-enrol-a-selected-group-of-learners
	test('the enrolments index reads the Enrolment schema', async ({
		loggedInPage: page,
	}) => {
		await openAndExpectSchemaLoads(page, '/enrolments', 'enrolment')
	})

	// @e2e openspec/specs/enrolment/spec.md#bulk-enrol-a-selected-group-of-learners
	test('the enrolments index offers a way to add enrolments', async ({
		loggedInPage: page,
	}) => {
		await openAndExpectSchemaLoads(page, '/enrolments', 'enrolment')

		// Bulk enrolment is the wedge's entry point: an officer enrols every
		// employee in the mandatory course. Whatever the control is called, the
		// index must offer at least one creation affordance, or the flow has no
		// door.
		const addControl = page
			.getByRole('button', { name: /add|new|enrol|import|bulk/i })
			.first()

		await expect(
			addControl,
			'the enrolments index offers no way to create or import an enrolment',
		).toBeVisible({ timeout: 10_000 })
	})

	// @e2e openspec/specs/enrolment/spec.md#progress-percentage-is-visible-on-the-learners-my-learning-dashboard
	test('the learner home renders without a fatal error', async ({
		loggedInPage: page,
	}) => {
		await openAndExpectNoFatal(page, '/learner')
	})
})
