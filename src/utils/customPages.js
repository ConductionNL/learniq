// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for the custom pages that used to open empty (learniq#947).
 *
 * The views under src/views/ load OpenRegister objects and post back through
 * these builders, so the shapes they write are tested under `node --test`
 * without an SFC compile step. URLs are app-relative; the views pass them
 * through `generateUrl`.
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */

/** OpenRegister's object API for the learniq register. */
export const OBJECTS = '/apps/openregister/api/objects/learniq'

/**
 * The URL of a schema's collection, or of one object in it.
 *
 * @param {string} slug Schema slug.
 * @param {string} [id] Object UUID.
 * @return {string} The app-relative URL.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function objectsUrl(slug, id = '') {
	return id ? `${OBJECTS}/${slug}/${encodeURIComponent(id)}` : `${OBJECTS}/${slug}`
}

/**
 * The URL of OpenRegister's transition endpoint for one object. The
 * transition name goes in the body as `{ action }`; a URL of the shape
 * `/objects/learniq/<schema>/<id>/transition/<name>` is not a route.
 *
 * @param {string} id Object UUID.
 * @return {string} The app-relative URL (POST).
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function transitionUrl(id) {
	return `/apps/openregister/api/objects/${encodeURIComponent(id)}/transition`
}

/**
 * The hand-in transition for an assignment right now. After the deadline of an
 * assignment that accepts late work it is `submitLate` (draft to late); in every
 * other case `submit`, which SubmissionWindowGuard refuses with its reason when
 * the deadline has passed (learniq#983).
 *
 * @param {object} assignment The assignment (dueAt, allowLateSubmission).
 * @param {Date} [now] The moment to judge, defaults to the current time.
 * @return {string} `submit` or `submitLate`.
 * @spec openspec/specs/assignments/spec.md#requirement-a-learner-hands-in-their-own-work-and-the-teacher-marks-it
 */
export function handInAction(assignment, now = new Date()) {
	const due = assignment?.dueAt ? new Date(assignment.dueAt) : null
	if (!due || Number.isNaN(due.getTime()) || due >= now) return 'submit'
	return assignment.allowLateSubmission === true ? 'submitLate' : 'submit'
}

/**
 * Read the rows out of an OpenRegister list response body.
 *
 * @param {object} body The response body.
 * @return {object[]} The rows.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function listRows(body) {
	if (Array.isArray(body)) return body
	return (body && (body.results || body.objects)) || []
}

/**
 * Read one object out of an OpenRegister single-object response body.
 *
 * @param {object} body The response body.
 * @return {object} The object.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function oneObject(body) {
	return (body && (body.object || body)) || {}
}

/**
 * An object's id, whichever key OpenRegister returned it under.
 *
 * @param {object} object An OpenRegister object.
 * @return {string} The UUID, or ''.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function objectId(object) {
	return String(object?.id ?? object?.uuid ?? object?.['@self']?.id ?? '')
}

/**
 * The gradebook grid: one row per learner, one column per plan component,
 * cells holding the learner's latest concept or published mark.
 *
 * @param {string[]} learnerIds Cohort.learnerIds.
 * @param {object[]} components CurriculumPlan.components.
 * @param {object[]} entries GradeEntries for the plan and cohort.
 * @return {{columns: object[], rows: object[], entryIndex: Record<string, object>}} Grid.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function gradebookGrid(learnerIds, components, entries) {
	const columns = components.map((c) => ({
		key: c.componentId,
		label: c.label || c.componentId,
		type: 'number',
		aggregate: 'avg',
	}))
	const entryIndex = {}
	for (const entry of entries) {
		if (entry.lifecycle === 'invalidated' || entry.lifecycle === 'revised')
			continue
		entryIndex[`${entry.learnerId}|${entry.componentId}`] = entry
	}
	const rows = [...new Set(learnerIds)].map((learnerId) => {
		const row = { id: learnerId, label: learnerId }
		for (const c of components) {
			const entry = entryIndex[`${learnerId}|${c.componentId}`]
			row[c.componentId] =
				entry && entry.value !== null && entry.value !== undefined
					? Number(entry.value)
					: ''
		}
		return row
	})
	return { columns, rows, entryIndex }
}

/**
 * The concept GradeEntry body for a cell typed in the gradebook.
 *
 * @param {object} args Arguments.
 * @param {string} args.learnerId Learner.
 * @param {string} args.componentId Plan component.
 * @param {number} args.value The mark.
 * @param {object} args.plan The CurriculumPlan.
 * @param {string} args.cohortId The Cohort.
 * @param {string} args.grader Nextcloud user id of the teacher.
 * @param {string} args.gradedAt ISO date-time.
 * @return {object} The GradeEntry body.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function gradeEntryBody({
	learnerId,
	componentId,
	value,
	plan,
	cohortId,
	grader,
	gradedAt,
}) {
	const component =
		(plan.components ?? []).find((c) => c.componentId === componentId) ?? {}
	return {
		learnerId,
		curriculumPlanId: objectId(plan),
		componentId,
		cohortId,
		gradeScaleId: component.gradeScaleId ?? plan.gradeScaleId ?? '',
		sourceKind: 'manual',
		value,
		weight: component.weight ?? null,
		period: component.period ?? null,
		grader,
		gradedAt,
		tenant_id: plan.tenant_id ?? '',
	}
}

/**
 * Parse a typed mark. Empty or non-numeric input is null.
 *
 * @param {unknown} raw The typed value.
 * @return {number|null} The mark.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function parseMark(raw) {
	const text = String(raw ?? '')
		.trim()
		.replace(',', '.')
	if (text === '') return null
	const value = Number(text)
	return Number.isFinite(value) ? value : null
}

/**
 * CnRelationshipGraph nodes and edges for a learning plan: the plan at the
 * centre, one node per goal and one per support measure, each measure linked
 * to the plan.
 *
 * @param {object} plan The LearningPlan.
 * @return {{nodes: object[], edges: object[]}} Graph.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function planGraph(plan) {
	const rootId = objectId(plan) || 'plan'
	const nodes = [
		{
			id: rootId,
			label: plan.kind ? String(plan.kind).toUpperCase() : 'Plan',
			isRoot: true,
		},
	]
	const edges = []
	for (const goal of plan.goals ?? []) {
		nodes.push({
			id: `goal:${goal.goalId}`,
			label: goal.description,
			kind: 'goal',
		})
		edges.push({ source: rootId, target: `goal:${goal.goalId}` })
	}
	for (const measure of plan.supportMeasures ?? []) {
		nodes.push({
			id: `measure:${measure.measureId}`,
			label: measure.description,
			kind: 'measure',
		})
		edges.push({ source: rootId, target: `measure:${measure.measureId}` })
	}
	return { nodes, edges }
}

/**
 * A new goal id that does not clash with the plan's existing goals.
 *
 * @param {object[]} goals Existing goals.
 * @return {string} The id.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function nextGoalId(goals) {
	const taken = new Set((goals ?? []).map((g) => g.goalId))
	let n = (goals ?? []).length + 1
	while (taken.has(`goal-${n}`)) n++
	return `goal-${n}`
}

/**
 * Move one item of a list up or down, returning a new list.
 *
 * @param {Array} list The list.
 * @param {number} index Index of the item.
 * @param {number} delta -1 for up, 1 for down.
 * @return {Array} The reordered list.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function moveItem(list, index, delta) {
	const target = index + delta
	if (target < 0 || target >= list.length) return [...list]
	const copy = [...list]
	const [item] = copy.splice(index, 1)
	copy.splice(target, 0, item)
	return copy
}

/**
 * The body of an exchange request learniq forwards to integriq
 * (POST /api/exchange/requests, data-exchange-to-integriq). Learniq picks the
 * schema and the mapping for the target; the screen only names the target and,
 * optionally, one learner.
 *
 * @param {object} args Arguments.
 * @param {string} args.target Exchange target, such as 'bron-rod'.
 * @param {string} [args.learnerId] One learner's user id, or '' for everyone.
 * @return {object} The request body.
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */
export function exchangeRequestBody({ target, learnerId }) {
	const body = { target }
	const learner = (learnerId ?? '').trim()
	if (learner !== '') body.learnerId = learner
	return body
}

/**
 * The exchange request URL.
 *
 * @return {string} The app-relative URL (POST).
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */
export function exchangeRequestUrl() {
	return '/apps/learniq/api/exchange/requests'
}

/**
 * The audit-pack export URL for a regulation and a date range.
 *
 * @param {string} regulationSlug Regulation slug.
 * @param {string} dateFrom YYYY-MM-DD.
 * @param {string} dateTo YYYY-MM-DD.
 * @return {string} The app-relative URL (POST).
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function auditPackUrl(regulationSlug, dateFrom, dateTo) {
	const q = new URLSearchParams({ regulationSlug, dateFrom, dateTo })
	return `/apps/learniq/api/compliance/audit/export?${q.toString()}`
}

/**
 * The course-package export URL.
 *
 * @param {string} courseId Course UUID.
 * @param {string} format 'common-cartridge' or 'scholiq-json'.
 * @return {string} The app-relative URL (GET).
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function coursePackageUrl(courseId, format) {
	const q = new URLSearchParams({ courseId, format })
	return `/apps/learniq/api/course-management/course-package-export?${q.toString()}`
}

/**
 * The course-package share URL: the package meant to leave the school.
 *
 * @return {string} The app-relative URL (POST).
 * @spec openspec/specs/course-management/spec.md#requirement-the-export-page-offers-sharing-with-the-confirmations
 */
export function coursePackageShareUrl() {
	return '/apps/learniq/api/course-management/course-package-share'
}

/**
 * The course store publish URL.
 *
 * @return {string} The app-relative URL (POST).
 * @spec openspec/specs/course-management/spec.md#requirement-publishing-sends-a-gated-package-to-the-registry
 */
export function coursePackagePublishUrl() {
	return '/apps/learniq/api/store/publish'
}

/** What each signable subject needs: its schema, its sign endpoint and the roles that sign in learniq. */
export const SIGNABLE_SUBJECTS = {
	'learning-plan': {
		subjectSchema: 'learning-plan',
		endpoint: 'learning-plan',
		roles: ['learner', 'parent', 'coordinator', 'teacher'],
	},
	praktijkovereenkomst: {
		subjectSchema: 'praktijkovereenkomst',
		endpoint: 'placement-agreement',
		// The praktijkopleider signs through the portal (portaliq), not here.
		// A parent or guardian signs a minor's agreement (pok-signature-parent-role).
		roles: ['student', 'school', 'parent'],
	},
}

/**
 * Whether the signing page says that a parent or guardian also signs.
 * The server sets `parentSignatureRequired` on a praktijkovereenkomst when
 * the student is under 18 or has no date of birth recorded; activation
 * checks it again on its own.
 *
 * @param {string} kind 'learning-plan' or 'praktijkovereenkomst'.
 * @param {object} subject The signed object.
 * @return {boolean} True for a praktijkovereenkomst that needs a parent's signature.
 * @spec openspec/specs/bpv/spec.md#scenario-the-signing-flow-asks-for-the-parent
 */
export function parentSignatureNeeded(kind, subject) {
	return (
		kind === 'praktijkovereenkomst' && subject?.parentSignatureRequired === true
	)
}

/**
 * The sign endpoint of a record. GET answers the roles the signed-in user
 * holds on it; POST `{role, capture}` records the signature, with signer,
 * time, version and tenant set by the server.
 *
 * @param {string} kind 'learning-plan' or 'praktijkovereenkomst'.
 * @param {string} id The record uuid.
 * @return {string} The app-relative URL.
 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
 */
export function signUrl(kind, id) {
	const endpoint = (SIGNABLE_SUBJECTS[kind] ?? SIGNABLE_SUBJECTS['learning-plan'])
		.endpoint
	return `/apps/learniq/api/sign/${endpoint}/${encodeURIComponent(id)}`
}

