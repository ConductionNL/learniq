<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 ConferenceReportDialog: "Verslag van het gesprek" (learniq/LqGespreksverslag).
 A teacher picks one of their parent conversations, notes who were there and
 what was agreed, and saves the ConferenceReport as a draft or records it at
 once (`record`). A draft can still change; a recorded report is fixed.
 @spec openspec/specs/parent-conferences/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'Report of the conversation')"
		:eyebrow="learnerName"
		width="wizard"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="conference-report-dialog"
		@closing="$emit('close')">
		<div class="conference-report">
			<p class="conference-report__muted">
				{{ t('learniq', 'The report goes into the dossier of {name}. Parents do not see it; the mentor and the care coordinator do.', { name: learnerName || t('learniq', 'the pupil') }) }}
			</p>
			<div class="conference-report__pair">
				<NcSelect
					v-model="slotId"
					:inputLabel="t('learniq', 'Conversation')"
					:options="slotOptions"
					:reduce="(option) => option.value"
					:loading="loadingSlots"
					:clearable="false"
					:disabled="busy || Boolean(conferenceSlotId)"
					label="label"
					data-testid="conference-report-slot" />
				<NcTextField
					:modelValue="learnerName"
					:label="t('learniq', 'Pupil')"
					readonly />
				<NcTextField
					:modelValue="slot.teacherName || slot.teacherId || ''"
					:label="t('learniq', 'Teacher')"
					readonly />
				<NcTextField
					v-model="attendees"
					:label="t('learniq', 'Present (optional)')"
					:helperText="t('learniq', 'Who were at the conversation.')"
					:disabled="busy" />
			</div>
			<NcTextArea
				v-model="narrative"
				:label="t('learniq', 'Report (optional)')"
				:disabled="busy"
				rows="5"
				data-testid="conference-report-narrative" />
			<div class="conference-report__pair">
				<NcTextField :modelValue="recorderName" :label="t('learniq', 'Recorded by')" readonly />
				<NcTextField :modelValue="recordedAtLabel" :label="t('learniq', 'Recorded on')" readonly />
			</div>
			<p class="conference-report__muted">
				{{ t('learniq', 'A draft you can still change. Choose Record report when the report is ready.') }}
			</p>
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<NcButton
				variant="secondary"
				:disabled="busy || !slotId"
				data-testid="conference-report-draft"
				@click="save(false)">
				{{ t('learniq', 'Save as draft') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ t('learniq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || !slotId"
				data-testid="conference-report-record"
				@click="save(true)">
				{{ t('learniq', 'Record report') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { getLanguage } from '@nextcloud/l10n'
import { NcButton, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import { profileName } from '../utils/learnerName.js'
import { conferenceReportBody, dayLabel } from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, callerTenant, createObject, currentUserId, listObjects, readObject, runTransition } from './dialogRequests.js'

export default {
	name: 'ConferenceReportDialog',

	components: { CnDialog, DialogNoticeFallback, NcButton, NcSelect, NcTextArea, NcTextField },

	props: {
		/** The ConferenceSlot the report is about, when the opener knows it. */
		conferenceSlotId: { type: String, default: '' },
		/** Only offer conversations about this pupil (Nextcloud user id). */
		learnerId: { type: String, default: '' },
	},

	emits: ['close'],

	data() {
		return {
			slots: [],
			loadingSlots: true,
			slotId: this.conferenceSlotId,
			learnerName: '',
			attendees: '',
			narrative: '',
			recordedAt: new Date().toISOString(),
			savedId: '',
			busy: false,
			notice: null,
		}
	},

	computed: {
		/**
		 * @return {object} The chosen conversation.
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		slot() {
			return this.slots.find((entry) => entry.id === this.slotId) ?? {}
		},

		/**
		 * @return {object[]} The teacher's conversations as options.
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		slotOptions() {
			return this.slots.map((entry) => ({
				value: entry.id,
				label: [entry.slotLabel || dayLabel(entry.startsAt, getLanguage(), { weekday: 'short', hour: '2-digit', minute: '2-digit' }), entry.location].filter(Boolean).join(', '),
			}))
		},

		/**
		 * @return {string} The signed-in teacher's name.
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		recorderName() {
			return getCurrentUser()?.displayName || currentUserId()
		},

		/**
		 * @return {string} When the report is recorded, as the reader writes it.
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		recordedAtLabel() {
			return dayLabel(this.recordedAt, getLanguage(), { hour: '2-digit', minute: '2-digit' })
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	watch: {
		/**
		 * Name the pupil of the chosen conversation.
		 *
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		async slot() {
			this.learnerName = this.slot.learnerId || ''
			if (!this.slot.learnerRef) {
				return
			}
			try {
				this.learnerName = profileName(await readObject('learner-profile', this.slot.learnerRef)) || this.learnerName
			} catch {
				// The account id stays as the name.
			}
		},
	},

	/**
	 * Read the teacher's conversations.
	 *
	 * @spec openspec/specs/parent-conferences/spec.md
	 */
	async created() {
		try {
			const filters = { teacherId: currentUserId(), _limit: 100 }
			if (this.learnerId) {
				filters.learnerId = this.learnerId
			}
			const slots = this.conferenceSlotId
				? [await readObject('conference-slot', this.conferenceSlotId)]
				: await listObjects('conference-slot', filters)
			this.slots = slots.filter((entry) => entry.learnerId || entry.learnerRef)
		} catch (error) {
			this.notice = requestRefusal(error, this.t('learniq', 'Not found.'), this.t('learniq', 'Your conversations could not be read.'))
		} finally {
			this.loadingSlots = false
		}
	},

	methods: {
		/**
		 * Save the report as a draft, or record it at once, then close. A
		 * report already saved by an earlier try is not saved twice.
		 *
		 * @param {boolean} record Whether to run `record` after saving.
		 * @return {Promise<void>}
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		async save(record) {
			this.busy = true
			this.notice = null
			try {
				if (!this.savedId) {
					const report = await createObject('conference-report', conferenceReportBody({
						slot: this.slot,
						slotId: this.slotId,
						narrative: this.narrative,
						attendees: this.attendees,
						recordedBy: currentUserId(),
						recordedAt: this.recordedAt,
						tenant: callerTenant(),
					}))
					this.savedId = report?.id ?? ''
				}
				if (record && this.savedId) {
					await runTransition(this.savedId, 'record')
				}
				announceSaved('learniq:conference-report:saved', { reportId: this.savedId, recorded: record })
				this.$emit('close')
			} catch (error) {
				this.notice = requestRefusal(error, this.t('learniq', 'Not saved.'), this.t('learniq', 'The report could not be saved.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.conference-report {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.conference-report__pair {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.conference-report__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
