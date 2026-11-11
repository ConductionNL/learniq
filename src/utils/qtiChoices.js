// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The options of a QTI choice interaction, as the assessment screen shows them.
 */

/**
 * The `<simpleChoice>` options of a QTI body, in declared order. An option
 * without an identifier is known by its text.
 *
 * @param {string} qtiBody Raw QTI XML body.
 * @return {Array<{id: string, label: string}>}
 * @spec openspec/specs/assessment/spec.md#requirement-per-attempt-item-order-and-answer-option-shuffle-are-independently-configurable
 */
export function parseSimpleChoices(qtiBody) {
	const parser = new DOMParser()
	const doc = parser.parseFromString(qtiBody, 'text/xml')
	const choices = []
	for (const sc of doc.getElementsByTagName('simpleChoice')) {
		choices.push({
			id: sc.getAttribute('identifier') ?? sc.textContent?.trim() ?? '',
			label: sc.textContent?.trim() ?? '',
		})
	}
	return choices
}

/**
 * The choices in `optionOrder` when it names any of them, the ones it does not
 * name after it; the declared order otherwise.
 *
 * @param {Array<{id: string, label: string}>} choices The declared choices.
 * @param {string[]|null} optionOrder Server-resolved identifier order, or null.
 * @return {Array<{id: string, label: string}>}
 * @spec openspec/specs/assessment/spec.md#requirement-per-attempt-item-order-and-answer-option-shuffle-are-independently-configurable
 */
export function orderChoices(choices, optionOrder) {
	if (!optionOrder || optionOrder.length === 0) {
		return choices
	}
	const byId = new Map(choices.map((choice) => [choice.id, choice]))
	const ordered = optionOrder.map((id) => byId.get(id)).filter(Boolean)
	const remaining = choices.filter((choice) => !optionOrder.includes(choice.id))
	return ordered.length > 0 ? [...ordered, ...remaining] : choices
}
