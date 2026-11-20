<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 ExemptionDecisionDialog: "Besluit over het verzoek" (learniq/LqExZaken).
 The exam board decides one ExemptionCase: grant or reject as two cards, the
 rationale and the article of the exam regulation. A case still waiting is
 taken into assessment first; ExemptionDecisionGuard refuses anyone outside
 the exam board.
 @spec openspec/specs/exam-board/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'Decision on the request')"
		:eyebrow="eyebrow"
		width="form"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="exemption-decision-dialog"
		@closing="$emit('close')">
		<div class="exemption-decision">
			<DialogFacts :facts="facts" />
			<CnChoiceCards
				v-model="form.decision"
				:label="t('learniq', 'Decision')"
				:options="decisionOptions"
				:columns="2"
				:disabled="busy"
				data-testid="exemption-decision-choice" />
			<NcTextArea
				v-model="form.decisionRationale"
				:label="t('learniq', 'Rationale')"
				:disabled="busy"
				rows="4"
				data-testid="exemption-decision-rationale" />
			<NcTextField
				v-model="form.policyReference"
				:label="t('learniq', 'Article in the exam regulation')"
				:disabled="busy"
				data-testid="exemption-decision-article" />
			<p class="exemption-decision__muted">
				{{ consequence }}
			</p>
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<CnDialogActions
				:saving="busy"
				:canSubmit="ready"
				:confirmLabel="form.decision === 'reject' ? t('learniq', 'Reject the request') : t('learniq', 'Grant exemption')"
				:cancelLabel="t('learniq', 'Cancel')"
				@cancel="$emit('close')"
				@submit="decide" />
		</template>
	</CnDialog>
</template>

<script>
import { CnChoiceCards, CnDialog, CnDialogActions } from '@conduction/nextcloud-vue'
import { getLanguage } from '@nextcloud/l10n'
import { NcTextArea, NcTextField } from '@nextcloud/vue'
import DialogFacts from './DialogFacts.vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import { profileName } from '../utils/learnerName.js'
import { canDecideExemption, dayLabel, decisionData, exemptionSteps } from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, readObject, runTransition } from './dialogRequests.js'

export default {
	name: 'ExemptionDecisionDialog',

	components: { CnChoiceCards, CnDialog, CnDialogActions, DialogFacts, DialogNoticeFallback, NcTextArea, NcTextField },

	props: {
		/** The ExemptionCase's uuid. */
		objectId: { type: String, required: true },
	},

	emits: ['close'],

	data() {
		return {
			exemption: {},
			learner: {},
			plan: {},
			form: { decision: 'grant', decisionRationale: '', policyReference: '' },
			busy: false,
			notice: null,
		}
	},

	computed: {
		/**
		 * @return {string} The pupil's name.
		 * @spec openspec/specs/exam-board/spec.md
		 */
		learnerName() {
			return profileName(this.learner)
		},

		/**
		 * @return {string} "Vrijstellingsverzoek, <pupil>".
		 * @spec openspec/specs/exam-board/spec.md
		 */
		eyebrow() {
			return this.learnerName ? this.t('learniq', 'Exemption request, {name}', { name: this.learnerName }) : this.t('learniq', 'Exemption request')
		},

		/**
		 * @return {string} The plan component the request is about.
		 * @spec openspec/specs/exam-board/spec.md
		 */
		componentLabel() {
			const component = (this.plan.components ?? []).find((entry) => entry.componentId === this.exemption.componentId)
			const part = component?.label || this.exemption.componentId || ''
			return this.plan.name ? `${part} (${this.plan.name})` : part
		},

		/**
		 * @return {object[]} Who asks what, on which grounds, since when.
		 * @spec openspec/specs/exam-board/spec.md
		 */
		facts() {
			const grounds = this.groundsText()
			return [
				{ label: this.t('learniq', 'Pupil'), value: [this.learnerName, this.learner.groupLabel].filter(Boolean).join(', ') },
				{ label: this.t('learniq', 'Part'), value: this.componentLabel },
				{ label: this.t('learniq', 'Grounds'), value: grounds },
				{ label: this.t('learniq', 'Submitted'), value: dayLabel(this.exemption.submittedAt, getLanguage()) },
			]
		},

		/**
		 * @return {object[]} Grant or reject as cards.
		 * @spec openspec/specs/exam-board/spec.md
		 */
		decisionOptions() {
			const name = this.learnerName || this.t('learniq', 'the pupil')
			return [
				{ value: 'grant', label: this.t('learniq', 'Grant'), description: this.t('learniq', 'The part counts as exempted') },
				{ value: 'reject', label: this.t('learniq', 'Reject'), description: this.t('learniq', '{name} takes part as usual', { name }) },
			]
		},

		/**
		 * @return {string} What the decision does.
		 * @spec openspec/specs/exam-board/spec.md
		 */
		consequence() {
			const name = this.learnerName || this.t('learniq', 'the pupil')
			return this.form.decision === 'reject'
				? this.t('learniq', '{name} and the parents get the decision with the rationale.', { name })
				: this.t('learniq', 'When granted, the grade overview of {name} gets an exemption line for this part. {name} and the parents get the decision with the rationale.', { name })
		},

		/**
		 * @return {boolean} Whether the decision can be sent.
		 * @spec openspec/specs/exam-board/spec.md
		 */
		ready() {
			return canDecideExemption(this.form)
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/exam-board/spec.md
		 */
		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	/**
	 * Read the case, the pupil and the plan.
	 *
	 * @spec openspec/specs/exam-board/spec.md
	 */
	async created() {
		try {
			this.exemption = await readObject('exemption-case', this.objectId)
		} catch (error) {
			this.notice = requestRefusal(error, this.t('learniq', 'Not found.'), this.t('learniq', 'The request could not be read.'))
			return
		}
		await this.readLearnerAndPlan()
	},

	methods: {
		/**
		 * Read the case's pupil and plan; one that is not named or cannot be
		 * read stays empty.
		 *
		 * @return {Promise<void>}
		 */
		async readLearnerAndPlan() {
			const [learner, plan] = await Promise.allSettled([
				this.exemption.learnerId ? readObject('learner-profile', this.exemption.learnerId) : Promise.resolve({}),
				this.exemption.curriculumPlanId ? readObject('curriculum-plan', this.exemption.curriculumPlanId) : Promise.resolve({}),
			])
			this.learner = learner.value ?? {}
			this.plan = plan.value ?? {}
		},

		/**
		 * @return {string} The grounds in words: "kind: description", or whichever is there.
		 */
		groundsText() {
			const kind = this.groundsKindLabel()
			const description = this.exemption.groundsDescription ?? ''
			return kind && description ? `${kind}: ${description}` : kind || description
		},

		/**
		 * @return {string} The kind of grounds in words, '' when unknown.
		 */
		groundsKindLabel() {
			const grounds = {
				'prior-diploma': this.t('learniq', 'Prior diploma'),
				certificate: this.t('learniq', 'Certificate'),
				'work-experience': this.t('learniq', 'Work experience'),
				other: this.t('learniq', 'Other'),
			}
			return grounds[this.exemption.groundsKind] ?? ''
		},

		/**
		 * Run the decision (after startAssessment when the case still waits) and close.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/exam-board/spec.md
		 */
		async decide() {
			this.busy = true
			this.notice = null
			try {
				for (const step of exemptionSteps(this.exemption.lifecycle, this.form.decision)) {
					await runTransition(this.objectId, step, step === 'startAssessment' ? {} : decisionData(this.form))
					if (step === 'startAssessment') {
						this.exemption = { ...this.exemption, lifecycle: 'in-assessment' }
					}
				}
				announceSaved('learniq:exemption-case:decided', { caseId: this.objectId, decision: this.form.decision })
				this.$emit('close')
			} catch (error) {
				this.notice = requestRefusal(error, this.t('learniq', 'Not decided.'), this.t('learniq', 'The decision could not be saved.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.exemption-decision {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.exemption-decision__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
