// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Preview as learner and next step rules (content-adaptive-next-step-and-preview).
// Pure helpers, no Vue and no Nextcloud imports, so node --test covers them.
//
// A preview is a mode of the real lesson player (design D2): the route query
// carries `preview=1` and the simulated score. The player makes no write in a
// preview, and every write it would make still carries PREVIEW_HEADER, so the
// server refuses it too (PreviewWriteGuard).

/**
 * The request header the server's PreviewWriteGuard refuses writes on.
 *
 * @type {string}
 */
export const PREVIEW_HEADER = 'X-Learniq-Preview'

/**
 * The kinds a next step rule's condition can have: the release condition
 * kinds plus score-below (design D1).
 *
 * @type {string[]}
 */
export const RULE_KINDS = ['score-below', 'assessment-min-score', 'lesson-completed']

/**
 * Read the preview state from a route query.
 *
 * @param {object|undefined} query The route query.
 * @return {{active: boolean, score: number|null}} Whether this is a preview, and its simulated score.
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-teacher-walks-the-course-as-a-learner
 */
export function previewFromQuery(query) {
	const active = String(query?.preview ?? '') === '1'
	const raw = query?.score
	const score =
		raw === undefined || raw === null || raw === '' || Number.isNaN(Number(raw))
			? null
			: Number(raw)
	return { active, score: active ? score : null }
}

/**
 * The route query that keeps a preview going to the next lesson.
 *
 * @param {{active: boolean, score: number|null}} preview The preview state.
 * @return {object} The query, empty outside a preview.
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#requirement-preview-as-learner
 */
export function previewQuery(preview) {
	if (!preview?.active) return {}
	const query = { preview: '1' }
	if (preview.score !== null && preview.score !== undefined)
		query.score = String(preview.score)
	return query
}

/**
 * Headers for a write the player makes, marked when in a preview.
 *
 * @param {{active: boolean}} preview The preview state.
 * @param {object} headers The write's own headers.
 * @return {object} The headers to send.
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-preview-leaves-no-records
 */
export function writeHeaders(preview, headers = {}) {
	if (!preview?.active) return { ...headers }
	return { ...headers, [PREVIEW_HEADER]: '1' }
}

/**
 * The path (before generateUrl) and query of the next step request.
 *
 * @param {string} lessonId The lesson.
 * @param {{active: boolean, score: number|null}} preview The preview state.
 * @return {string} The app-relative path with its query.
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
 */
export function nextStepPath(lessonId, preview) {
	const query = new URLSearchParams(previewQuery(preview)).toString()
	const path =
		'/apps/learniq/api/lessons/' + encodeURIComponent(lessonId) + '/next-step'
	return query ? path + '?' + query : path
}

/**
 * A new, empty rule for the editor.
 *
 * @return {object} The rule.
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
 */
export function emptyRule() {
	return {
		when: { kind: 'score-below', assessmentId: null, belowScore: null },
		goToLessonId: null,
	}
}

/**
 * Stored rules as the editor edits them: every rule with a `when` holding
 * every field, so a half-written stored rule never breaks the form.
 *
 * @param {Array<object>|undefined} rules The stored rules.
 * @return {Array<object>} A working copy.
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
 */
export function editableRules(rules) {
	return (Array.isArray(rules) ? rules : []).map((rule) => ({
		when: {
			...emptyRule().when,
			lessonId: null,
			minScore: null,
			...(rule?.when ?? {}),
		},
		goToLessonId: rule?.goToLessonId ?? null,
	}))
}

/**
 * Normalise the editor's rules into what the Lesson schema stores: only the
 * fields the rule's kind uses, and no rule without a target.
 *
 * @param {Array<object>} rules The rules as edited.
 * @return {Array<object>} The rules to save.
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
 */
export function serialiseRules(rules) {
	const out = []
	for (const rule of rules ?? []) {
		const kind = rule?.when?.kind
		if (!rule?.goToLessonId || !RULE_KINDS.includes(kind)) continue
		const when = { kind }
		if (kind === 'lesson-completed') {
			when.lessonId = rule.when.lessonId ?? null
		} else {
			when.assessmentId = rule.when.assessmentId ?? null
			const field = kind === 'score-below' ? 'belowScore' : 'minScore'
			const value = rule.when[field]
			when[field] =
				value === '' || value === null || value === undefined
					? null
					: Number(value)
		}
		out.push({ when, goToLessonId: rule.goToLessonId })
	}
	return out
}

/**
 * Whether a refused lesson save was LessonNextStepGuard's: a rule or default
 * next lesson outside the course. OpenRegister hands the listener's errors
 * back in the response body; the reason code is looked for anywhere in it.
 *
 * @param {object} body The parsed error response.
 * @return {boolean} True for the same-course refusal.
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-rule-cannot-leave-the-course
 */
export function isNextStepRefusal(body) {
	const text = JSON.stringify(body ?? {})
	return (
		text.includes('next-step-outside-course')
		|| text.includes('A next step can only go to a lesson of the same course.')
	)
}
