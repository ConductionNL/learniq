<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ExternalTrainingUploadDialog (compliance-external-training-spreadsheet-upload).

 A compliance officer uploads a provider's attendance list as CSV, one row
 per person. The dialog posts the rows with dryRun first and shows every row
 as ready, unmatched, invalid or already recorded, with the reason. Confirming
 posts the same rows again without dryRun: the server records the ready rows
 under one batch and reports per row. The failed rows download as a CSV that
 can be corrected and uploaded again.

 @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-import-result-report
-->
<template>
	<NcDialog
		:open="true"
		size="large"
		:name="t('learniq', 'Upload a spreadsheet')"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<div class="training-upload">
			<p>
				{{
					t(
						'learniq',
						'Save the attendance list as CSV with one row per person. Columns: learner (email, personal number or learner reference), title, provider, completed on, and optionally kind, valid until, regulation and evidence note.',
					)
				}}
			</p>
			<div class="training-upload__file">
				<label for="training-upload-file">{{
					t('learniq', 'CSV file')
				}}</label>
				<input
					id="training-upload-file"
					type="file"
					accept=".csv,text/csv"
					data-testid="training-upload-file"
					:disabled="busy"
					@change="onFile" />
			</div>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="ignored.length > 0" type="info">
				{{
					t('learniq', 'These columns are not read: {columns}', {
						columns: ignored.join(', '),
					})
				}}
			</NcNoteCard>

			<template v-if="report">
				<p
					class="training-upload__summary"
					data-testid="training-upload-summary">
					{{ summaryText }}
				</p>
				<NcButton
					v-if="!report.dryRun && report.summary.failed > 0"
					variant="secondary"
					data-testid="training-upload-failed-download"
					@click="downloadFailed">
					{{ t('learniq', 'Download the failed rows') }}
				</NcButton>
				<table class="training-upload__rows">
					<thead>
						<tr>
							<th scope="col">{{ t('learniq', 'Row') }}</th>
							<th scope="col">{{ t('learniq', 'Learner') }}</th>
							<th scope="col">{{ t('learniq', 'Training') }}</th>
							<th scope="col">{{ t('learniq', 'Status') }}</th>
							<th scope="col">{{ t('learniq', 'Reason') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="line in report.rows" :key="line.row">
							<td>{{ line.row }}</td>
							<td>{{ line.learner }}</td>
							<td>{{ rows[line.row - 1]?.title }}</td>
							<td>{{ statusLabel(line.status) }}</td>
							<td>{{ reasonLabel(line) }}</td>
						</tr>
					</tbody>
				</table>
			</template>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('learniq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="report && report.dryRun"
				variant="primary"
				:disabled="busy || counts.ready === 0"
				data-testid="training-upload-confirm"
				@click="send(false)">
				{{ t('learniq', 'Record {count} rows', { count: counts.ready }) }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import {
	failedRowsCsv,
	IMPORT_URL,
	previewCounts,
	rowsFromCsv,
} from '../utils/externalTrainingUpload.js'

const STATUS_LABELS = {
	ready: 'Ready',
	created: 'Recorded',
	unmatched: 'No learner found',
	invalid: 'Not valid',
	duplicate: 'Twice in this file',
	skipped: 'Already recorded',
}

export default {
	name: 'ExternalTrainingUploadDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
	},

	emits: ['close', 'imported'],

	data() {
		return {
			rows: [],
			ignored: [],
			report: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Rows by what will happen to them.
		 *
		 * @return {{ready: number, skipped: number, failed: number}}
		 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-an-officer-uploads-a-providers-attendance-list
		 */
		counts() {
			return previewCounts(this.report?.rows)
		},

		/**
		 * The line above the table: the preview counts, or the import result.
		 *
		 * @return {string}
		 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-import-result-report
		 */
		summaryText() {
			if (this.report.dryRun) {
				return this.t(
					'learniq',
					'{ready} ready, {skipped} already recorded, {failed} cannot be recorded.',
					this.counts,
				)
			}
			return this.t(
				'learniq',
				'{created} recorded, {skipped} skipped, {failed} failed.',
				this.report.summary,
			)
		},
	},

	methods: {
		/**
		 * Read the chosen file and ask the server for the preview.
		 *
		 * @param {Event} event The file input's change event.
		 * @return {Promise<void>}
		 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-an-officer-uploads-a-providers-attendance-list
		 */
		async onFile(event) {
			const file = event.target.files?.[0]
			this.report = null
			this.error = ''
			this.ignored = []
			if (!file) {
				return
			}
			const parsed = rowsFromCsv(await file.text())
			this.ignored = parsed.ignored
			if (parsed.missing.length > 0) {
				this.error = this.t(
					'learniq',
					'The file has no column for: {columns}',
					{ columns: parsed.missing.join(', ') },
				)
				return
			}
			if (parsed.rows.length === 0) {
				this.error = this.t('learniq', 'The file has no rows.')
				return
			}
			this.rows = parsed.rows
			await this.send(true)
		},

		/**
		 * Post the rows: the preview (dry run) or the import.
		 *
		 * @param {boolean} dryRun True for the preview.
		 * @return {Promise<void>}
		 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
		 */
		async send(dryRun) {
			this.busy = true
			this.error = ''
			try {
				const response = await axios.post(generateUrl(IMPORT_URL), {
					rows: this.rows,
					dryRun,
				})
				this.report = response.data
				if (!dryRun && response.data.batchId) {
					this.$emit(
						'imported',
						response.data.batchId,
						response.data.summary.created,
					)
				}
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('learniq', 'The file could not be checked.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Save the failed rows, with their reasons, as a CSV.
		 *
		 * @return {void}
		 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-the-officer-fixes-the-failed-rows
		 */
		downloadFailed() {
			const blob = new Blob([failedRowsCsv(this.rows, this.report.rows)], {
				type: 'text/csv',
			})
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = 'external-training-failed-rows.csv'
			link.click()
			URL.revokeObjectURL(url)
		},

		/**
		 * The label of a row status.
		 *
		 * @param {string} status The status.
		 * @return {string}
		 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-an-officer-uploads-a-providers-attendance-list
		 */
		statusLabel(status) {
			return this.t('learniq', STATUS_LABELS[status] ?? STATUS_LABELS.invalid)
		},

		/**
		 * The server's reason, translated, its placeholders filled in.
		 *
		 * @param {object} line A row of the server's report.
		 * @return {string}
		 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-an-invalid-date-is-refused-per-row
		 */
		reasonLabel(line) {
			return line.reason
				? this.t('learniq', line.reason, line.reasonParams ?? {})
				: ''
		},
	},
}
</script>

<style scoped>
.training-upload {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.training-upload__file {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.training-upload__rows {
	width: 100%;
	border-collapse: collapse;
}

.training-upload__rows th,
.training-upload__rows td {
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}
</style>
