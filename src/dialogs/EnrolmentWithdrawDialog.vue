<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 EnrolmentWithdrawDialog: "Uitschrijven uit <cursus>"
 (learniq/LqLeerlingInschrijvingen). A member of staff withdraws a pupil from
 one course with a reason, which goes into the enrolment's history. Without a
 reason nothing is sent. Completed lessons and grades are kept.
 @spec openspec/specs/enrolment/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="title"
		:eyebrow="eyebrow"
		width="form"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="enrolment-withdraw-dialog"
		@closing="$emit('close')">
		<div class="enrolment-withdraw">
			<DialogFacts :facts="facts" />
			<NcTextArea
				v-model="reason"
				:label="t('learniq', 'Reason for withdrawing')"
				:helperText="t('learniq', 'The reason goes into the history of the enrolment. You cannot withdraw without a reason.')"
				:disabled="busy"
				rows="4"
				data-testid="enrolment-withdraw-reason" />
			<p class="enrolment-withdraw__muted">
				{{ t('learniq', '{name} and the parents see that the pupil is withdrawn. Completed lessons and grades are kept.', { name: learnerName || t('learniq', 'The pupil') }) }}
			</p>
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<CnDialogActions
				:saving="busy"
				:canSubmit="ready"
				:confirmLabel="t('learniq', 'Withdraw')"
				:cancelLabel="t('learniq', 'Cancel')"
				variant="error"
				@cancel="$emit('close')"
				@submit="withdraw" />
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnDialogActions } from '@conduction/nextcloud-vue'
import { getLanguage } from '@nextcloud/l10n'
import { NcTextArea } from '@nextcloud/vue'
import DialogFacts from './DialogFacts.vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import { dayLabel, withdrawData } from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, readObject, runTransition } from './dialogRequests.js'

export default {
	name: 'EnrolmentWithdrawDialog',

	components: { CnDialog, CnDialogActions, DialogFacts, DialogNoticeFallback, NcTextArea },

	props: {
		/** The Enrolment's uuid. */
		objectId: { type: String, required: true },
	},

	emits: ['close'],

	data() {
		return { enrolment: {}, reason: '', busy: false, notice: null }
	},

	computed: {
		/**
		 * @return {string} The pupil's name, or their account.
		 * @spec openspec/specs/enrolment/spec.md
		 */
		learnerName() {
			return this.enrolment.learnerName || this.enrolment.learnerId || ''
		},

		/**
		 * @return {string} "Uitschrijven uit <cursus>".
		 * @spec openspec/specs/enrolment/spec.md
		 */
		title() {
			return this.enrolment.courseName
				? this.t('learniq', 'Withdraw from {course}', { course: this.enrolment.courseName })
				: this.t('learniq', 'Withdraw')
		},

		/**
		 * @return {string} "Inschrijving, <pupil>".
		 * @spec openspec/specs/enrolment/spec.md
		 */
		eyebrow() {
			return this.learnerName ? this.t('learniq', 'Enrolment, {name}', { name: this.learnerName }) : this.t('learniq', 'Enrolment')
		},

		/**
		 * @return {object[]} Who, which course, since when, how far.
		 * @spec openspec/specs/enrolment/spec.md
		 */
		facts() {
			const since = dayLabel(this.enrolment.inschrijvingDate || this.enrolment.requestedAt || this.enrolment['@self']?.created, getLanguage())
			const enrolled = this.enrolment.source === 'self'
				? this.t('learniq', 'Signed up themselves on {date}', { date: since })
				: since
			const total = this.enrolment.totalPublishedLessonCount
			const done = this.enrolment.completedLessonCount ?? 0
			return [
				{ label: this.t('learniq', 'Pupil'), value: this.learnerName },
				{ label: this.t('learniq', 'Course'), value: this.enrolment.courseName ?? '' },
				{ label: this.t('learniq', 'Enrolled'), value: since ? enrolled : '' },
				{ label: this.t('learniq', 'Progress'), value: total ? this.t('learniq', '{done} of {total} lessons completed', { done, total }) : '' },
			]
		},

		/**
		 * @return {boolean} Whether a reason was given.
		 * @spec openspec/specs/enrolment/spec.md
		 */
		ready() {
			return withdrawData(this.reason).withdrawReason !== ''
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/enrolment/spec.md
		 */
		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	/**
	 * Read the enrolment.
	 *
	 * @spec openspec/specs/enrolment/spec.md
	 */
	async created() {
		try {
			this.enrolment = await readObject('enrolment', this.objectId)
		} catch (error) {
			this.notice = requestRefusal(error, this.t('learniq', 'Not found.'), this.t('learniq', 'The enrolment could not be read.'))
		}
	},

	methods: {
		/**
		 * Run the withdraw transition with the reason, then close.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/enrolment/spec.md
		 */
		async withdraw() {
			this.busy = true
			this.notice = null
			try {
				await runTransition(this.objectId, 'withdraw', withdrawData(this.reason))
				announceSaved('learniq:enrolment:withdrawn', { enrolmentId: this.objectId })
				this.$emit('close')
			} catch (error) {
				this.notice = requestRefusal(error, this.t('learniq', 'Not withdrawn.'), this.t('learniq', 'The pupil could not be withdrawn.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.enrolment-withdraw {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.enrolment-withdraw__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
