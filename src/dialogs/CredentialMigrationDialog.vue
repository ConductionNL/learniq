<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CredentialMigrationDialog (credentials-migrate-with-validity).

 HR or a compliance officer uploads the certificates of the previous system
 as CSV, one row per certificate. The dialog posts the rows as a dry run
 first: the server reports every row as ready or refused with the reason,
 and nothing is stored. "Take over" posts them again and only the ready rows
 are written, as migrated certificates without a learniq signature. The
 layout follows the external-training upload: file, table, confirm.

 @spec openspec/changes/credentials-migrate-with-validity/specs/certification/spec.md#requirement-certificates-from-the-previous-system-are-uploaded-with-a-dry-run-first
-->
<template>
	<NcDialog
		:open="true"
		size="large"
		:name="t('learniq', 'Take over certificates')"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<div class="credential-migration">
			<p>
				{{
					t(
						'learniq',
						'Save the certificates of the previous system as CSV with one row per certificate. Columns: learner (email, personal number or learner reference), course code or regulation, issued on, certificate number, and optionally valid until, issuing body and kind.',
					)
				}}
			</p>
			<div class="credential-migration__file">
				<label for="credential-migration-file">{{
					t('learniq', 'CSV file')
				}}</label>
				<input
					id="credential-migration-file"
					type="file"
					accept=".csv,text/csv"
					data-testid="credential-migration-file"
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
					class="credential-migration__summary"
					data-testid="credential-migration-summary">
					{{ summaryText }}
				</p>
				<NcNoteCard
					v-if="report.summary.withoutEndDate > 0"
					type="warning">
					{{
						n(
							'learniq',
							'%n certificate has no end date and stays valid without expiry.',
							'%n certificates have no end date and stay valid without expiry.',
							report.summary.withoutEndDate,
						)
					}}
				</NcNoteCard>
				<table class="credential-migration__rows">
					<thead>
						<tr>
							<th v-for="heading in headings" :key="heading" scope="col">
								{{ heading }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="line in report.rows" :key="line.row">
							<td>{{ line.row }}</td>
							<td>{{ line.learner }}</td>
							<td>{{ rows[line.row - 1]?.legacyNumber }}</td>
							<td>{{ statusLabel(line.status) }}</td>
							<td>{{ line.reason ? t('learniq', line.reason) : '' }}</td>
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
				v-if="takeOverPossible"
				variant="primary"
				data-testid="credential-migration-commit"
				:disabled="busy"
				@click="send(false)">
				{{ t('learniq', 'Take over') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import {
	canTakeOver,
	MIGRATE_URL,
	migrationRowsFromCsv,
} from '../utils/credentialMigrationUpload.js'

const STATUS_LABELS = {
	ready: 'Ready',
	created: 'Taken over',
	unmatched: 'Not found',
	invalid: 'Not valid',
	duplicate: 'Already present',
	failed: 'Not saved',
}

export default {
	name: 'CredentialMigrationDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
	},

	emits: ['close'],

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
		 * The table's column headings.
		 *
		 * @return {string[]}
		 * @spec openspec/changes/credentials-migrate-with-validity/specs/certification/spec.md#requirement-certificates-from-the-previous-system-are-uploaded-with-a-dry-run-first
		 */
		headings() {
			return [
				this.t('learniq', 'Row'),
				this.t('learniq', 'Learner'),
				this.t('learniq', 'Certificate number'),
				this.t('learniq', 'Status'),
				this.t('learniq', 'Reason'),
			]
		},

		/**
		 * The line above the table: the preview or the result.
		 *
		 * @return {string}
		 * @spec openspec/changes/credentials-migrate-with-validity/specs/certification/spec.md#requirement-certificates-from-the-previous-system-are-uploaded-with-a-dry-run-first
		 */
		summaryText() {
			const summary = this.report.summary
			if (this.report.commit) {
				return this.t('learniq', '{created} taken over, {skipped} skipped, {failed} failed.', summary)
			}
			return this.t('learniq', '{ready} ready to take over, {skipped} refused or already present.', summary)
		},

		/**
		 * Whether "Take over" shows: a dry run with ready rows.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/credentials-migrate-with-validity/specs/certification/spec.md#scenario-a-clean-file
		 */
		takeOverPossible() {
			return canTakeOver(this.report)
		},
	},

	methods: {
		/**
		 * Read the chosen file and check it without writing.
		 *
		 * @param {Event} event The file input's change event.
		 * @return {Promise<void>}
		 * @spec openspec/changes/credentials-migrate-with-validity/specs/certification/spec.md#scenario-a-clean-file
		 */
		async onFile(event) {
			const file = event.target.files?.[0]
			const parsed = file ? migrationRowsFromCsv(await file.text()) : { rows: [], missing: [], ignored: [] }
			Object.assign(this, { report: null, ignored: parsed.ignored, error: file ? this.fileProblem(parsed) : '' })
			if (file && this.error === '') {
				this.rows = parsed.rows
				await this.send(true)
			}
		},

		/**
		 * Why a read file cannot be checked: a required column is missing or it has no rows; '' when it can.
		 *
		 * @param {{rows: object[], missing: string[]}} parsed The read file.
		 * @return {string}
		 * @spec openspec/changes/credentials-migrate-with-validity/specs/certification/spec.md#scenario-a-clean-file
		 */
		fileProblem(parsed) {
			if (parsed.missing.length > 0) {
				return this.t('learniq', 'The file has no column for: {columns}', { columns: parsed.missing.join(', ') })
			}
			return parsed.rows.length === 0 ? this.t('learniq', 'The file has no rows.') : ''
		},

		/**
		 * Post the rows: a dry run for the preview, or the take-over.
		 *
		 * @param {boolean} dryRun True for the preview.
		 * @return {Promise<void>}
		 * @spec openspec/changes/credentials-migrate-with-validity/specs/certification/spec.md#requirement-certificates-from-the-previous-system-are-uploaded-with-a-dry-run-first
		 */
		async send(dryRun) {
			this.busy = true
			this.error = ''
			try {
				const response = await axios.post(generateUrl(MIGRATE_URL), {
					rows: this.rows,
					dryRun,
				})
				this.report = response.data
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('learniq', 'The file could not be checked.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * The label of a row status.
		 *
		 * @param {string} status The status.
		 * @return {string}
		 * @spec openspec/changes/credentials-migrate-with-validity/specs/certification/spec.md#scenario-a-row-with-an-unknown-learner
		 */
		statusLabel(status) {
			return this.t('learniq', STATUS_LABELS[status] ?? STATUS_LABELS.invalid)
		},
	},
}
</script>

<style scoped>
.credential-migration,
.credential-migration__file {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.credential-migration {
	gap: calc(var(--default-grid-baseline) * 3);
}

.credential-migration__rows {
	width: 100%;
	border-collapse: collapse;
}

.credential-migration__rows th,
.credential-migration__rows td {
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}
</style>
