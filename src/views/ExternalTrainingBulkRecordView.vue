<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ExternalTrainingBulkRecordView: record one external training for many
 learners at once (learniq#952).

 A custom view because no manifest page can post one form for a list of
 picked learners. It is the only caller of ExternalTrainingController:
   - POST /api/external-training/bulk records the training for every picked
     learner under one batchId,
   - the batch table then lists the created records, issues a credential for
     a verified one (POST /{recordId}/credential), and checks each learner's
     coverage for the regulation (GET /coverage).
 Reached from the External training index header action. Opening the page
 with ?batchId=<id> reloads an earlier batch.

 @spec openspec/specs/external-training-recording/spec.md
-->
<template>
	<div class="external-training-bulk">
		<h2>{{ t('learniq', 'Record training for a group') }}</h2>
		<p class="external-training-bulk__intro">
			{{
				t(
					'learniq',
					'Pick the learners who followed the same training. Fill in the training once.',
				)
			}}
		</p>

		<form class="external-training-bulk__form" @submit.prevent="submit">
			<NcSelect
				v-model="learners"
				:options="learnerOptions"
				:multiple="true"
				:loading="searchingLearners"
				:filterable="false"
				:getOptionLabel="learnerLabel"
				:inputLabel="t('learniq', 'Learners')"
				:placeholder="t('learniq', 'Search by name')"
				@search="searchLearners" />

			<label for="etb-title">{{ t('learniq', 'Training title') }}</label>
			<input id="etb-title" v-model="training.title" type="text" required />

			<label for="etb-provider">{{ t('learniq', 'Provider') }}</label>
			<input
				id="etb-provider"
				v-model="training.provider"
				type="text"
				required />

			<NcSelect
				v-model="training.kind"
				:options="kindOptions"
				:reduce="(option) => option.id"
				:clearable="false"
				:inputLabel="t('learniq', 'Kind of training')" />

			<label for="etb-completed">{{ t('learniq', 'Completed on') }}</label>
			<input
				id="etb-completed"
				v-model="training.completedAt"
				type="date"
				required />

			<label for="etb-valid">{{
				t('learniq', 'Valid until (optional)')
			}}</label>
			<input id="etb-valid" v-model="training.validUntil" type="date" />

			<NcSelect
				v-model="training.regulationSlug"
				:options="regulationOptions"
				:reduce="(option) => option.id"
				:inputLabel="t('learniq', 'Regulation it covers (optional)')" />

			<label for="etb-evidence">{{
				t('learniq', 'Evidence note (optional)')
			}}</label>
			<textarea id="etb-evidence" v-model="training.evidenceNote" rows="3" />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div class="external-training-bulk__actions">
				<NcButton type="submit" variant="primary" :disabled="submitting">
					{{
						n(
							'learniq',
							'Record for %n learner',
							'Record for %n learners',
							learners.length,
						)
					}}
				</NcButton>
			</div>
		</form>

		<section v-if="batchId" class="external-training-bulk__batch">
			<h3>{{ t('learniq', 'Recorded batch') }}</h3>
			<NcNoteCard v-if="createdCount > 0" type="success">
				{{
					n(
						'learniq',
						'%n record created. Each one waits for verification.',
						'%n records created. Each one waits for verification.',
						createdCount,
					)
				}}
			</NcNoteCard>
			<p>{{ t('learniq', 'Batch {batchId}', { batchId }) }}</p>

			<NcLoadingIcon v-if="loadingBatch" :size="32" />
			<table
				v-else-if="records.length > 0"
				class="external-training-bulk__table">
				<thead>
					<tr>
						<th scope="col">{{ t('learniq', 'Learner') }}</th>
						<th scope="col">{{ t('learniq', 'Status') }}</th>
						<th scope="col">{{ t('learniq', 'Coverage') }}</th>
						<th scope="col">{{ t('learniq', 'Credential') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="record in records" :key="record.id">
						<td>
							<router-link
								:to="{
									name: 'ExternalTrainingRecordDetail',
									params: { id: record.id },
								}">
								{{ learnerName(record.learnerId) }}
							</router-link>
						</td>
						<td>{{ record.lifecycle || t('learniq', 'submitted') }}</td>
						<td>{{ coverageLabel(record) }}</td>
						<td>
							<span v-if="record.credentialId">{{
								t('learniq', 'Issued')
							}}</span>
							<NcButton
								v-else
								variant="secondary"
								:disabled="
									record.lifecycle !== 'verified'
									|| issuing === record.id
								"
								@click="issueCredential(record)">
								{{ t('learniq', 'Issue credential') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<div
				v-if="records.length > 0 && batchRegulation"
				class="external-training-bulk__actions">
				<NcButton
					variant="secondary"
					:disabled="checkingCoverage"
					@click="checkCoverage">
					{{ t('learniq', 'Check coverage') }}
				</NcButton>
			</div>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import {
	buildBulkPayload,
	BULK_URL,
	bulkMissingFields,
	coverageUrl,
	credentialUrl,
	learnerId,
} from '../utils/externalTrainingBulk.js'

const OBJECTS = '/apps/openregister/api/objects/learniq'

/**
 * Read the rows out of an OpenRegister list response.
 *
 * @param {object} response The axios response.
 * @return {object[]} The rows.
 * @spec openspec/specs/external-training-recording/spec.md
 */
function rows(response) {
	return (response?.data && (response.data.results || response.data.objects)) || []
}

export default {
	name: 'ExternalTrainingBulkRecordView',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			learners: [],
			learnerOptions: [],
			searchingLearners: false,
			regulationOptions: [],
			training: {
				title: '',
				provider: '',
				kind: 'classroom',
				completedAt: '',
				validUntil: '',
				regulationSlug: null,
				evidenceNote: '',
			},

			submitting: false,
			error: '',
			batchId: '',
			createdCount: 0,
			records: [],
			learnerNames: {},
			loadingBatch: false,
			issuing: '',
			checkingCoverage: false,
			coverage: {},
		}
	},

	computed: {
		/**
		 * The kinds ExternalTrainingRecord.kind allows.
		 *
		 * @return {Array<{id: string, label: string}>} Select options.
		 * @spec openspec/specs/external-training-recording/spec.md
		 */
		kindOptions() {
			return [
				{ id: 'classroom', label: this.t('learniq', 'Classroom') },
				{
					id: 'external-elearning',
					label: this.t('learniq', 'External e-learning'),
				},
				{ id: 'conference', label: this.t('learniq', 'Conference') },
				{ id: 'on-the-job', label: this.t('learniq', 'On the job') },
				{ id: 'other', label: this.t('learniq', 'Other') },
			]
		},

		/**
		 * The regulation of the loaded batch, used for the coverage check.
		 *
		 * @return {string} The regulation slug, or ''.
		 * @spec openspec/specs/external-training-recording/spec.md
		 */
		batchRegulation() {
			const withSlug = this.records.find((r) => r.regulationSlug)
			return withSlug ? withSlug.regulationSlug : ''
		},
	},

	mounted() {
		this.searchLearners('')
		this.loadRegulations()
		const fromQuery = this.$route?.query?.batchId
		if (typeof fromQuery === 'string' && fromQuery !== '') {
			this.batchId = fromQuery
			this.loadBatch()
		}
	},

	methods: {
		/**
		 * Show a learner by name.
		 *
		 * @param {object} learner A LearnerProfile object.
		 * @return {string} The display name.
		 * @spec openspec/specs/external-training-recording/spec.md
		 */
		learnerLabel(learner) {
			const name = [learner.givenName, learner.familyName]
				.filter(Boolean)
				.join(' ')
			return name || learner.ncUserId || learnerId(learner)
		},

		/**
		 * Search LearnerProfile objects for the picker.
		 *
		 * @param {string} query The typed search text.
		 * @return {Promise<void>}
		 * @spec openspec/specs/external-training-recording/spec.md
		 */
		async searchLearners(query) {
			this.searchingLearners = true
			try {
				const response = await axios.get(
					generateUrl(`${OBJECTS}/learner-profile`),
					{
						params: { _search: query || undefined, _limit: 50 },
					},
				)
				this.learnerOptions = rows(response)
				this.learnerOptions.forEach((l) => {
					this.learnerNames[learnerId(l)] = this.learnerLabel(l)
				})
			} catch {
				this.error = this.t('learniq', 'Learners could not be loaded.')
				this.learnerOptions = []
			} finally {
				this.searchingLearners = false
			}
		},

		/**
		 * Load the regulations a training can cover.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/external-training-recording/spec.md
		 */
		async loadRegulations() {
			try {
				const response = await axios.get(
					generateUrl(`${OBJECTS}/regulation`),
					{
						params: { _limit: 200 },
					},
				)
				this.regulationOptions = rows(response)
					.filter((r) => r.slug)
					.map((r) => ({ id: r.slug, label: r.name || r.slug }))
			} catch {
				// Without regulations the optional field stays empty; recording still works.
				this.regulationOptions = []
			}
		},

		/**
		 * Post the training for every picked learner, then show the batch.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/external-training-recording/spec.md
		 */
		async submit() {
			const input = { learners: this.learners, training: this.training }
			const missing = bulkMissingFields(input)
			if (missing.length > 0) {
				this.error = this.t('learniq', 'Fill in: {fields}', {
					fields: missing.join(', '),
				})
				return
			}
			this.error = ''
			this.submitting = true
			try {
				const response = await axios.post(
					generateUrl(BULK_URL),
					buildBulkPayload(input),
				)
				this.batchId = response.data.batchId
				this.createdCount = response.data.count
				this.coverage = {}
				await this.loadBatch()
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('learniq', 'The training could not be recorded.')
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Load the records of the current batch.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/external-training-recording/spec.md
		 */
		async loadBatch() {
			this.loadingBatch = true
			try {
				const response = await axios.get(
					generateUrl(`${OBJECTS}/external-training-record`),
					{ params: { batchId: this.batchId, _limit: 500 } },
				)
				this.records = rows(response).map((r) => ({
					...r,
					id: r.id || r.uuid,
				}))
			} catch {
				this.error = this.t('learniq', 'The batch could not be loaded.')
				this.records = []
			} finally {
				this.loadingBatch = false
			}
		},

		/**
		 * Issue a credential for one verified record.
		 *
		 * @param {object} record The external-training record.
		 * @return {Promise<void>}
		 * @spec openspec/specs/external-training-recording/spec.md
		 */
		async issueCredential(record) {
			this.issuing = record.id
			try {
				const response = await axios.post(
					generateUrl(credentialUrl(record.id)),
					{},
				)
				record.credentialId = response.data.credentialId
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('learniq', 'The credential could not be issued.')
			} finally {
				this.issuing = ''
			}
		},

		/**
		 * Check each learner in the batch for coverage of the batch regulation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/external-training-recording/spec.md
		 */
		async checkCoverage() {
			this.checkingCoverage = true
			try {
				const results = await Promise.all(
					this.records.map((r) =>
						axios
							.get(
								generateUrl(
									coverageUrl(r.learnerId, this.batchRegulation),
								),
							)
							.then((res) => [r.learnerId, res.data])
							.catch(() => [r.learnerId, null]),
					),
				)
				this.coverage = Object.fromEntries(results)
			} finally {
				this.checkingCoverage = false
			}
		},

		/**
		 * Show a learner's coverage result.
		 *
		 * @param {object} record The external-training record.
		 * @return {string} The label, or '' before a check.
		 * @spec openspec/specs/external-training-recording/spec.md
		 */
		coverageLabel(record) {
			if (!(record.learnerId in this.coverage)) {
				return ''
			}
			const result = this.coverage[record.learnerId]
			if (result === null) {
				return this.t('learniq', 'Could not check')
			}
			return result.covered
				? this.t('learniq', 'Covered by {kind}', {
						kind: result.evidenceClass,
					})
				: this.t('learniq', 'Not covered')
		},

		/**
		 * Show the learner of a record by name when known.
		 *
		 * @param {string} id LearnerProfile UUID.
		 * @return {string} The name, or the id.
		 * @spec openspec/specs/external-training-recording/spec.md
		 */
		learnerName(id) {
			return this.learnerNames[id] || id
		},
	},
}
</script>

<style scoped>
.external-training-bulk {
	padding: 1rem;
	max-width: 48rem;
}

.external-training-bulk__form {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.external-training-bulk__actions {
	display: flex;
	gap: 0.5rem;
	margin-top: 1rem;
}

.external-training-bulk__batch {
	margin-top: 2rem;
}

.external-training-bulk__table {
	width: 100%;
	border-collapse: collapse;
}

.external-training-bulk__table th,
.external-training-bulk__table td {
	padding: 0.25rem 0.5rem;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}
</style>
