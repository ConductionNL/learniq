// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The item editor writes QTI 2.1 markup, so it labels it QTI 2.1. It used to
// stamp the QTI 3.0 namespace on the same markup, which no QTI 3.0 tool can
// read.
//
// @spec openspec/specs/assessment/spec.md#requirement-items-are-stored-as-qti-21-and-labelled-as-qti-21

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	buildItemXml,
	escapeXml,
	QTI21_NAMESPACE,
} from '../../src/utils/qtiItemXml.js'

const choiceForm = {
	identifier: 'item-1',
	title: 'Gemiddelde & mediaan',
	interactionType: 'choice',
	prompt: 'Wat is het gemiddelde van 2, 3 en 4?',
	choices: [
		{ id: 'A', label: '3' },
		{ id: 'B', label: '<4>' },
	],
	correctChoiceIdx: 0,
	maxScore: 1,
}

test('the namespace is the QTI 2.1 one', () => {
	assert.equal(QTI21_NAMESPACE, 'http://www.imsglobal.org/xsd/imsqti_v2p1')
})

test('a choice item is QTI 2.1 markup in the QTI 2.1 namespace', () => {
	const xml = buildItemXml(choiceForm)

	assert.match(
		xml,
		/<assessmentItem xmlns="http:\/\/www\.imsglobal\.org\/xsd\/imsqti_v2p1"/,
	)
	assert.match(xml, /<choiceInteraction responseIdentifier="RESPONSE"/)
	assert.match(xml, /<simpleChoice identifier="A">3<\/simpleChoice>/)
	assert.match(xml, /<value>A<\/value>/)
	assert.doesNotMatch(xml, /v3p0/)
})

test('an essay item carries the same namespace', () => {
	const xml = buildItemXml({ ...choiceForm, interactionType: 'extendedText' })

	assert.match(
		xml,
		/<assessmentItem xmlns="http:\/\/www\.imsglobal\.org\/xsd\/imsqti_v2p1"/,
	)
	assert.match(xml, /<extendedTextInteraction responseIdentifier="RESPONSE"/)
	assert.doesNotMatch(xml, /v3p0/)
})

test('text is escaped', () => {
	const xml = buildItemXml(choiceForm)

	assert.match(xml, /title="Gemiddelde &amp; mediaan"/)
	assert.match(xml, /<simpleChoice identifier="B">&lt;4&gt;<\/simpleChoice>/)
	assert.equal(escapeXml('"a" & <b>'), '&quot;a&quot; &amp; &lt;b&gt;')
	assert.equal(escapeXml(null), '')
})
