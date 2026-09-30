<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 MyEvaluationsView: the learner's open course evaluations (route
 /my-evaluations, assessment-course-evaluation-answer-page).

 Lists the caller's open invitations from GET /api/evaluations/mine and
 answers one through POST /api/evaluations/{invitationId}/answer. The answers
 are stored without the learner's name; an answered invitation leaves the list.

 @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-learner-answers-an-evaluation
-->
<template>
	<div class="my-evaluations">
		<h2>{{ t('learniq', 'My evaluations') }}</h2>
		<p class="my-evaluations__intro">
			{{ t('learniq', 'Your answers are stored without your name.') }}
		</p>
		<NcNoteCard v-if="sent" type="success">
			{{ t('learniq', 'Thank you. Your answers are in.') }}
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else-if="current">
			<h3>{{ current.campaignName }}</h3>
			<p>{{ current.courseName }}</p>
			<EvaluationAnswerForm
				:campaignQuestions="current.questions"
				:busy="posting"
				@submit="send"
				@cancel="current = null" />
		</template>
		<NcEmptyContent
			v-else-if="invitations.length === 0"
			:name="t('learniq', 'No evaluations to fill in')"
			:description="
				t(
					'learniq',
					'When a course asks for your opinion, you find it here.',
				)
			" />
		<ul v-else class="my-evaluations__list">
			<li
				v-for="invitation in invitations"
				:key="invitation.invitationId"
				class="my-evaluations__item">
				<div>
					<strong>{{ invitation.campaignName }}</strong>
					<div>{{ invitation.courseName }}</div>
					<div class="my-evaluations__due">
						{{
							t('learniq', 'Open until {date}', {
								date: formatDate(invitation.closesAt),
							})
						}}
					</div>
				</div>
				<NcButton
					v-if="
						invitation.instrumentKind === 'external-form'
						&& invitation.externalFormUrl
					"
					:href="invitation.externalFormUrl"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('learniq', 'Open the form') }}
				</NcButton>
				<NcButton v-else variant="primary" @click="start(invitation)">
					{{ t('learniq', 'Fill in') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import EvaluationAnswerForm from '../components/evaluation/EvaluationAnswerForm.vue'

export default {
	name: 'MyEvaluationsView',

	components: {
		EvaluationAnswerForm,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			loading: true,
			posting: false,
			error: '',
			sent: false,
			invitations: [],
			current: null,
		}
	},

	/**
	 * Load the caller's open invitations.
	 *
	 * @return {Promise<void>}
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Fetch the open invitations.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/learniq/api/evaluations/mine'),
				)
				this.invitations = Array.isArray(data?.invitations)
					? data.invitations
					: []
			} catch {
				this.error = this.t('learniq', 'Could not load your evaluations.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the form for one invitation.
		 *
		 * @param {object} invitation The invitation.
		 * @return {void}
		 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
		 */
		start(invitation) {
			this.sent = false
			this.error = ''
			this.current = invitation
		},

		/**
		 * Post the answers, then reload the list.
		 *
		 * @param {{answers: object}} body The answers.
		 * @return {Promise<void>}
		 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-cannot-answer-twice
		 */
		async send(body) {
			this.posting = true
			this.error = ''
			try {
				await axios.post(
					generateUrl('/apps/learniq/api/evaluations/{id}/answer', {
						id: this.current.invitationId,
					}),
					body,
				)
				this.sent = true
				this.current = null
				await this.load()
			} catch (e) {
				this.error = this.sendError(e?.response?.status)
			} finally {
				this.posting = false
			}
		},

		/**
		 * Why the answers were not taken, in the reader's language.
		 *
		 * @param {number|undefined} status The HTTP status of the refusal.
		 * @return {string}
		 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-cannot-answer-twice
		 */
		sendError(status) {
			if (status === 409) {
				return this.t(
					'learniq',
					'This evaluation is closed or you already answered it.',
				)
			}
			if (status === 422) {
				return this.t('learniq', 'Answer every required question first.')
			}
			if (status === 403 || status === 404) {
				return this.t(
					'learniq',
					'You have no open invitation for this evaluation.',
				)
			}
			return this.t('learniq', 'Your answers could not be sent.')
		},

		/**
		 * A closing date as a short local date.
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string}
		 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
		 */
		formatDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.my-evaluations {
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 720px;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.my-evaluations__intro,
.my-evaluations__due {
	color: var(--color-text-maxcontrast);
}

.my-evaluations__list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.my-evaluations__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}
</style>
