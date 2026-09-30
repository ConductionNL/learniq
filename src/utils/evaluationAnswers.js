// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A learner answers a course evaluation (assessment-course-evaluation-answer-page).
// Pure helpers, no Vue and no Nextcloud imports, so node --test covers them.
// The server checks the answers again (CourseEvaluationAnswerService); these
// only keep the form honest before it posts.

/**
 * The question kinds the answer form renders.
 *
 * @type {string[]}
 */
export const QUESTION_KINDS = ['likert-5', 'free-text']

/**
 * The questions the form shows: well-formed ones of a known kind, in order.
 *
 * @param {Array<object>|undefined} questions The campaign questions.
 * @return {Array<object>} The questions to render.
 */
export function formQuestions(questions) {
	if (!Array.isArray(questions)) {
		return []
	}
	return questions.filter(
		(question) =>
			question
			&& typeof question.questionId === 'string'
			&& question.questionId !== ''
			&& QUESTION_KINDS.includes(question.kind),
	)
}

/**
 * The question text in the reader's language, falling back to English,
 * then Dutch, then the question id.
 *
 * @param {object} question A campaign question.
 * @param {string} language The reader's language code, such as 'nl' or 'en-GB'.
 * @return {string} The text to show.
 */
export function questionText(question, language) {
	const text = question?.text ?? {}
	const short = String(language ?? '').slice(0, 2)
	return text[short] || text.en || text.nl || question?.questionId || ''
}

/**
 * Whether a value answers a question of the given kind.
 *
 * @param {string} kind The question kind.
 * @param {*} value The value in the form.
 * @return {boolean} True when it counts as an answer.
 */
export function isAnswered(kind, value) {
	if (kind === 'likert-5') {
		const rating = Number(value)
		return (
			value !== ''
			&& value !== null
			&& Number.isInteger(rating)
			&& rating >= 1
			&& rating <= 5
		)
	}
	return typeof value === 'string' && value.trim() !== ''
}

/**
 * The ids of required questions that have no answer yet.
 *
 * @param {Array<object>} questions The campaign questions.
 * @param {object} answers Map of questionId to value.
 * @return {string[]} The unanswered required question ids.
 */
export function missingRequired(questions, answers) {
	return formQuestions(questions)
		.filter((question) => question.required === true)
		.filter(
			(question) => !isAnswered(question.kind, answers?.[question.questionId]),
		)
		.map((question) => question.questionId)
}

/**
 * The body the form posts: only answered questions, ratings as numbers,
 * text trimmed. Never anything about the learner.
 *
 * @param {Array<object>} questions The campaign questions.
 * @param {object} answers Map of questionId to value.
 * @return {{answers: object}} The request body.
 */
export function answerBody(questions, answers) {
	const body = {}
	for (const question of formQuestions(questions)) {
		const value = answers?.[question.questionId]
		if (!isAnswered(question.kind, value)) {
			continue
		}
		body[question.questionId] =
			question.kind === 'likert-5' ? Number(value) : value.trim()
	}
	return { answers: body }
}

/**
 * How the staff figures read: the mean, or why it is not shown.
 *
 * @param {object|null} results The server's campaign results.
 * @return {{responses: number, invitations: number, mean: (number|null), hidden: boolean}} The figures.
 */
export function resultFigures(results) {
	const responses = Number(results?.responseCount ?? 0)
	const invitations = Number(results?.invitationCount ?? 0)
	const hidden =
		results?.meanHidden === true
		|| results?.meanOverallScore === null
		|| results?.meanOverallScore === undefined
	return {
		responses,
		invitations,
		mean: hidden ? null : Number(results.meanOverallScore),
		hidden,
	}
}
