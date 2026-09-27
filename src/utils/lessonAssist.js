// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The lesson composer's AI assist actions, as pure functions (lesson-ai-assist-actions).
 *
 * Learniq never calls a model itself. Each action posts to hermiq's
 * `lesson-authoring` delegate (hermiq PR 962, `contract.md` in that change),
 * which is off by default and gated by hermiq's AI Act register. This module
 * decides what leaves learniq and how every answer is read:
 *
 * - request bodies come from a per-action allowlist of the contract fields,
 *   so no pupil data and no object id can ride along;
 * - goal ids are swapped for titles before the call and the returned indexes
 *   are swapped back after it;
 * - every answer, HTTP error or thrown error becomes one outcome class
 *   (ok, off, retry, busy, failed) the component switches on.
 *
 * The transport is injected (`createLessonAssistClient({ post, urlFor })`), so
 * the node test runs every path against a stub.
 *
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */

/** Hermiq's limit on `lessonText`, in characters after trimming. */
export const LESSON_TEXT_MAX = 20000

/** Hermiq's limit on the number of `goalTitles`. */
export const GOAL_COUNT_MAX = 100

/** Hermiq's limit on one goal title, in characters. */
export const GOAL_TITLE_MAX = 300

/** Reading levels hermiq accepts (CEFR, then the Dutch referentieniveaus). */
export const READING_LEVELS = ['A1', 'A2', 'B1', 'B2', '1F', '2F', '3F']

/** The four actions, as the path segment hermiq routes them under. */
export const ASSIST_ACTIONS = [
	'outline',
	'questions',
	'simplify',
	'goal-suggestions',
]

/** The field each action's success answer carries its result in. */
const RESULT_FIELD = {
	outline: 'draftText',
	questions: 'questions',
	simplify: 'draftText',
	'goal-suggestions': 'suggestedGoals',
}

const LANGUAGE_PATTERN = /^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8}){0,2}$/

/**
 * Whether the hermiq app is enabled for this user, read the way
 * LearniqSettings.vue reads it (Nextcloud lists every enabled app's web root).
 *
 * @param {object|undefined|null} appsWebRoots `window.OC.appswebroots`.
 * @return {boolean} True when hermiq is enabled.
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 */
export function isHermiqEnabled(appsWebRoots) {
	return Boolean(appsWebRoots) && appsWebRoots.hermiq !== undefined
}

/**
 * A language tag hermiq accepts, falling back to Dutch.
 *
 * @param {string|undefined|null} language A course language such as `nl`.
 * @return {string} A BCP-47 tag.
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
export function normaliseLanguage(language) {
	const tag = String(language ?? '').trim()
	return LANGUAGE_PATTERN.test(tag) ? tag : 'nl'
}

/**
 * A reading level hermiq accepts, falling back to B1.
 *
 * @param {string|undefined|null} level The chosen level.
 * @return {string} One of READING_LEVELS.
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
export function normaliseReadingLevel(level) {
	return READING_LEVELS.includes(level) ? level : 'B1'
}

/**
 * A question count hermiq accepts: an integer from 1 to 10, default 5.
 *
 * @param {number|string|undefined|null} count The chosen count.
 * @return {number} The count, clamped.
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
export function normaliseQuestionCount(count) {
	const n = Number.parseInt(count, 10)
	if (Number.isNaN(n)) return 5
	return Math.min(10, Math.max(1, n))
}

/**
 * Cut a text to hermiq's lesson text limit.
 *
 * @param {string|undefined|null} text Any text.
 * @return {string} The trimmed text, at most LESSON_TEXT_MAX characters.
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
export function clampLessonText(text) {
	return String(text ?? '')
		.trim()
		.slice(0, LESSON_TEXT_MAX)
		.trim()
}

/**
 * The lesson text an action sends: the rich text of every block that is not a
 * pending AI draft, in block order, joined by blank lines.
 *
 * @param {Array<object>} blocks The composer's blocks.
 * @return {string} The lesson text, at most LESSON_TEXT_MAX characters.
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
export function lessonTextFromBlocks(blocks) {
	const parts = (blocks ?? [])
		.filter((b) => b?.type === 'richText' && !b.assistDraft)
		.map((b) => String(b.text ?? '').trim())
		.filter((t) => t !== '')
	return clampLessonText(parts.join('\n\n'))
}

/**
 * Split goals into the titles that leave learniq and the ids that stay, in
 * one fixed order so a returned index maps back to the right id. Goals
 * without a title are dropped, ids are unique, titles are cut to
 * GOAL_TITLE_MAX and the list to GOAL_COUNT_MAX.
 *
 * @param {Array<{id: string, title: string}>} goals Candidate goals.
 * @return {{titles: string[], ids: string[]}} Parallel arrays.
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#scenario-goal-suggestions-map-back-to-goal-ids
 */
export function goalPayload(goals) {
	const titles = []
	const ids = []
	const seen = new Set()
	for (const goal of goals ?? []) {
		const id = String(goal?.id ?? '')
		const title = String(goal?.title ?? '')
			.trim()
			.slice(0, GOAL_TITLE_MAX)
			.trim()
		if (id === '' || title === '' || seen.has(id)) continue
		seen.add(id)
		ids.push(id)
		titles.push(title)
		if (ids.length === GOAL_COUNT_MAX) break
	}
	return { titles, ids }
}

/**
 * Map hermiq's `suggestedGoals[].index` back to goal ids. An index that is not
 * an integer inside the list sent is ignored, and each id appears once.
 *
 * @param {Array<{index: number}>|undefined|null} suggestedGoals From the answer.
 * @param {string[]} ids The ids, in the order their titles were sent.
 * @return {string[]} The suggested goal ids, in index order.
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#scenario-goal-suggestions-map-back-to-goal-ids
 */
export function goalIdsFromSuggestions(suggestedGoals, ids) {
	const result = []
	for (const entry of Array.isArray(suggestedGoals) ? suggestedGoals : []) {
		const index = entry?.index
		if (!Number.isInteger(index) || index < 0 || index >= ids.length) continue
		if (!result.includes(ids[index])) result.push(ids[index])
	}
	return result
}

/**
 * The request body for one action, built from the contract fields only.
 * Optional fields are left out when empty.
 *
 * @param {string} action One of ASSIST_ACTIONS.
 * @param {object} input `{lessonText, goalTitles, language, questionCount, readingLevel}`.
 * @return {object} The body hermiq receives.
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
export function buildRequestBody(action, input = {}) {
	const lessonText = clampLessonText(input.lessonText)
	const goalTitles = (input.goalTitles ?? [])
		.map((t) =>
			String(t ?? '')
				.trim()
				.slice(0, GOAL_TITLE_MAX)
				.trim(),
		)
		.filter((t) => t !== '')
		.slice(0, GOAL_COUNT_MAX)

	switch (action) {
		case 'outline': {
			const body = { goalTitles, language: normaliseLanguage(input.language) }
			if (lessonText !== '') body.lessonText = lessonText
			return body
		}
		case 'questions': {
			const body = {
				lessonText,
				questionCount: normaliseQuestionCount(input.questionCount),
				language: normaliseLanguage(input.language),
			}
			if (goalTitles.length > 0) body.goalTitles = goalTitles
			return body
		}
		case 'simplify':
			return {
				lessonText,
				readingLevel: normaliseReadingLevel(input.readingLevel),
			}
		case 'goal-suggestions':
			return { lessonText, goalTitles }
		default:
			throw new Error(`Unknown lesson assist action: ${action}`)
	}
}

/**
 * Whether an action has what it needs before any call: text for questions,
 * simplify and goal suggestions, goals for the outline and goal suggestions.
 *
 * @param {string} action One of ASSIST_ACTIONS.
 * @param {object} body The body from buildRequestBody().
 * @return {boolean} True when the call may go out.
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
export function canRun(action, body) {
	const hasText = typeof body?.lessonText === 'string' && body.lessonText !== ''
	const hasGoals = Array.isArray(body?.goalTitles) && body.goalTitles.length > 0
	if (action === 'outline') return hasGoals
	if (action === 'goal-suggestions') return hasText && hasGoals
	return hasText
}

/**
 * Turn a hermiq answer, an HTTP status or a thrown error into one outcome
 * class. `off` means the feature is switched off or absent and the actions
 * hide; `retry`, `busy` and `failed` keep them.
 *
 * @param {string} action One of ASSIST_ACTIONS.
 * @param {{status?: number, data?: object, error?: unknown}} response What the transport gave.
 * @return {{outcome: string, reason: string|null, data: object|null}} The classified answer.
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 */
export function classifyOutcome(action, response) {
	const status = response?.status ?? 0
	const data = response?.data ?? null

	if (status === 404)
		return { outcome: 'off', reason: 'not-installed', data: null }
	if (status === 429)
		return { outcome: 'busy', reason: 'rate-limited', data: null }
	if (
		response?.error !== undefined
		|| status !== 200
		|| data === null
		|| typeof data !== 'object'
	) {
		return { outcome: 'failed', reason: 'request-failed', data: null }
	}

	if (data.available === false) {
		if (data.reason === 'feature-not-enabled') {
			return { outcome: 'off', reason: 'feature-not-enabled', data: null }
		}
		return {
			outcome: 'retry',
			reason: String(data.reason ?? 'provider-error'),
			data: null,
		}
	}

	const field = RESULT_FIELD[action]
	const value = data[field]
	const usable =
		field === 'draftText'
			? typeof value === 'string' && value.trim() !== ''
			: Array.isArray(value)
				&& (field === 'suggestedGoals' || value.length > 0)
	if (data.available !== true || !usable) {
		return { outcome: 'retry', reason: 'unusable-answer', data: null }
	}

	return { outcome: 'ok', reason: null, data }
}

/**
 * The text a draft block receives: the outline or rewrite as returned, or the
 * questions as a numbered markdown list.
 *
 * @param {string} action `outline`, `questions` or `simplify`.
 * @param {object} data A successful answer.
 * @return {string} Markdown for a richText block.
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
 */
export function draftTextFromResult(action, data) {
	if (action === 'questions') {
		return (data?.questions ?? [])
			.map((q) => String(q ?? '').trim())
			.filter((q) => q !== '')
			.map((q, i) => `${i + 1}. ${q}`)
			.join('\n')
	}
	return String(data?.draftText ?? '').trim()
}

/**
 * A client for hermiq's four lesson-authoring endpoints. `post(url, body)`
 * resolves to `{status, data}` (axios' shape) or rejects with an error that
 * may carry `response.status`; `urlFor(action)` builds the endpoint URL.
 * Every method resolves to classifyOutcome()'s shape and never rejects.
 *
 * @param {{post: (url: string, body: object) => Promise<object>, urlFor: (action: string) => string}} transport The injected transport.
 * @return {{run: (action: string, input: object) => Promise<object>}} `run` resolves to `{outcome, reason, data, body}`.
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 */
export function createLessonAssistClient({ post, urlFor }) {
	return {
		async run(action, input) {
			if (!ASSIST_ACTIONS.includes(action)) {
				throw new Error(`Unknown lesson assist action: ${action}`)
			}
			const body = buildRequestBody(action, input)
			let response
			try {
				const answer = await post(urlFor(action), body)
				response = { status: answer?.status ?? 200, data: answer?.data }
			} catch (error) {
				const status = error?.response?.status
				response = status ? { status, data: error.response.data } : { error }
			}
			return { ...classifyOutcome(action, response), body }
		},
	}
}
