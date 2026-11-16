<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 PupilGuardianConversation: the conversation with the pupil's guardians on the
 pupil's page (wilgenboom/LqDetail, "Gesprek met ouders"). The newest messages
 between the reader and the guardians, read through portaliq's staff
 messaging by GET /api/pupils/{learnerId}/conversation, in the library's
 CnConversationThread; a participant replies from the page (POST to the same
 path). Staff who take part in no thread about the pupil see no messages.
 @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
-->
<template>
	<section class="pupil-guardian-conversation" data-testid="pupil-guardian-conversation">
		<p v-if="view.state === 'unavailable' && !loading" class="pupil-guardian-conversation__note">
			{{ t('learniq', 'The messages with the parents cannot be reached right now.') }}
		</p>
		<CnConversationThread
			v-else
			:title="t('learniq', 'Conversation with the parents')"
			:messages="view.messages"
			:loading="loading"
			:allowReply="view.canReply"
			:endpoint="path"
			:usLabel="t('learniq', 'Our school')"
			:replyLabel="t('learniq', 'Message to the parents')"
			:sendLabel="t('learniq', 'Send to the parents')"
			:emptyText="t('learniq', 'You have no conversation with the parents of this pupil yet.')"
			@sent="onSent" />
	</section>
</template>

<script>
import { CnConversationThread } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { guardianConversationPath, guardianConversationView } from '../../utils/guardianConversation.js'

export default {
	name: 'PupilGuardianConversation',

	components: { CnConversationThread },

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The pupil's Nextcloud user id (`@object.ncUserId`). */
		learnerId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			answer: null,
			loading: false,
		}
	},

	computed: {
		/**
		 * @return {string} The pupil's user id, from the prop or the page's object.
		 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
		 */
		pupil() {
			return this.learnerId || String(this.cnSectionContext?.value?.object?.ncUserId ?? '')
		},

		/**
		 * @return {string} The conversation endpoint.
		 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
		 */
		path() {
			return guardianConversationPath(this.pupil)
		},

		/**
		 * @return {object} What the thread shows.
		 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
		 */
		view() {
			return guardianConversationView(this.answer)
		},
	},

	watch: {
		path: {
			immediate: true,
			/**
			 * Reload the conversation whenever the pupil, and so the endpoint, changes.
			 *
			 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Read the conversation; a refusal or failure shows as not reachable.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
		 */
		async load() {
			if (this.path === '') {
				this.answer = null
				return
			}
			this.loading = true
			try {
				const response = await axios.get(generateUrl(this.path))
				this.answer = response?.data ?? null
			} catch {
				this.answer = null
			} finally {
				this.loading = false
			}
		},

		/**
		 * After a reply the endpoint answers the conversation as it now stands.
		 *
		 * @param {object|undefined} data The answer of the post.
		 * @return {void}
		 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
		 */
		onSent(data) {
			if (data && typeof data === 'object') {
				this.answer = data
				return
			}
			this.load()
		},
	},
}
</script>

<style scoped>
.pupil-guardian-conversation__note {
	color: var(--color-text-maxcontrast);
}
</style>
