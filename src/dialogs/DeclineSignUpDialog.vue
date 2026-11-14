<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 DeclineSignUpDialog: the "Reject" row action on Aanmeldverzoeken
 (learniq/LqTlAanmeldverzoeken). It asks the reason the learner reads and runs
 the Enrolment's `decline` transition.
 @spec openspec/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager
-->
<template>
	<NcDialog
		:open="true"
		:name="t('learniq', 'Reject sign-up')"
		size="normal"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<NcTextArea
			v-model="reason"
			:label="t('learniq', 'Reason')"
			:helperText="t('learniq', 'The learner reads this reason.')"
			:disabled="busy"
			data-testid="decline-sign-up-reason" />
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template #actions>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ t('learniq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || !ready"
				data-testid="decline-sign-up-confirm"
				@click="decline">
				{{ t('learniq', 'Reject') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcTextArea } from '@nextcloud/vue'
import { canDecline } from '../utils/signUpDecision.js'
import { declineSignUp } from '../utils/signUpDecisionRequest.js'

export default {
	name: 'DeclineSignUpDialog',

	components: { NcButton, NcDialog, NcNoteCard, NcTextArea },

	props: {
		/** The Enrolment's uuid. */
		objectId: { type: String, required: true },
	},

	emits: ['close'],

	data() {
		return { reason: '', busy: false, error: '' }
	},

	computed: {
		/**
		 * @return {boolean} Whether the reason can be sent.
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager
		 */
		ready() {
			return canDecline(this.reason)
		},
	},

	methods: {
		/**
		 * Run the decline transition and close; the list follows through its
		 * live-update subscription on the Enrolment collection.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager
		 */
		async decline() {
			this.busy = true
			this.error = ''
			try {
				await declineSignUp(this.objectId, this.reason)
				this.$emit('close')
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('learniq', 'The sign-up could not be rejected.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>
