// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * An attempt as CnChecklistRunWidget reads it (learniq/LqLlToetsMaken): one
 * question per drawn item, the answers kept so far, and the widget config that
 * saves each answer a moment after it is given through the view's handler.
 */

/** The id of the action the widget dispatches for each answer. */
export const SAVE_ANSWER = 'save-answer'

/**
 * One drawn item as a run question: a choice item offers its options in the
 * attempt's order, every other item is answered in words.
 *
 * @param {object} item The Item.
 * @param {{prompt: string, choices: Array<{id: string, label: string}>, help: string}} parts What the view read off the item.
 * @return {object} The question.
 * @spec openspec/specs/assessment/spec.md#scenario-answers-are-saved-while-the-learner-works
 */
export function runQuestion(item, parts) {
	const choice = item.interactionType === 'choice'
	return {
		id: item.uuid,
		text: parts.prompt,
		type: choice ? 'choice' : 'text',
		options: choice ? parts.choices.map((option) => ({ value: option.id, label: option.label })) : [],
		help: parts.help,
	}
}

/**
 * The answers kept so far, one entry per answered item.
 *
 * @param {Object<string, unknown>} responses The answers by item uuid.
 * @return {Array<{questionId: string, value: string}>} The entries.
 * @spec openspec/specs/assessment/spec.md#scenario-answers-are-saved-while-the-learner-works
 */
export function runAnswers(responses) {
	return Object.entries(responses ?? {})
		.filter(([, value]) => value !== null && value !== undefined)
		.map(([questionId, value]) => ({ questionId, value: String(value) }))
}

/**
 * The answers with one answer the widget gave.
 *
 * @param {Object<string, unknown>} responses The answers by item uuid.
 * @param {{questionId: string, value: string}} answer The answer.
 * @return {Object<string, unknown>} The new answers.
 * @spec openspec/specs/assessment/spec.md#scenario-answers-are-saved-while-the-learner-works
 */
export function applyAnswer(responses, answer) {
	if (!answer || typeof answer.questionId !== 'string') {
		return responses
	}
	return { ...responses, [answer.questionId]: answer.value === '' ? null : answer.value }
}

/**
 * The widget config: questions and answers on the attempt record, one item a
 * page, each answer saved through SAVE_ANSWER, resumed at the first open item.
 *
 * @param {{submit: string, saved: string}} labels The translated button and saved line.
 * @return {object} The CnChecklistRunWidget content.
 * @spec openspec/specs/assessment/spec.md#scenario-answers-are-saved-while-the-learner-works
 */
export function runContent(labels) {
	return {
		questionsPath: 'questions',
		answersPath: 'answers',
		answerKey: 'questionId',
		pageSize: 1,
		allowNote: false,
		allowPhotos: false,
		saveEachAnswer: true,
		saveAction: { id: SAVE_ANSWER, type: 'handler', handler: 'none' },
		startAt: 'firstOpen',
		submitLabel: labels.submit,
		savedText: labels.saved,
	}
}
