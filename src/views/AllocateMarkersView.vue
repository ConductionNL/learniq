<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AllocateMarkersView: the teacher in charge names the markers of a
 double-marked assignment (route /assignments/:assignmentId/markers,
 assignments-double-marking).

 Pick up to markersPerSubmission markers, for every handed-in submission or
 for one. learniq's POST /api/assignments/{id}/markers creates one draft
 SubmissionMark per marker and hand-in, never twice, and refuses a marker who
 is one of the hand-in's own learners; each refusal is listed here.

 @spec openspec/specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers
-->
<template>
	<div class="allocate-markers">
		<h2>{{ t('learniq', 'Allocate markers') }}</h2>
		<NcNoteCard v-if="assignment && maxMarkers < 2" type="info">
			{{
				t(
					'learniq',
					'This assignment has one marker per hand-in. Set more markers per submission on the assignment first.',
				)
			}}
		</NcNoteCard>
		<form
			v-else-if="assignment"
			class="allocate-markers__form"
			@submit.prevent="allocate">
			<p class="allocate-markers__intro">
				{{
					t(
						'learniq',
						'Each hand-in of {title} is marked by up to {max} markers on their own.',
						{ title: assignment.title || '', max: maxMarkers },
					)
				}}
			</p>
			<NcSelect
				v-model="markers"
				:options="userOptions"
				:multiple="true"
				:filterable="false"
				:loading="searching"
				:getOptionLabel="(u) => u.label || u.id"
				:inputLabel="t('learniq', 'Markers')"
				:placeholder="t('learniq', 'Search by name')"
				@search="searchUsers" />
			<NcSelect
				v-model="submission"
				:options="submissionOptions"
				:getOptionLabel="submissionLabel"
				:inputLabel="
					t('learniq', 'Hand-in (leave empty for every hand-in)')
				" />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="result" type="success">
				{{
					n(
						'learniq',
						'%n mark allocated.',
						'%n marks allocated.',
						result.createdCount,
					)
				}}
			</NcNoteCard>
			<NcNoteCard v-if="result && result.refused.length > 0" type="warning">
				<ul>
					<li
						v-for="item in result.refused"
						:key="item.submissionId + item.markerId">
						{{ refusalText(item) }}
					</li>
				</ul>
			</NcNoteCard>

			<div class="allocate-markers__actions">
				<NcButton
					type="submit"
					variant="primary"
					:disabled="
						saving || markers.length === 0 || markers.length > maxMarkers
					">
					{{ t('learniq', 'Allocate') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { listRows, objectId, objectsUrl, oneObject } from '../utils/customPages.js'

export default {
	name: 'AllocateMarkersView',

	components: { NcButton, NcNoteCard, NcSelect },

	props: {
		/** Assignment UUID from the route. */
		assignmentId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			assignment: null,
			submissions: [],
			markers: [],
			submission: null,
			userOptions: [],
			searching: false,
			saving: false,
			error: null,
			result: null,
		}
	},

	computed: {
		/**
		 * @return {number} The assignment's markers per submission.
		 * @spec openspec/specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers
		 */
		maxMarkers() {
			return Number(this.assignment?.markersPerSubmission ?? 1)
		},

		/**
		 * @return {Array<object>} The handed-in submissions.
		 * @spec openspec/specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers
		 */
		submissionOptions() {
			return this.submissions.filter((s) =>
				['submitted', 'late'].includes(s.lifecycle),
			)
		},
	},

	/**
	 * Load the assignment and its submissions, and the first marker options.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers
	 */
	async mounted() {
		try {
			this.assignment = oneObject(
				(
					await axios.get(
						generateUrl(objectsUrl('assignment', this.assignmentId)),
					)
				).data,
			)
			this.submissions = listRows(
				(
					await axios.get(generateUrl(objectsUrl('submission')), {
						params: { assignmentId: this.assignmentId, _limit: 500 },
					})
				).data,
			).map((s) => ({ ...s, id: objectId(s) }))
		} catch {
			this.error = this.t('learniq', 'The assignment could not be loaded.')
		}
		this.searchUsers('')
	},

	methods: {
		/**
		 * @param {object} s A Submission.
		 * @return {string} The learners of the hand-in.
		 * @spec openspec/specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers
		 */
		submissionLabel(s) {
			return (s.learnerIds || []).join(', ') || s.id
		},

		/**
		 * Search Nextcloud accounts through the core autocomplete.
		 *
		 * @param {string} query Typed text.
		 * @return {Promise<void>}
		 * @spec openspec/specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers
		 */
		async searchUsers(query) {
			this.searching = true
			try {
				const resp = await axios.get(
					generateOcsUrl('core/autocomplete/get'),
					{
						params: {
							search: query || '',
							itemType: ' ',
							itemId: ' ',
							'shareTypes[]': 0,
							limit: 20,
						},
					},
				)
				this.userOptions = resp.data?.ocs?.data ?? []
			} catch {
				this.userOptions = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * @param {object} item A refusal from the allocation.
		 * @return {string} The refusal in words.
		 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-cannot-mark-their-own-group-work
		 */
		refusalText(item) {
			const hand = this.submissionLabel(
				this.submissions.find((s) => s.id === item.submissionId) || {
					id: item.submissionId,
				},
			)
			if (item.reason === 'marker-is-learner') {
				return this.t(
					'learniq',
					'{marker} is a learner of the hand-in of {learners} and cannot mark it.',
					{ marker: item.markerId, learners: hand },
				)
			}
			return this.t(
				'learniq',
				'The hand-in of {learners} already has all its markers, so {marker} was not added.',
				{ marker: item.markerId, learners: hand },
			)
		},

		/**
		 * Allocate the chosen markers.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assignments/spec.md#scenario-a-coordinator-allocates-two-markers-to-every-hand-in
		 */
		async allocate() {
			this.saving = true
			this.error = null
			this.result = null
			try {
				const resp = await axios.post(
					generateUrl(
						`/apps/learniq/api/assignments/${this.assignmentId}/markers`,
					),
					{
						markerIds: this.markers.map((u) => u.id),
						submissionId: this.submission?.id ?? '',
					},
				)
				this.result = resp.data
			} catch {
				this.error = this.t('learniq', 'The markers could not be allocated.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.allocate-markers {
	max-width: 720px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

.allocate-markers__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.allocate-markers__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
