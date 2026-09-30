<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 EvaluationCampaignResultsView: a campaign's figures for staff (route
 /course-evaluation/campaigns/:campaignId/results,
 assessment-course-evaluation-answer-page).

 Reads GET /api/evaluations/campaigns/{campaignId}/results: invitations,
 responses and the mean overall score. Under five responses the mean is not
 shown, because it would point at the people who answered (design D2).

 @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-campaign-results-for-staff
-->
<template>
	<div class="campaign-results">
		<h2>{{ t('learniq', 'Evaluation results') }}</h2>
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<dl v-else class="campaign-results__figures">
			<dt>{{ t('learniq', 'Invited') }}</dt>
			<dd>{{ figures.invitations }}</dd>
			<dt>{{ t('learniq', 'Answered') }}</dt>
			<dd>{{ figures.responses }}</dd>
			<dt>{{ t('learniq', 'Average overall score') }}</dt>
			<dd v-if="figures.hidden">
				{{
					t(
						'learniq',
						'Shown from 5 answers, so nobody can be recognised.',
					)
				}}
			</dd>
			<dd v-else>
				{{ figures.mean }}
			</dd>
		</dl>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { resultFigures } from '../utils/evaluationAnswers.js'

export default {
	name: 'EvaluationCampaignResultsView',

	components: { NcLoadingIcon, NcNoteCard },

	props: {
		/** Campaign UUID from the route :campaignId param. */
		campaignId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			results: null,
		}
	},

	computed: {
		/**
		 * The figures to show.
		 *
		 * @return {object}
		 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-small-groups-are-protected
		 */
		figures() {
			return resultFigures(this.results)
		},
	},

	/**
	 * Load the campaign figures.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-small-groups-are-protected
	 */
	async mounted() {
		try {
			const { data } = await axios.get(
				generateUrl('/apps/learniq/api/evaluations/campaigns/{id}/results', {
					id: this.campaignId,
				}),
			)
			this.results = data
		} catch (e) {
			this.error =
				e?.response?.status === 403
					? this.t('learniq', 'Only staff can read evaluation results.')
					: this.t('learniq', 'Could not load the results.')
		} finally {
			this.loading = false
		}
	},
}
</script>

<style scoped>
.campaign-results {
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 720px;
}

.campaign-results__figures {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: calc(var(--default-grid-baseline) * 2)
		calc(var(--default-grid-baseline) * 6);
}

.campaign-results__figures dt {
	font-weight: bold;
}
</style>
