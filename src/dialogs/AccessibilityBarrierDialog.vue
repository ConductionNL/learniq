<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 AccessibilityBarrierDialog: "Een toegankelijkheidsprobleem melden"
 (learniq/LqBarriereMelden). Any user files one AccessibilityFeedback: where
 it went wrong, what went wrong and how bad it is. The administrator triages
 it into a limitation.
 @spec openspec/specs/accessibility-conformance/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'Report an accessibility problem')"
		:eyebrow="t('learniq', 'Accessibility')"
		width="form"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="accessibility-barrier-dialog"
		@closing="$emit('close')">
		<div class="barrier">
			<p class="barrier__intro">
				{{ t('learniq', 'Does something not work because the app does not work well with your screen reader, keyboard, magnification or colours? Report it here. The administrator sees your report and you hear what happens with it.') }}
			</p>
			<NcTextField
				v-model="affectedSurface"
				:label="t('learniq', 'Where did it go wrong')"
				:helperText="t('learniq', 'The page or the part where you ran into the barrier.')"
				:disabled="busy"
				data-testid="accessibility-barrier-surface" />
			<NcTextArea
				v-model="description"
				:label="t('learniq', 'What went wrong')"
				:helperText="t('learniq', 'Describe in plain words what happened.')"
				:disabled="busy"
				rows="4"
				data-testid="accessibility-barrier-description" />
			<div class="barrier__severity">
				<NcSelect
					v-model="severity"
					:inputLabel="t('learniq', 'How bad is it')"
					:options="severityOptions"
					:reduce="(option) => option.value"
					:clearable="false"
					:disabled="busy"
					label="label"
					data-testid="accessibility-barrier-severity" />
				<span class="barrier__muted">{{ t('learniq', 'Critical, serious, moderate or minor.') }}</span>
			</div>
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<CnDialogActions
				:saving="busy"
				:canSubmit="ready"
				:confirmLabel="t('learniq', 'Send report')"
				:cancelLabel="t('learniq', 'Cancel')"
				@cancel="$emit('close')"
				@submit="send" />
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnDialogActions } from '@conduction/nextcloud-vue'
import { NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import { barrierBody, canSendBarrier } from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, createObject } from './dialogRequests.js'

export default {
	name: 'AccessibilityBarrierDialog',

	components: { CnDialog, CnDialogActions, DialogNoticeFallback, NcSelect, NcTextArea, NcTextField },

	props: {
		/** The page or part the report is about, when the opener knows it. */
		surface: { type: String, default: '' },
	},

	emits: ['close'],

	data() {
		return {
			affectedSurface: this.surface,
			description: '',
			severity: null,
			busy: false,
			notice: null,
		}
	},

	computed: {
		/**
		 * @return {object[]} The severities, worst first, each with what it means for the reporter.
		 * @spec openspec/specs/accessibility-conformance/spec.md
		 */
		severityOptions() {
			return [
				{ value: 'critical', label: this.t('learniq', 'Critical: I cannot do my work') },
				{ value: 'serious', label: this.t('learniq', 'Serious: I can only do half of my work') },
				{ value: 'moderate', label: this.t('learniq', 'Moderate: it takes me a lot more effort') },
				{ value: 'minor', label: this.t('learniq', 'Minor: it is awkward, but it works') },
			]
		},

		/**
		 * @return {boolean} Whether the report can be sent.
		 * @spec openspec/specs/accessibility-conformance/spec.md
		 */
		ready() {
			return canSendBarrier(this.form())
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/accessibility-conformance/spec.md
		 */
		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	methods: {
		/**
		 * @return {object} The fields as the body builder reads them.
		 * @spec openspec/specs/accessibility-conformance/spec.md
		 */
		form() {
			return { affectedSurface: this.affectedSurface, description: this.description, severity: this.severity }
		},

		/**
		 * File the report and close.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/accessibility-conformance/spec.md
		 */
		async send() {
			this.busy = true
			this.notice = null
			try {
				const feedback = await createObject('accessibility-feedback', barrierBody(this.form()))
				announceSaved('learniq:accessibility-feedback:sent', { feedbackId: feedback?.id ?? '' })
				this.$emit('close')
			} catch (error) {
				this.notice = requestRefusal(error, this.t('learniq', 'Not sent.'), this.t('learniq', 'The report could not be sent. Try again later.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.barrier {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.barrier__intro {
	margin: 0;
}

.barrier__severity {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.barrier__muted {
	color: var(--color-text-maxcontrast);
}
</style>
