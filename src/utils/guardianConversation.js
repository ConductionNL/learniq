// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the guardian conversation on the pupil's page shows, from the answer of
 * `GET /api/pupils/{learnerId}/conversation`: the messages for
 * CnConversationThread, whether a reply box is offered and the sentence shown
 * instead of messages.
 *
 * @param {object|null} answer The endpoint's answer (`available`, `canReply`, `messages`).
 * @return {{messages: Array<object>, canReply: boolean, state: string}} `state` is
 *  `unavailable` (portaliq cannot answer), `none` (no conversation the reader takes part in) or `messages`.
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
 */
export function guardianConversationView(answer) {
	if (!answer || answer.available !== true) {
		return { messages: [], canReply: false, state: 'unavailable' }
	}
	const messages = (Array.isArray(answer.messages) ? answer.messages : [])
		.filter((message) => message && typeof message.text === 'string' && message.text !== '')
		.map((message) => ({
			id: message.id || `${message.threadId}-${message.time}`,
			side: message.side === 'us' ? 'us' : 'them',
			author: message.author || undefined,
			time: message.time || undefined,
			text: message.text,
		}))
	return {
		messages,
		canReply: answer.canReply === true,
		state: messages.length > 0 || answer.canReply === true ? 'messages' : 'none',
	}
}

/**
 * The endpoint of a pupil's guardian conversation.
 *
 * @param {string} learnerId The pupil's Nextcloud user id.
 * @return {string} The app-absolute path, '' without a pupil.
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
 */
export function guardianConversationPath(learnerId) {
	if (typeof learnerId !== 'string' || learnerId === '') {
		return ''
	}
	return `/apps/learniq/api/pupils/${encodeURIComponent(learnerId)}/conversation`
}
