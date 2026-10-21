<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SignatureView: sign a learning plan or a praktijkovereenkomst
 (routes /learning-plans/:planId/sign and
 /bpv/praktijkovereenkomsten/:pokId/sign, learniq#947).

 The page config names the subject (config.subject). CnSignatureCapture takes
 a typed or drawn signature plus an affirmation; saving creates one
 append-only Signature (plan) or PokSignature (POK) for the current user in
 a role they hold, at the subject's current version. The sign endpoint
 offers the roles and sets signer, time, version and tenant. Whether enough
 signatures exist is decided by the subject's own activate transition
 (LearningPlanSignatureGuard, PokActivationGuard), not here.

 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<div class="signature-view">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<template v-else>
			<h2>{{ heading }}</h2>
			<p class="signature-view__version">
				{{
					t('learniq', 'Version {version}', {
						version: subjectData.version || 1,
					})
				}}
			</p>

			<NcNoteCard v-if="parentNeeded" type="info">
				{{
					t(
						'learniq',
						'A parent or guardian also signs this agreement. The student is under 18, or their date of birth is not recorded.',
					)
				}}
			</NcNoteCard>

			<NcNoteCard v-if="!signable" type="info">
				{{ t('learniq', 'This document can no longer be signed.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="!roleOptions.length" type="info">
				{{ t('learniq', 'You are not one of the people who sign this document.') }}
			</NcNoteCard>

			<NcSelect
				v-if="signable && roleOptions.length"
				v-model="signerRole"
				:options="roleOptions"
				:reduce="(option) => option.id"
				:clearable="false"
				:inputLabel="t('learniq', 'I sign as')" />

			<CnSignatureCapture
				:affirmation="
					t('learniq', 'I have read this document and agree with it.')
				"
				:typedPlaceholder="t('learniq', 'Type your full name')"
				:typedAriaLabel="t('learniq', 'Typed signature')"
				:drawnAriaLabel="t('learniq', 'Signature drawing area')"
				:drawnHint="
					t(
						'learniq',
						'Draw your signature with the mouse, a finger or a pen.',
					)
				"
				:typedModeLabel="t('learniq', 'Type')"
				:drawnModeLabel="t('learniq', 'Draw')"
				:clearLabel="t('learniq', 'Clear')"
				@change="capture = $event" />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="signed" type="success">
				{{ t('learniq', 'Your signature is saved.') }}
			</NcNoteCard>

			<div class="signature-view__actions">
				<NcButton
					variant="primary"
					:disabled="saving || signed || !signable || !canSign"
					@click="sign">
					{{ t('learniq', 'Sign') }}
				</NcButton>
				<NcButton variant="tertiary" @click="back">
					{{ t('learniq', 'Back') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { CnSignatureCapture } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import {
	objectsUrl,
	oneObject,
	parentSignatureNeeded,
	SIGNABLE_SUBJECTS,
	signUrl,
} from '../utils/customPages.js'

export default {
	name: 'SignatureView',

	components: {
		CnSignatureCapture,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** Which subject this page signs: 'learning-plan' or 'praktijkovereenkomst'. */
		subject: { type: String, default: 'learning-plan' },
		/** LearningPlan UUID (learning-plan route). */
		planId: { type: String, default: '' },
		/** Praktijkovereenkomst UUID (POK route). */
		pokId: { type: String, default: '' },
	},

	data() {
		return {
			loading: true,
			loadError: '',
			subjectObject: {},
			heldRoles: [],
			signable: true,
			signerRole: '',
			capture: null,
			saving: false,
			signed: false,
			error: '',
		}
	},

	computed: {
		/**
		 * @return {object} The subject's schema slugs and roles.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		spec() {
			return (
				SIGNABLE_SUBJECTS[this.subject] ?? SIGNABLE_SUBJECTS['learning-plan']
			)
		},

		/**
		 * @return {string} UUID of the subject being signed.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		subjectId() {
			return this.subject === 'praktijkovereenkomst' ? this.pokId : this.planId
		},

		/**
		 * The loaded object, named for the template.
		 *
		 * @return {object} The subject.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		subjectData() {
			return this.subjectObject
		},

		/**
		 * @return {string} The page heading.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		heading() {
			return this.subject === 'praktijkovereenkomst'
				? this.t('learniq', 'Sign the work placement agreement')
				: this.t('learniq', 'Sign the learning plan')
		},

		/**
		 * @return {boolean} Whether a parent or guardian also signs this subject.
		 * @spec openspec/specs/bpv/spec.md#scenario-the-signing-flow-asks-for-the-parent
		 */
		parentNeeded() {
			return parentSignatureNeeded(this.subject, this.subjectObject)
		},

		/**
		 * @return {Array<{id: string, label: string}>} The roles the user holds, as options.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		roleOptions() {
			const labels = {
				learner: this.t('learniq', 'Learner'),
				student: this.t('learniq', 'Student'),
				parent: this.t('learniq', 'Parent or guardian'),
				coordinator: this.t('learniq', 'Coordinator'),
				teacher: this.t('learniq', 'Teacher'),
				school: this.t('learniq', 'School'),
			}
			return this.heldRoles
				.filter((id) => this.spec.roles.includes(id))
				.map((id) => ({ id, label: labels[id] }))
		},

		/**
		 * @return {boolean} Whether a complete, affirmed signature is captured.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		canSign() {
			return Boolean(
				this.capture?.value && this.capture?.affirmed && this.signerRole,
			)
		},
	},

	/**
	 * Load the object that is signed and the roles the user holds on it.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
	 */
	async mounted() {
		try {
			const [subject, roles] = await Promise.all([
				axios.get(
					generateUrl(objectsUrl(this.spec.subjectSchema, this.subjectId)),
				),
				axios.get(generateUrl(signUrl(this.subject, this.subjectId))),
			])
			this.subjectObject = oneObject(subject.data)
			this.heldRoles = roles.data?.roles ?? []
			this.signable = roles.data?.signable !== false
			this.signerRole = this.roleOptions[0]?.id ?? ''
		} catch {
			this.loadError = this.t('learniq', 'This document could not be loaded.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Save the signature.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async sign() {
			this.saving = true
			this.error = ''
			try {
				await axios.post(generateUrl(signUrl(this.subject, this.subjectId)), {
					role: this.signerRole,
					capture: this.capture,
				})
				this.signed = true
			} catch (e) {
				this.error = this.refusalText(e?.response?.data?.reason)
			} finally {
				this.saving = false
			}
		},

		/**
		 * The message for a refused signature.
		 *
		 * @param {string|undefined} reason The server's reason.
		 * @return {string} The message.
		 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
		 */
		refusalText(reason) {
			const texts = {
				'role-not-held': this.t('learniq', 'You cannot sign this document in that role.'),
				'already-signed': this.t('learniq', 'You have already signed this version in that role.'),
				'not-signable': this.t('learniq', 'This document can no longer be signed.'),
				incomplete: this.t('learniq', 'Sign and confirm that you agree before saving.'),
			}
			return texts[reason] ?? this.t('learniq', 'Your signature could not be saved.')
		},

		/**
		 * @return {void}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		back() {
			const name =
				this.subject === 'praktijkovereenkomst'
					? 'PraktijkovereenkomstDetail'
					: 'LearningPlanDetail'
			this.$router
				.push({ name, params: { id: this.subjectId } })
				.catch(() => {})
		},
	},
}
</script>

<style scoped>
.signature-view {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-inline-size: 40rem;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.signature-view__version {
	color: var(--color-text-maxcontrast);
}

.signature-view__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>
