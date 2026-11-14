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
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
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
 * @spec openspec/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 */
export function isHermiqEnabled(appsWebRoots) {
	return Boolean(appsWebRoots) && appsWebRoots.hermiq !== undefined
}

/**
 * A language tag hermiq accepts, falling back to Dutch.
 *
 * @param {string|undefined|null} language A course language such as `nl`.
 * @return {string} A BCP-47 tag.
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
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
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
export function normaliseReadingLevel(level) {
	return READING_LEVELS.includes(level) ? level : 'B1'
}

/**
 * A question count hermiq accepts: an integer from 1 to 10, default 5.
 *
 * @param {number|string|undefined|null} count The chosen count.
 * @return {number} The count, clamped.
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
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
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
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
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
export function lessonTextFromBlocks(blocks) {
	const parts = (blocks ?? [])
		.filter((b) => b?.type === 'richText' && !b.draft)
		.map((b) => String(b.text ?? '').trim())
		.filter((t) => t !== '')
	return clampLessonText(parts.join('\n\n'))
}

/**
 * A goal title as it leaves learniq: trimmed and cut to GOAL_TITLE_MAX.
 *
 * @param {unknown} title The title.
 * @return {string} The title, '' when there is none.
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
function goalTitle(title) {
	return String(title ?? '')
		.trim()
		.slice(0, GOAL_TITLE_MAX)
		.trim()
}

/**
 * Add one goal to the payload being built, unless it has no id or title or
 * its id is already in.
 *
 * @param {{titles: string[], ids: string[], seen: Set<string>}} run The payload being built.
 * @param {{id: string, title: string}} goal A candidate goal.
 * @return {void}
 * @spec openspec/specs/course-management/spec.md#scenario-goal-suggestions-map-back-to-goal-ids
 */
function addGoal(run, goal) {
	const id = String(goal?.id ?? '')
	const title = goalTitle(goal?.title)
	if (id === '' || title === '' || run.seen.has(id)) {
		return
	}
	run.seen.add(id)
	run.ids.push(id)
	run.titles.push(title)
}

/**
 * Split goals into the titles that leave learniq and the ids that stay, in
 * one fixed order so a returned index maps back to the right id. Goals
 * without a title are dropped, ids are unique, titles are cut to
 * GOAL_TITLE_MAX and the list to GOAL_COUNT_MAX.
 *
 * @param {Array<{id: string, title: string}>} goals Candidate goals.
 * @return {{titles: string[], ids: string[]}} Parallel arrays.
 * @spec openspec/specs/course-management/spec.md#scenario-goal-suggestions-map-back-to-goal-ids
 */
export function goalPayload(goals) {
	const run = { titles: [], ids: [], seen: new Set() }
	for (const goal of goals ?? []) {
		addGoal(run, goal)
		if (run.ids.length === GOAL_COUNT_MAX) break
	}
	return { titles: run.titles, ids: run.ids }
}

/**
 * A value when it is a list, else an empty list.
 *
 * @param {unknown} value The value.
 * @return {Array} The list.
 * @spec openspec/specs/course-management/spec.md#scenario-goal-suggestions-map-back-to-goal-ids
 */
function listOrEmpty(value) {
	return Array.isArray(value) ? value : []
}

/**
 * Whether a returned index points into the list of ids sent.
 *
 * @param {unknown} index The index from the answer.
 * @param {string[]} ids The ids, in the order their titles were sent.
 * @return {boolean} True for an integer inside the list.
 * @spec openspec/specs/course-management/spec.md#scenario-goal-suggestions-map-back-to-goal-ids
 */
function isIndexInto(index, ids) {
	return Number.isInteger(index) && index >= 0 && index < ids.length
}

/**
 * Map hermiq's `suggestedGoals[].index` back to goal ids. An index that is not
 * an integer inside the list sent is ignored, and each id appears once.
 *
 * @param {Array<{index: number}>|undefined|null} suggestedGoals From the answer.
 * @param {string[]} ids The ids, in the order their titles were sent.
 * @return {string[]} The suggested goal ids, in index order.
 * @spec openspec/specs/course-management/spec.md#scenario-goal-suggestions-map-back-to-goal-ids
 */
export function goalIdsFromSuggestions(suggestedGoals, ids) {
	const result = []
	for (const entry of listOrEmpty(suggestedGoals)) {
		const index = entry?.index
		if (isIndexInto(index, ids) && !result.includes(ids[index])) {
			result.push(ids[index])
		}
	}
	return result
}

/**
 * The body of an outline request; the lesson text only when there is one.
 *
 * @param {{input: object, lessonText: string, goalTitles: string[]}} fields The normalised fields.
 * @return {object} The body.
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
function outlineBody(fields) {
	const body = {
		goalTitles: fields.goalTitles,
		language: normaliseLanguage(fields.input.language),
	}
	if (fields.lessonText !== '') body.lessonText = fields.lessonText
	return body
}

/**
 * The body of a questions request; the goal titles only when there are any.
 *
 * @param {{input: object, lessonText: string, goalTitles: string[]}} fields The normalised fields.
 * @return {object} The body.
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
function questionsBody(fields) {
	const body = {
		lessonText: fields.lessonText,
		questionCount: normaliseQuestionCount(fields.input.questionCount),
		language: normaliseLanguage(fields.input.language),
	}
	if (fields.goalTitles.length > 0) body.goalTitles = fields.goalTitles
	return body
}

/**
 * The body of a simplify request.
 *
 * @param {{input: object, lessonText: string}} fields The normalised fields.
 * @return {object} The body.
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
function simplifyBody(fields) {
	return {
		lessonText: fields.lessonText,
		readingLevel: normaliseReadingLevel(fields.input.readingLevel),
	}
}

/**
 * The body of a goal-suggestions request.
 *
 * @param {{lessonText: string, goalTitles: string[]}} fields The normalised fields.
 * @return {object} The body.
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
function goalSuggestionsBody(fields) {
	return { lessonText: fields.lessonText, goalTitles: fields.goalTitles }
}

/** The body builder of each action. */
const REQUEST_BODIES = new Map([
	['outline', outlineBody],
	['questions', questionsBody],
	['simplify', simplifyBody],
	['goal-suggestions', goalSuggestionsBody],
])

/**
 * The goal titles a request carries: trimmed, cut, blanks dropped, at most
 * GOAL_COUNT_MAX.
 *
 * @param {Array<unknown>|undefined} goalTitles The titles given.
 * @return {string[]} The titles.
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
function requestGoalTitles(goalTitles) {
	return (goalTitles ?? [])
		.map((t) => goalTitle(t))
		.filter((t) => t !== '')
		.slice(0, GOAL_COUNT_MAX)
}

/**
 * The request body for one action, built from the contract fields only.
 * Optional fields are left out when empty.
 *
 * @param {string} action One of ASSIST_ACTIONS.
 * @param {object} input `{lessonText, goalTitles, language, questionCount, readingLevel}`.
 * @return {object} The body hermiq receives.
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
export function buildRequestBody(action, input = {}) {
	const fields = {
		input,
		lessonText: clampLessonText(input.lessonText),
		goalTitles: requestGoalTitles(input.goalTitles),
	}
	const build = REQUEST_BODIES.get(action)
	if (build === undefined) {
		throw new Error(`Unknown lesson assist action: ${action}`)
	}
	return build(fields)
}

/**
 * Whether a request body carries lesson text.
 *
 * @param {object} body The body from buildRequestBody().
 * @return {boolean} True when it has text.
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
function hasLessonText(body) {
	return typeof body?.lessonText === 'string' && body.lessonText !== ''
}

/**
 * Whether a request body carries goal titles.
 *
 * @param {object} body The body from buildRequestBody().
 * @return {boolean} True when it has goals.
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
function hasGoalTitles(body) {
	return Array.isArray(body?.goalTitles) && body.goalTitles.length > 0
}

/**
 * Whether an action has what it needs before any call: text for questions,
 * simplify and goal suggestions, goals for the outline and goal suggestions.
 *
 * @param {string} action One of ASSIST_ACTIONS.
 * @param {object} body The body from buildRequestBody().
 * @return {boolean} True when the call may go out.
 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
 */
export function canRun(action, body) {
	const hasText = hasLessonText(body)
	const hasGoals = hasGoalTitles(body)
	if (action === 'outline') return hasGoals
	if (action === 'goal-suggestions') return hasText && hasGoals
	return hasText
}

/** The outcome an HTTP status means on its own, whatever the body says. */
const STATUS_OUTCOMES = new Map([
	[404, { outcome: 'off', reason: 'not-installed' }],
	[429, { outcome: 'busy', reason: 'rate-limited' }],
])

/**
 * An outcome that carries no answer.
 *
 * @param {string} outcome The outcome class.
 * @param {string} reason Why.
 * @return {{outcome: string, reason: string, data: null}} The outcome.
 * @spec openspec/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 */
function outcomeWithoutData(outcome, reason) {
	return { outcome, reason, data: null }
}

/**
 * The outcome the HTTP status alone decides, or null.
 *
 * @param {{status?: number}|undefined} response What the transport gave.
 * @return {{outcome: string, reason: string, data: null}|null} The outcome.
 * @spec openspec/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 */
function statusOutcome(response) {
	const byStatus = STATUS_OUTCOMES.get(response?.status ?? 0)
	return byStatus === undefined ? null : outcomeWithoutData(byStatus.outcome, byStatus.reason)
}

/**
 * Whether an answer is an object to read.
 *
 * @param {unknown} data The answer.
 * @return {boolean} True for an object.
 * @spec openspec/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 */
function isAnswerObject(data) {
	return data !== null && typeof data === 'object'
}

/**
 * Whether the request failed: an error, a status other than 200, or no
 * object to read.
 *
 * @param {{status?: number, data?: object, error?: unknown}|undefined} response What the transport gave.
 * @return {boolean} True when it failed.
 * @spec openspec/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 */
function requestFailed(response) {
	return (
		response?.error !== undefined
		|| (response?.status ?? 0) !== 200
		|| !isAnswerObject(response?.data ?? null)
	)
}

/**
 * Whether an answer carries a result the action can use: non-blank draft
 * text, a non-empty question list, or a goal-suggestion list (empty allowed).
 *
 * @param {string} action One of ASSIST_ACTIONS.
 * @param {object} data The answer.
 * @return {boolean} True when usable.
 * @spec openspec/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 */
function answerUsable(action, data) {
	const field = RESULT_FIELD[action]
	const value = data[field]
	if (field === 'draftText') {
		return typeof value === 'string' && value.trim() !== ''
	}
	return Array.isArray(value) && (field === 'suggestedGoals' || value.length > 0)
}

/**
 * The outcome of an answer hermiq marked unavailable: off when the feature
 * is not enabled, else retry with hermiq's reason.
 *
 * @param {object} data The answer.
 * @return {{outcome: string, reason: string, data: null}} The outcome.
 * @spec openspec/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 */
function unavailableOutcome(data) {
	if (data.reason === 'feature-not-enabled') {
		return outcomeWithoutData('off', 'feature-not-enabled')
	}
	return outcomeWithoutData('retry', String(data.reason ?? 'provider-error'))
}

/**
 * The outcome of an answer that arrived as an object.
 *
 * @param {string} action One of ASSIST_ACTIONS.
 * @param {object} data The answer.
 * @return {{outcome: string, reason: string|null, data: object|null}} The outcome.
 * @spec openspec/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 */
function answerOutcome(action, data) {
	if (data.available === false) {
		return unavailableOutcome(data)
	}
	if (data.available !== true || !answerUsable(action, data)) {
		return outcomeWithoutData('retry', 'unusable-answer')
	}
	return { outcome: 'ok', reason: null, data }
}

/**
 * Turn a hermiq answer, an HTTP status or a thrown error into one outcome
 * class. `off` means the feature is switched off or absent and the actions
 * hide; `retry`, `busy` and `failed` keep them.
 *
 * @param {string} action One of ASSIST_ACTIONS.
 * @param {{status?: number, data?: object, error?: unknown}} response What the transport gave.
 * @return {{outcome: string, reason: string|null, data: object|null}} The classified answer.
 * @spec openspec/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 */
export function classifyOutcome(action, response) {
	const byStatus = statusOutcome(response)
	if (byStatus !== null) {
		return byStatus
	}
	if (requestFailed(response)) {
		return outcomeWithoutData('failed', 'request-failed')
	}
	return answerOutcome(action, response.data)
}

/**
 * The text a draft block receives: the outline or rewrite as returned, or the
 * questions as a numbered markdown list.
 *
 * @param {string} action `outline`, `questions` or `simplify`.
 * @param {object} data A successful answer.
 * @return {string} Markdown for a richText block.
 * @spec openspec/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
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
 * @spec openspec/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 */
export function createLessonAssistClient({ post, urlFor }) {
	return {
		/**
		 * Build the body, post it, and classify whatever comes back.
		 *
		 * @param {string} action One of ASSIST_ACTIONS.
		 * @param {object} input The fields the body is built from.
		 * @return {Promise<object>} `{outcome, reason, data, body}`.
		 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
		 */
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
