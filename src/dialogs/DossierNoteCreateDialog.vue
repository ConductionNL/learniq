<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 DossierNoteCreateDialog: "Nieuwe notitie" (learniq/LqNieuweNotitie). A
 teacher writes one DossierNote: the pupil, the day it happened, the kind of
 note as cards, the note, and who may read it (the team, only the care team,
 only the author). A note stays as saved; an addition is a new note.
 @spec openspec/specs/pupil-dossier/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'New note')"
		:eyebrow="t('learniq', 'Care and dossier')"
		:subtitle="t('learniq', 'A note in the dossier stays as you save it. You write an addition as a new note.')"
		width="wizard"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="dossier-note-create-dialog"
		@closing="$emit('close')">
		<div class="dossier-note">
			<div class="dossier-note__pair">
				<DialogUserSelect
					v-model="form.learnerId"
					:inputLabel="t('learniq', 'Pupil')"
					:disabled="busy || Boolean(learnerId)" />
				<NcTextField
					v-model="form.date"
					type="date"
					:label="t('learniq', 'Date')"
					:helperText="t('learniq', 'The day it happened.')"
					:disabled="busy" />
			</div>
			<CnChoiceCards
				v-model="form.category"
				:label="t('learniq', 'Kind of note')"
				:options="categoryOptions"
				:disabled="busy"
				data-testid="dossier-note-category" />
			<NcTextArea
				v-model="form.body"
				:label="t('learniq', 'Note')"
				:disabled="busy"
				rows="4"
				data-testid="dossier-note-body" />
			<CnChoiceCards
				v-model="form.confidentiality"
				:label="t('learniq', 'Who sees this note')"
				:options="confidentialityOptions"
				:columns="3"
				:disabled="busy"
				data-testid="dossier-note-confidentiality" />
			<DialogUserSelect
				v-if="form.confidentiality === 'care-team-only'"
				v-model="form.careTeamUserIds"
				:multiple="true"
				:inputLabel="t('learniq', 'Care team (optional)')"
				:helperText="t('learniq', 'Type a name to add a colleague.')"
				:disabled="busy" />
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<CnDialogActions
				:saving="busy"
				:canSubmit="ready"
				:confirmLabel="t('learniq', 'Save note')"
				:cancelLabel="t('learniq', 'Cancel')"
				@cancel="$emit('close')"
				@submit="save" />
		</template>
	</CnDialog>
</template>

<script>
import { CnChoiceCards, CnDialog, CnDialogActions } from '@conduction/nextcloud-vue'
import { NcTextArea, NcTextField } from '@nextcloud/vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import DialogUserSelect from './DialogUserSelect.vue'
import { canSaveDossierNote, dossierNoteBody, NOTE_CATEGORIES, NOTE_CONFIDENTIALITY } from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, callerTenant, createObject, currentUserId } from './dialogRequests.js'

/**
 * @param {Date} now The current moment.
 * @return {string} Today as YYYY-MM-DD, in local time.
 */
function today(now) {
	return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

export default {
	name: 'DossierNoteCreateDialog',

	components: { CnChoiceCards, CnDialog, CnDialogActions, DialogNoticeFallback, DialogUserSelect, NcTextArea, NcTextField },

	props: {
		/** The pupil's Nextcloud user id, when the note is written from the pupil's page. */
		learnerId: { type: String, default: '' },
	},

	emits: ['close'],

	data() {
		return {
			form: {
				learnerId: this.learnerId,
				date: today(new Date()),
				category: 'observation',
				body: '',
				confidentiality: 'team-visible',
				careTeamUserIds: [],
			},

			busy: false,
			notice: null,
		}
	},

	computed: {
		/**
		 * @return {object[]} The kinds of note as cards.
		 * @spec openspec/specs/pupil-dossier/spec.md
		 */
		categoryOptions() {
			const labels = {
				observation: this.t('learniq', 'Observation'),
				conversation: this.t('learniq', 'Conversation'),
				'phone-call-home': this.t('learniq', 'Phone call home'),
				concern: this.t('learniq', 'Care'),
				positive: this.t('learniq', 'Positive'),
			}
			return NOTE_CATEGORIES.map((value) => ({ value, label: labels[value] }))
		},

		/**
		 * @return {object[]} Who may read the note, as cards.
		 * @spec openspec/specs/pupil-dossier/spec.md
		 */
		confidentialityOptions() {
			const cards = {
				'team-visible': [this.t('learniq', 'The team'), this.t('learniq', 'All teachers of the pupil')],
				'care-team-only': [this.t('learniq', 'Only the care team'), this.t('learniq', 'The people you name below')],
				'private-to-author': [this.t('learniq', 'Only me'), this.t('learniq', 'Nobody else')],
			}
			return NOTE_CONFIDENTIALITY.map((value) => ({ value, label: cards[value][0], description: cards[value][1] }))
		},

		/**
		 * @return {boolean} Whether the note can be saved.
		 * @spec openspec/specs/pupil-dossier/spec.md
		 */
		ready() {
			return canSaveDossierNote(this.form)
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/pupil-dossier/spec.md
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
		 * @spec openspec/specs/pupil-dossier/spec.md
		 */
		async save() {
			this.busy = true
			this.notice = null
			try {
				const note = await createObject('dossier-note', dossierNoteBody(this.form, currentUserId(), callerTenant()))
				announceSaved('learniq:dossier-note:created', { noteId: note?.id ?? '', learnerId: this.form.learnerId })
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
.dossier-note {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.dossier-note__pair {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}
</style>
