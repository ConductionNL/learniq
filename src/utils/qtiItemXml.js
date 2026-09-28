// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Item XML the item editor writes into `Item.qtiBody`.
 *
 * The markup is QTI 2.1 (`assessmentItem`, `choiceInteraction`,
 * `simpleChoice`), which is what the take view, the choice-order resolver and
 * item analysis read, so it carries the QTI 2.1 namespace. It used to carry
 * the QTI 3.0 namespace on the same markup.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-items-are-stored-as-qti-21-and-labelled-as-qti-21
 */

/** The QTI 2.1 namespace. */
export const QTI21_NAMESPACE = 'http://www.imsglobal.org/xsd/imsqti_v2p1'

/**
 * Escape XML special characters.
 *
 * @param {string|null|undefined} str Raw string
 * @return {string} XML-escaped string
 * @spec openspec/specs/assessment/spec.md#requirement-items-are-stored-as-qti-21-and-labelled-as-qti-21
 */
export function escapeXml(str) {
	return (str ?? '')
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
}

/**
 * Build the QTI 2.1 item XML from the editor's form values.
 *
 * @param {object} form The item form
 * @param {string} form.identifier Item identifier
 * @param {string} form.title Item title
 * @param {string} form.interactionType `choice`, or anything else for an essay
 * @param {string} form.prompt Question text
 * @param {Array<{id: string, label: string}>} form.choices Answer options (choice only)
 * @param {number} form.correctChoiceIdx Index of the correct option (choice only)
 * @param {number} form.maxScore Maximum score
 * @return {string} QTI 2.1 XML string
 * @spec openspec/specs/assessment/spec.md#requirement-items-are-stored-as-qti-21-and-labelled-as-qti-21
 */
export function buildItemXml({
	identifier,
	title,
	interactionType,
	prompt,
	choices,
	correctChoiceIdx,
	maxScore,
}) {
	if (interactionType === 'choice') {
		const optionXml = choices
			.map(
				(c) =>
					`<simpleChoice identifier="${c.id}">${escapeXml(c.label)}</simpleChoice>`,
			)
			.join('\n      ')

		return `<?xml version="1.0" encoding="UTF-8"?>
<assessmentItem xmlns="${QTI21_NAMESPACE}"
    identifier="${identifier}"
    title="${escapeXml(title)}"
    adaptive="false"
    timeDependent="false">
  <responseDeclaration identifier="RESPONSE" cardinality="single" baseType="identifier">
    <correctResponse>
      <value>${escapeXml(choices[correctChoiceIdx]?.id ?? 'A')}</value>
    </correctResponse>
  </responseDeclaration>
  <outcomeDeclaration identifier="SCORE" cardinality="single" baseType="float">
    <defaultValue><value>${maxScore}</value></defaultValue>
  </outcomeDeclaration>
  <itemBody>
    <p>${escapeXml(prompt)}</p>
    <choiceInteraction responseIdentifier="RESPONSE" shuffle="false" maxChoices="1">
      ${optionXml}
    </choiceInteraction>
  </itemBody>
</assessmentItem>`
	}

	// extendedText and other types.
	return `<?xml version="1.0" encoding="UTF-8"?>
<assessmentItem xmlns="${QTI21_NAMESPACE}"
    identifier="${identifier}"
    title="${escapeXml(title)}"
    adaptive="false"
    timeDependent="false">
  <outcomeDeclaration identifier="SCORE" cardinality="single" baseType="float">
    <defaultValue><value>${maxScore}</value></defaultValue>
  </outcomeDeclaration>
  <itemBody>
    <p>${escapeXml(prompt)}</p>
    <extendedTextInteraction responseIdentifier="RESPONSE" expectedLength="500" />
  </itemBody>
</assessmentItem>`
}
