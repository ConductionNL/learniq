<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 ConfidentialNoteCreateDialog: "Nieuwe vertrouwelijke notitie"
 (learniq/LqZoNieuweNotitie). The confidential counsellor writes one
 ConfidentialNote: a short case name without names, the note, the colleagues
 who may read it, its status and the day it is destroyed.
 @spec openspec/specs/confidential-counsel/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'New confidential note')"
		:eyebrow="t('learniq', 'Confidential notes')"
		width="form"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="confidential-note-create-dialog"
		@closing="$emit('close')">
		<div class="confidential-note">
			<NcTextField
				v-model="form.title"
				:label="t('learniq', 'Short name of the case')"
				:helperText="t('learniq', 'Leave names out.')"
				:disabled="busy"
				data-testid="confidential-note-title" />
			<NcTextArea
				v-model="form.note"
				:label="t('learniq', 'Note')"
				:helperText="t('learniq', 'What was discussed and agreed. Only you and the people you add can read it.')"
				:disabled="busy"
				rows="4"
				data-testid="confidential-note-note" />
			<DialogUserSelect
				v-model="form.participantIds"
				:multiple="true"
				:inputLabel="t('learniq', 'People with access (optional)')"
				:helperText="t('learniq', 'Colleagues you add to this case. They can read the note, nothing else.')"
				:disabled="busy" />
			<div class="confidential-note__pair">
				<NcSelect
					v-model="form.status"
					:inputLabel="t('learniq', 'Status')"
					:options="statusOptions"
					:reduce="(option) => option.value"
					:clearable="false"
					:disabled="busy"
					label="label" />
				<NcTextField
					v-model="form.destroyAfter"
					type="date"
					:label="t('learniq', 'Destroy after (optional)')"
					:helperText="t('learniq', 'Keep a note no longer than the case needs.')"
					:disabled="busy" />
			</div>
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<CnDialogActions
				:saving="busy"
				:canSubmit="ready"
				:confirmLabel="t('learniq', 'Save')"
				:cancelLabel="t('learniq', 'Cancel')"
				@cancel="$emit('close')"
				@submit="save" />
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnDialogActions } from '@conduction/nextcloud-vue'
import { NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import DialogUserSelect from './DialogUserSelect.vue'
import { canSaveConfidentialNote, confidentialNoteBody } from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, callerTenant, createObject, currentUserId } from './dialogRequests.js'

export default {
	name: 'ConfidentialNoteCreateDialog',

	components: { CnDialog, CnDialogActions, DialogNoticeFallback, DialogUserSelect, NcSelect, NcTextArea, NcTextField },

	emits: ['close'],

	data() {
		return {
			form: { title: '', note: '', participantIds: [], status: 'open', destroyAfter: '' },
			busy: false,
			notice: null,
		}
	},

	computed: {
		/**
		 * @return {object[]} ConfidentialNote.status in words.
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		statusOptions() {
			return [
				{ value: 'open', label: this.t('learniq', 'Open') },
				{ value: 'closed', label: this.t('learniq', 'Closed') },
			]
		},

		/**
		 * @return {boolean} Whether the note can be saved.
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		ready() {
			return canSaveConfidentialNote(this.form)
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	methods: {
		/**
		 * Save the note and close.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		async save() {
			this.busy = true
			this.notice = null
			try {
				const note = await createObject('confidential-note', confidentialNoteBody(this.form, currentUserId(), callerTenant()))
				announceSaved('learniq:confidential-note:created', { noteId: note?.id ?? '' })
				this.$emit('close')
			} catch (error) {
				this.notice = requestRefusal(error, this.t('learniq', 'Not saved.'), this.t('learniq', 'The note could not be saved.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.confidential-note {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.confidential-note__pair {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}
</style>
