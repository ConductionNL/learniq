/**
 * The school-year rollover wizard's actions, kept out of the view so a Node
 * test can run them.
 *
 * Executing is irreversible, so it asks first, and it goes through
 * OpenRegister's transition endpoint: RolloverExecutionHandler listens to the
 * `execute` transition, which a PATCH of the lifecycle field never fires.
 *
 * Plain ES module so a Node test can import it without a build step.
 *
 * @spec openspec/specs/school-year-rollover/spec.md#requirement-execution-must-promote-by-creating-new-cohorts-and-archiving-old-ones
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** The cohort actions a mapping can take, as the schema stores them. */
export const COHORT_ACTIONS = ['promote', 'graduate', 'dissolve']

/**
 * The cohort actions as select options.
 *
 * @param {(text: string) => string} t Translates.
 * @return {Array<{id: string, label: string}>}
 * @spec openspec/specs/school-year-rollover/spec.md#requirement-execution-must-promote-by-creating-new-cohorts-and-archiving-old-ones
 */
export function actionOptions(t) {
	const labels = { promote: t('Promote'), graduate: t('Graduate'), dissolve: t('Dissolve') }
	return COHORT_ACTIONS.map((id) => ({ id, label: labels[id] }))
}

/**
 * The transition address of a plan.
 *
 * @param {string} planId The plan's id.
 * @return {string} The path, before generateUrl.
 * @spec openspec/specs/school-year-rollover/spec.md#requirement-execution-must-promote-by-creating-new-cohorts-and-archiving-old-ones
 */
export function transitionUrl(planId) {
	return `/apps/openregister/api/objects/${encodeURIComponent(planId)}/transition`
}

/**
 * The server's reason for a refusal, else the fallback.
 *
 * @param {unknown} error The rejected request.
 * @param {string} fallback What to say without a reason.
 * @return {string}
 * @spec openspec/specs/school-year-rollover/spec.md#requirement-preview-must-be-side-effect-free-and-produce-a-complete-dry-run-report
 */
export function refusal(error, fallback) {
	const data = error?.response?.data
	for (const key of ['message', 'error']) {
		if (typeof data?.[key] === 'string' && data[key].trim() !== '' && data[key].length <= 400) {
			return data[key].trim()
		}
	}
	return fallback
}

/**
 * Ask, then start the rollover through the `execute` transition.
 *
 * @param {object} plan The plan: `{ id, fromAcademicYear, toAcademicYear }`.
 * @param {object} deps Seams.
 * @param {(text: string) => Promise<boolean>} deps.confirm Asks the admin; true to go on.
 * @param {(url: string, body: object) => Promise<object>} deps.post Posts.
 * @param {(text: string, vars?: object) => string} deps.t Translates.
 * @return {Promise<{status: 'cancelled'|'started'|'failed', message: string}>}
 * @spec openspec/specs/school-year-rollover/spec.md#requirement-execution-must-promote-by-creating-new-cohorts-and-archiving-old-ones
 */
export async function executeRollover(plan, { confirm, post, t }) {
	const question = t('Run the rollover from {from} to {to}? It creates the new groups and moves the enrolments. You cannot undo it.', {
		from: plan.fromAcademicYear || '?',
		to: plan.toAcademicYear || '?',
	})
	// Closing the question rejects in some @nextcloud/dialogs versions; that is a no.
	const confirmed = await Promise.resolve()
		.then(() => confirm(question))
		.catch(() => false)
	if (confirmed !== true) {
		return { status: 'cancelled', message: '' }
	}
	try {
		await post(transitionUrl(plan.id), { action: 'execute' })
		return { status: 'started', message: t('The rollover runs now. You get a notification when it is done.') }
	} catch (error) {
		return { status: 'failed', message: refusal(error, t('The rollover did not start.')) }
	}
}
