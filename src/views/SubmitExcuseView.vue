<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SubmitExcuseView: record an absence excuse for a learner
 (route /attendance/excuses/submit, learniq#947).

 A staff form: pick the learner, the dates, the kind of reason and a short
 explanation. It creates an ExcuseRequest in state submitted, with the
 current user as submitter and assurance level basic (a Nextcloud login).
 Parents and 18+ learners report absence through the portal instead.

 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<div class="submit-excuse">
		<h2>{{ t('learniq', 'Record an absence excuse') }}</h2>
		<form class="submit-excuse__form" @submit.prevent="submit">
			<NcSelect
				v-model="learner"
				:options="learnerOptions"
				:loading="searching"
				:filterable="false"
				:getOptionLabel="learnerLabel"
				:inputLabel="t('learniq', 'Learner')"
				:placeholder="t('learniq', 'Search by name')"
				@search="searchLearners" />

			<label for="se-from">{{ t('learniq', 'First day absent') }}</label>
			<input id="se-from" v-model="dateFrom" type="date" required />

			<label for="se-to">{{ t('learniq', 'Last day absent') }}</label>
			<input id="se-to" v-model="dateTo" type="date" required />

			<NcSelect
				v-model="reasonKind"
				:options="reasonOptions"
				:reduce="(option) => option.id"
				:clearable="false"
				:inputLabel="t('learniq', 'Kind of reason')" />

			<label for="se-reason">{{ t('learniq', 'Explanation') }}</label>
			<textarea id="se-reason" v-model="reason" rows="3" required />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="createdId" type="success">
				{{
					t('learniq', 'The excuse is recorded and waits for a decision.')
				}}
			</NcNoteCard>

			<div class="submit-excuse__actions">
				<NcButton type="submit" variant="primary" :disabled="saving">
					{{ t('learniq', 'Record the excuse') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect } from '@nextcloud/vue'
import {
	EXCUSE_REASON_KINDS,
	listRows,
	objectId,
	objectsUrl,
	oneObject,
} from '../utils/customPages.js'

export default {
	name: 'SubmitExcuseView',

	components: { NcButton, NcNoteCard, NcSelect },

	data() {
		return {
			learner: null,
			learnerOptions: [],
			searching: false,
			dateFrom: '',
			dateTo: '',
			reasonKind: 'illness',
			reason: '',
			saving: false,
			error: '',
			createdId: '',
		}
	},

	computed: {
		/**
		 * @return {Array<{id: string, label: string}>} Reason options.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		reasonOptions() {
			const labels = {
				illness: this.t('learniq', 'Illness'),
				'medical-appointment': this.t('learniq', 'Medical appointment'),
				'family-circumstance': this.t('learniq', 'Family circumstance'),
				'religious-observance': this.t('learniq', 'Religious observance'),
				bereavement: this.t('learniq', 'Bereavement'),
				other: this.t('learniq', 'Other'),
			}
			return EXCUSE_REASON_KINDS.map((id) => ({ id, label: labels[id] ?? id }))
		},
	},

	mounted() {
		this.searchLearners('')
	},

	methods: {
		/**
		 * @param {object} p A LearnerProfile.
		 * @return {string} The name.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		learnerLabel(p) {
			return (
				[p.givenName, p.familyName].filter(Boolean).join(' ')
				|| p.ncUserId
				|| objectId(p)
			)
		},

		/**
		 * @param {string} query Typed text.
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async searchLearners(query) {
			this.searching = true
			try {
				this.learnerOptions = listRows(
					(
						await axios.get(generateUrl(objectsUrl('learner-profile')), {
							params: { _search: query || undefined, _limit: 50 },
						})
					).data,
				)
			} catch {
				this.learnerOptions = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * Create the ExcuseRequest.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async submit() {
			this.error = ''
			this.createdId = ''
			if (!this.learner?.ncUserId) {
				this.error = this.t(
					'learniq',
					'Pick a learner who has a Nextcloud account.',
				)
				return
			}
			if (this.dateTo < this.dateFrom) {
				this.error = this.t(
					'learniq',
					'The last day cannot be before the first day.',
				)
				return
			}
			this.saving = true
			try {
				const created = oneObject(
					(
						await axios.post(generateUrl(objectsUrl('excuse-request')), {
							learnerId: this.learner.ncUserId,
							learnerRef: objectId(this.learner) || undefined,
							submittedBy: getCurrentUser()?.uid ?? '',
							dateFrom: this.dateFrom,
							dateTo: this.dateTo,
							reasonKind: this.reasonKind,
							reason: this.reason,
							submittedAuthLevel: 'basic',
							tenant_id: this.learner.tenant_id ?? '',
						})
					).data,
				)
				this.createdId = objectId(created)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('learniq', 'The excuse could not be recorded.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.submit-excuse {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-inline-size: 40rem;
}

.submit-excuse__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.submit-excuse__actions {
	margin-block-start: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>
