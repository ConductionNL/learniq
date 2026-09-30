<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 EvaluationAnswerForm: the questions of one course evaluation invitation
 (assessment-course-evaluation-answer-page). A rating question is five
 radio buttons, a free-text question a text area. Emits `submit` with the
 body to post; the parent posts it.

 @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
-->
<template>
	<form class="evaluation-form" @submit.prevent="submit">
		<fieldset
			v-for="question in questions"
			:key="question.questionId"
			class="evaluation-form__question">
			<legend>
				{{ textOf(question) }}
				<span v-if="question.required" class="evaluation-form__required">
					{{ t('learniq', '(required)') }}
				</span>
			</legend>
			<div
				v-if="question.kind === 'likert-5'"
				class="evaluation-form__scale"
				role="radiogroup">
				<NcCheckboxRadioSwitch
					v-for="rating in [1, 2, 3, 4, 5]"
					:key="rating"
					v-model="answers[question.questionId]"
					type="radio"
					:value="String(rating)"
					:name="'q-' + question.questionId">
					{{ rating }}
				</NcCheckboxRadioSwitch>
			</div>
			<NcTextArea
				v-else
				v-model="answers[question.questionId]"
				:label="t('learniq', 'Your answer')"
				resize="vertical" />
		</fieldset>
		<p class="evaluation-form__scale-hint">
			{{
				t(
					'learniq',
					'1 means you disagree completely, 5 that you agree completely.',
				)
			}}
		</p>
		<NcNoteCard v-if="showMissing && missing.length > 0" type="warning">
			{{ t('learniq', 'Answer every required question first.') }}
		</NcNoteCard>
		<div class="evaluation-form__actions">
			<NcButton type="submit" variant="primary" :disabled="busy">
				{{ t('learniq', 'Send my answers') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('cancel')">
				{{ t('learniq', 'Cancel') }}
			</NcButton>
		</div>
	</form>
</template>

<script>
import { getLanguage } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcTextArea,
} from '@nextcloud/vue'
import {
	answerBody,
	formQuestions,
	missingRequired,
	questionText,
} from '../../utils/evaluationAnswers.js'

export default {
	name: 'EvaluationAnswerForm',

	components: { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcTextArea },

	props: {
		/** The campaign questions of the invitation. */
		campaignQuestions: {
			type: Array,
			default: () => [],
		},

		/** True while the parent posts the answers. */
		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['submit', 'cancel'],

	data() {
		return {
			answers: {},
			showMissing: false,
		}
	},

	computed: {
		/**
		 * The questions to render.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
		 */
		questions() {
			return formQuestions(this.campaignQuestions)
		},

		/**
		 * Required questions still unanswered.
		 *
		 * @return {string[]}
		 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
		 */
		missing() {
			return missingRequired(this.campaignQuestions, this.answers)
		},
	},

	methods: {
		/**
		 * A question's text in the reader's language.
		 *
		 * @param {object} question The question.
		 * @return {string}
		 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
		 */
		textOf(question) {
			return questionText(question, getLanguage())
		},

		/**
		 * Emit the body to post, or show which questions still need an answer.
		 *
		 * @return {void}
		 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
		 */
		submit() {
			if (this.missing.length > 0) {
				this.showMissing = true
				return
			}
			this.$emit('submit', answerBody(this.campaignQuestions, this.answers))
		},
	},
}
</script>

<style scoped>
.evaluation-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
}

.evaluation-form__question {
	border: none;
	padding: 0;
	margin: 0;
}

.evaluation-form__question legend {
	font-weight: bold;
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.evaluation-form__required {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.evaluation-form__scale {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 3);
}

.evaluation-form__scale-hint {
	color: var(--color-text-maxcontrast);
}

.evaluation-form__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
