<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 RegulationExemptionDecisionDialog: "Vrijstelling toekennen"
 (learniq/LqCfVrijstellingen). A compliance officer decides a requested
 RegulationExemption: the rationale, the policy it rests on and the day it
 ends. Granting needs all three; rejecting needs the rationale.
 RegulationExemptionDecisionGuard refuses the person who asked, and its
 refusal shows in the dialog.
 @spec openspec/specs/compliance-exemptions/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'Grant exemption')"
		:eyebrow="eyebrow"
		width="form"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="regulation-exemption-decision-dialog"
		@closing="$emit('close')">
		<div class="regulation-exemption">
			<DialogFacts :facts="facts" />
			<NcTextArea
				v-model="form.decisionRationale"
				:label="t('learniq', 'Rationale')"
				:disabled="busy"
				rows="4"
				data-testid="regulation-exemption-rationale" />
			<div class="regulation-exemption__pair">
				<NcTextField
					v-model="form.policyReference"
					:label="t('learniq', 'Policy reference')"
					:disabled="busy"
					data-testid="regulation-exemption-policy" />
				<NcTextField
					v-model="form.validUntil"
					type="date"
					:label="t('learniq', 'Valid until')"
					:helperText="t('learniq', 'After that, {name} counts for this rule again.', { name: person || t('learniq', 'the employee') })"
					:disabled="busy"
					data-testid="regulation-exemption-until" />
			</div>
			<p class="regulation-exemption__muted">
				{{ t('learniq', 'You can only grant a request you did not make yourself. Without a rationale, a policy reference and an end date the request is not granted.') }}
			</p>
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<NcButton
				variant="secondary"
				:disabled="busy || !canReject"
				data-testid="regulation-exemption-reject"
				@click="decide('reject')">
				{{ t('learniq', 'Reject') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ t('learniq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || !canGrant"
				data-testid="regulation-exemption-grant"
				@click="decide('grant')">
				{{ t('learniq', 'Grant exemption') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { getLanguage } from '@nextcloud/l10n'
import { NcButton, NcTextArea, NcTextField } from '@nextcloud/vue'
import DialogFacts from './DialogFacts.vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import { canGrantRegulationExemption, canRejectRegulationExemption, dayLabel, decisionData } from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, readObject, runTransition } from './dialogRequests.js'

export default {
	name: 'RegulationExemptionDecisionDialog',

	components: { CnDialog, DialogFacts, DialogNoticeFallback, NcButton, NcTextArea, NcTextField },

	props: {
		/** The RegulationExemption's uuid. */
		objectId: { type: String, required: true },
	},

	emits: ['close'],

	data() {
		return {
			exemption: {},
			form: { decisionRationale: '', policyReference: '', validUntil: '' },
			busy: false,
			notice: null,
		}
	},

	computed: {
		/**
		 * @return {string} The person the exemption is for, as the request names them.
		 * @spec openspec/specs/compliance-exemptions/spec.md
		 */
		person() {
			return this.exemption.learnerId || ''
		},

		/**
		 * @return {string} "Vrijstelling, <person>".
		 * @spec openspec/specs/compliance-exemptions/spec.md
		 */
		eyebrow() {
			return this.person ? this.t('learniq', 'Exemption, {name}', { name: this.person }) : this.t('learniq', 'Exemption')
		},

		/**
		 * @return {object[]} Who, which rule, why, asked by whom and when.
		 * @spec openspec/specs/compliance-exemptions/spec.md
		 */
		facts() {
			const reasons = {
				medical: this.t('learniq', 'Medical'),
				'role-change': this.t('learniq', 'Change of role'),
				'equivalent-qualification': this.t('learniq', 'Equivalent qualification'),
				other: this.t('learniq', 'Other'),
			}
			const kind = reasons[this.exemption.reasonKind] ?? ''
			const why = this.exemption.reasonText ?? ''
			const asked = dayLabel(this.exemption['@self']?.created, getLanguage())
			return [
				{ label: this.t('learniq', 'Employee'), value: this.person },
				{ label: this.t('learniq', 'Rule'), value: this.exemption.regulationSlug ?? '' },
				{ label: this.t('learniq', 'Reason'), value: kind && why ? `${kind}, ${why}` : kind || why },
				{ label: this.t('learniq', 'Requested by'), value: [this.exemption.requestedBy, asked].filter(Boolean).join(', ') },
			]
		},

		/**
		 * @return {boolean} Whether the request can be granted.
		 * @spec openspec/specs/compliance-exemptions/spec.md
		 */
		canGrant() {
			return canGrantRegulationExemption(this.form)
		},

		/**
		 * @return {boolean} Whether the request can be rejected.
		 * @spec openspec/specs/compliance-exemptions/spec.md
		 */
		canReject() {
			return canRejectRegulationExemption(this.form)
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/compliance-exemptions/spec.md
		 */
		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	/**
	 * Read the request.
	 *
	 * @spec openspec/specs/compliance-exemptions/spec.md
	 */
	async created() {
		try {
			this.exemption = await readObject('regulation-exemption', this.objectId)
		} catch (error) {
			this.notice = requestRefusal(error, this.t('learniq', 'Not found.'), this.t('learniq', 'The request could not be read.'))
		}
	},

	methods: {
		/**
		 * Grant or reject the request, then close.
		 *
		 * @param {'grant'|'reject'} action The transition.
		 * @return {Promise<void>}
		 * @spec openspec/specs/compliance-exemptions/spec.md
		 */
		async decide(action) {
			this.busy = true
			this.notice = null
			try {
				await runTransition(this.objectId, action, decisionData(this.form))
				announceSaved('learniq:regulation-exemption:decided', { exemptionId: this.objectId, decision: action })
				this.$emit('close')
			} catch (error) {
				const title = action === 'grant' ? this.t('learniq', 'Not granted.') : this.t('learniq', 'Not rejected.')
				this.notice = requestRefusal(error, title, this.t('learniq', 'The decision could not be saved.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.regulation-exemption {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.regulation-exemption__pair {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.regulation-exemption__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
