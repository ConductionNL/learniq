/**
 * The item-authoring form as ItemAuthorView seeds it from a stored QTI Item:
 * the plain fields, the prompt text stripped from the QTI body, and the
 * choices and correct answer of a choice interaction.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without a build step.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * The plain form fields of a stored Item, with the editor defaults.
 *
 * @param {object} item The stored Item.
 * @return {{title: string, interactionType: string, maxScore: number}} The fields.
 */
export function itemFormFields(item) {
	return {
		title: item.title ?? '',
		interactionType: item.interactionType ?? 'choice',
		maxScore: item.maxScore ?? 1,
	}
}

/**
 * The prompt text of a QTI body: the markup stripped and the whitespace collapsed.
 *
 * @param {string|null|undefined} qtiBody The QTI item body XML.
 * @return {string} The prompt.
 */
export function promptFromQtiBody(qtiBody) {
	return (qtiBody ?? '')
		.replace(/<[^>]+>/g, ' ')
		.replace(/\s+/g, ' ')
		.trim()
}

/**
 * One choice option of a `simpleChoice` element; a choice without an
 * identifier is lettered by its position.
 *
 * @param {Element} element The simpleChoice element.
 * @param {number} index Its position.
 * @return {{id: string, label: string}} The option.
 */
function choiceOption(element, index) {
	return {
		id: element.getAttribute('identifier') ?? String.fromCharCode(65 + index),
		label: element.textContent?.trim() ?? '',
	}
}

/**
 * The choice options a parsed QTI document carries.
 *
 * @param {Document} doc The parsed QTI body.
 * @return {Array<{id: string, label: string}>} The options, empty when there are none.
 */
export function choicesFromQti(doc) {
	return Array.from(doc.getElementsByTagName('simpleChoice')).map(choiceOption)
}

/**
 * The index of the correct choice, or the first choice when it is not among them.
 *
 * @param {Array<{id: string}>} choices The options.
 * @param {string} correctId The identifier of the correct response.
 * @return {number} The index.
 */
export function correctChoiceIndex(choices, correctId) {
	const idx = choices.findIndex((c) => c.id === correctId)
	return idx >= 0 ? idx : 0
}
