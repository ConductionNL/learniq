// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The conversation with the guardians on the pupil's page
// (learner-list-and-page-follow-the-boards T5): the messages, the reply box,
// and the sentence when portaliq cannot answer.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { guardianConversationPath, guardianConversationView } from '../../src/utils/guardianConversation.js'

test('the messages are shown on their side, with a reply box for a participant', () => {
	const view = guardianConversationView({
		available: true,
		canReply: true,
		messages: [
			{ id: 'm1', threadId: 't', side: 'them', author: '', time: '2026-09-27T19:00:00+00:00', text: 'Moet Vera de topografie kennen?' },
			{ id: 'm2', threadId: 't', side: 'us', author: 'Daan de Wit', time: '2026-09-28T09:00:00+00:00', text: 'Ja.' },
			{ id: 'm3', threadId: 't', side: 'us', text: '' },
		],
	})
	assert.equal(view.state, 'messages')
	assert.equal(view.canReply, true)
	assert.deepEqual(view.messages, [
		{ id: 'm1', side: 'them', author: undefined, time: '2026-09-27T19:00:00+00:00', text: 'Moet Vera de topografie kennen?' },
		{ id: 'm2', side: 'us', author: 'Daan de Wit', time: '2026-09-28T09:00:00+00:00', text: 'Ja.' },
	])
})

test('a reader who takes part in no conversation sees none and gets no reply box', () => {
	assert.deepEqual(guardianConversationView({ available: true, canReply: false, messages: [] }), { messages: [], canReply: false, state: 'none' })
})

test('without portaliq, or after a refusal, the conversation is not reachable', () => {
	assert.equal(guardianConversationView(null).state, 'unavailable')
	assert.equal(guardianConversationView({ available: false, canReply: true, messages: [{ text: 'x' }] }).canReply, false)
})

test('the endpoint names the pupil', () => {
	assert.equal(guardianConversationPath('vera'), '/apps/learniq/api/pupils/vera/conversation')
	assert.equal(guardianConversationPath('a b'), '/apps/learniq/api/pupils/a%20b/conversation')
	assert.equal(guardianConversationPath(''), '')
})

test('the pupil page mounts the conversation section with the pupil', () => {
	const manifest = JSON.parse(readFileSync(new URL('../../src/manifest.d/people.json', import.meta.url), 'utf8'))
	const page = manifest.pages.find((candidate) => candidate.id === 'LearnerProfileDetail')
	const section = page.config.bodyWidgets.find((widget) => widget.component === 'PupilGuardianConversation')
	assert.deepEqual(section.props, { learnerId: '@object.ncUserId' })
	const registry = readFileSync(new URL('../../src/registry.js', import.meta.url), 'utf8')
	assert.match(registry, /PupilGuardianConversation: \{\n\t\tkind: 'section',/)
})
