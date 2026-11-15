<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 CredentialRevokeDialog: "Certificaat intrekken" (learniq/LqCertificaten).
 Names the holder, the certificate and its validity, asks a reason (required)
 and an explanation, and runs the Credential `revoke` transition. The
 verification page then shows the certificate as invalid and a wallet copy is
 revoked too; it cannot be undone.
 @spec openspec/specs/certification/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'Revoke certificate')"
		:eyebrow="eyebrow"
		width="form"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="credential-revoke-dialog"
		@closing="$emit('close')">
		<div class="revoke">
			<DialogFacts :facts="facts" />
			<div class="revoke__field">
				<NcSelect
					v-model="reason"
					:inputLabel="t('learniq', 'Reason')"
					:placeholder="t('learniq', 'Choose a reason')"
					:options="reasonOptions"
					:reduce="(option) => option.value"
					:clearable="false"
					:disabled="busy"
					label="label"
					data-testid="credential-revoke-reason" />
				<span class="revoke__muted">{{ t('learniq', 'Required. The reason goes into the history and onto the verification page.') }}</span>
			</div>
			<NcTextArea
				v-model="explanation"
				:label="t('learniq', 'Explanation (optional)')"
				:disabled="busy"
				rows="3"
				data-testid="credential-revoke-explanation" />
			<p class="revoke__warning">
				{{ t('learniq', 'After revoking, the verification page shows this certificate as invalid. If {name} has it in a wallet, that copy is revoked too. You cannot undo this.', { name: holder || t('learniq', 'the holder') }) }}
			</p>
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<CnDialogActions
				:saving="busy"
				:canSubmit="Boolean(reason)"
				:confirmLabel="t('learniq', 'Revoke certificate')"
				:cancelLabel="t('learniq', 'Cancel')"
				variant="error"
				@cancel="$emit('close')"
				@submit="revoke" />
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnDialogActions } from '@conduction/nextcloud-vue'
import { getLanguage } from '@nextcloud/l10n'
import { NcSelect, NcTextArea } from '@nextcloud/vue'
import DialogFacts from './DialogFacts.vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import { dayLabel, revocationReason, REVOKE_REASONS } from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, readObject, runTransition } from './dialogRequests.js'

export default {
	name: 'CredentialRevokeDialog',

	components: { CnDialog, CnDialogActions, DialogFacts, DialogNoticeFallback, NcSelect, NcTextArea },

	props: {
		/** The Credential's uuid. */
		objectId: { type: String, required: true },
	},

	emits: ['close'],

	data() {
		return { credential: {}, reason: null, explanation: '', busy: false, notice: null }
	},

	computed: {
		/**
		 * @return {string} The holder's name.
		 * @spec openspec/specs/certification/spec.md
		 */
		holder() {
			return this.credential.learnerName || ''
		},

		/**
		 * @return {string} "Certificaat, <holder>".
		 * @spec openspec/specs/certification/spec.md
		 */
		eyebrow() {
			return this.holder ? this.t('learniq', 'Certificate, {name}', { name: this.holder }) : this.t('learniq', 'Certificate')
		},

		/**
		 * @return {object[]} Who holds what, and until when.
		 * @spec openspec/specs/certification/spec.md
		 */
		facts() {
			const issued = dayLabel(this.credential.issuedAt, getLanguage())
			const name = this.credential.courseName || ''
			return [
				{ label: this.t('learniq', 'Employee'), value: this.holder },
				{ label: this.t('learniq', 'Certificate'), value: issued ? this.t('learniq', '{name}, issued on {date}', { name, date: issued }) : name },
				{ label: this.t('learniq', 'Valid until'), value: dayLabel(this.credential.expiresAt, getLanguage()) },
			]
		},

		/**
		 * @return {object[]} The reasons a certificate is withdrawn for.
		 * @spec openspec/specs/certification/spec.md
		 */
		reasonOptions() {
			const labels = {
				'issued-in-error': this.t('learniq', 'Issued in error'),
				'requirements-not-met': this.t('learniq', 'The requirements were not met'),
				fraud: this.t('learniq', 'Fraud'),
				replaced: this.t('learniq', 'Replaced by a new certificate'),
				other: this.t('learniq', 'Another reason'),
			}
			return REVOKE_REASONS.map((value) => ({ value, label: labels[value] }))
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/certification/spec.md
		 */
		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	/**
	 * Read the certificate the dialog revokes.
	 *
	 * @spec openspec/specs/certification/spec.md
	 */
	async created() {
		try {
			this.credential = await readObject('credential', this.objectId)
		} catch (error) {
			this.notice = requestRefusal(error, this.t('learniq', 'Not found.'), this.t('learniq', 'The certificate could not be read.'))
		}
	},

	methods: {
		/**
		 * Run the revoke transition with the reason, then close.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/certification/spec.md
		 */
		async revoke() {
			this.busy = true
			this.notice = null
			const label = this.reasonOptions.find((option) => option.value === this.reason)?.label ?? ''
			try {
				await runTransition(this.objectId, 'revoke', { revocationReason: revocationReason(label, this.explanation) })
				announceSaved('learniq:credential:revoked', { credentialId: this.objectId })
				this.$emit('close')
			} catch (error) {
				this.notice = requestRefusal(error, this.t('learniq', 'Not revoked.'), this.t('learniq', 'The certificate could not be revoked.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.revoke {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.revoke__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.revoke__muted {
	color: var(--color-text-maxcontrast);
}

.revoke__warning {
	margin: 0;
	padding: 10px 14px;
	border-radius: var(--border-radius-large);
	background: var(--color-warning-hover, var(--color-background-hover));
}
</style>
