<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 SubmissionReopenDialog: "Opnieuw laten inleveren" (learniq/LqOpdracht). A
 teacher sends a returned hand-in back to draft with a new hand-in date
 (Submission `reopen`); the pupils are notified and no late penalty applies
 before the new date.
 @spec openspec/specs/assignments/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'Ask to hand in again')"
		:eyebrow="eyebrow"
		width="confirm"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="submission-reopen-dialog"
		@closing="$emit('close')">
		<div class="submission-reopen">
			<p class="submission-reopen__intro">
				{{ t('learniq', 'The hand-in goes back to draft. {name} gets a notification. No late penalty applies before this new date.', { name: learnerName || t('learniq', 'The pupil') }) }}
			</p>
			<NcTextField
				v-model="due"
				type="datetime-local"
				:label="t('learniq', 'New hand-in date')"
				:disabled="busy"
				data-testid="submission-reopen-due" />
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<CnDialogActions
				:saving="busy"
				:canSubmit="ready"
				:confirmLabel="t('learniq', 'Ask to hand in again')"
				:cancelLabel="t('learniq', 'Cancel')"
				@cancel="$emit('close')"
				@submit="reopen" />
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnDialogActions } from '@conduction/nextcloud-vue'
import { NcTextField } from '@nextcloud/vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import { profileName } from '../utils/learnerName.js'
import { canReopen, reopenData } from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, readObject, runTransition } from './dialogRequests.js'

export default {
	name: 'SubmissionReopenDialog',

	components: { CnDialog, CnDialogActions, DialogNoticeFallback, NcTextField },

	props: {
		/** The Submission's uuid. */
		objectId: { type: String, required: true },
	},

	emits: ['close'],

	data() {
		return { assignmentTitle: '', learnerName: '', due: '', busy: false, notice: null }
	},

	computed: {
		/**
		 * @return {string} "<assignment>, <pupil>".
		 * @spec openspec/specs/assignments/spec.md
		 */
		eyebrow() {
			return [this.assignmentTitle, this.learnerName].filter(Boolean).join(', ')
		},

		/**
		 * @return {boolean} Whether the new date lies ahead.
		 * @spec openspec/specs/assignments/spec.md
		 */
		ready() {
			return canReopen(this.due)
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/assignments/spec.md
		 */
		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	/**
	 * Name the assignment and the pupil.
	 *
	 * @spec openspec/specs/assignments/spec.md
	 */
	async created() {
		let submission
		try {
			submission = await readObject('submission', this.objectId)
		} catch (error) {
			this.notice = requestRefusal(error, this.t('learniq', 'Not found.'), this.t('learniq', 'The hand-in could not be read.'))
			return
		}
		const ref = (submission.learnerRefs ?? [])[0] || submission.learnerRef
		const [assignment, learner] = await Promise.allSettled([
			submission.assignmentId ? readObject('assignment', submission.assignmentId) : Promise.resolve({}),
			ref ? readObject('learner-profile', ref) : Promise.resolve({}),
		])
		this.assignmentTitle = assignment.value?.title ?? ''
		this.learnerName = profileName(learner.value) || (submission.learnerIds ?? [])[0] || ''
	},

	methods: {
		/**
		 * Run the reopen transition with the new date, then close.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assignments/spec.md
		 */
		async reopen() {
			this.busy = true
			this.notice = null
			try {
				await runTransition(this.objectId, 'reopen', reopenData(this.due))
				announceSaved('learniq:submission:reopened', { submissionId: this.objectId })
				this.$emit('close')
			} catch (error) {
				this.notice = requestRefusal(error, this.t('learniq', 'Not sent back.'), this.t('learniq', 'The hand-in could not be sent back.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.submission-reopen {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.submission-reopen__intro {
	margin: 0;
}
</style>
