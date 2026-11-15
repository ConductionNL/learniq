<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 DepartmentComplianceWidget: the per-department compliance roll-up on the
 compliance dashboard (learniq#951). One row per department level, indented
 from directorate to department to team, with coverage, upcoming deadlines,
 overdue mandatory training and expired credentials. Figures come from
 GET /api/compliance/departments (ComplianceRollupService), which counts only
 the learners each regulation's audience scope covers.

 @spec openspec/parity/capabilities.json#comp-roll-up-by-department
-->
<template>
	<div class="learniq-department-compliance">
		<NcLoadingIcon
			v-if="loading"
			:name="t('learniq', 'Loading compliance per department')" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcEmptyContent
			v-else-if="rows.length === 0"
			:name="t('learniq', 'No learners yet')"
			:description="
				t(
					'learniq',
					'Compliance per department appears once learner profiles exist.',
				)
			" />
		<table v-else class="learniq-department-compliance__table">
			<caption class="hidden-visually">
				{{
					t('learniq', 'Compliance per department')
				}}
			</caption>
			<thead>
				<tr>
					<th scope="col">
						{{ t('learniq', 'Department') }}
					</th>
					<th scope="col">
						{{ t('learniq', 'Learners') }}
					</th>
					<th scope="col">
						{{ t('learniq', 'Coverage') }}
					</th>
					<th scope="col">
						{{ t('learniq', 'Excused') }}
					</th>
					<th scope="col">
						{{ t('learniq', 'Due within 30 days') }}
					</th>
					<th scope="col">
						{{ t('learniq', 'Overdue') }}
					</th>
					<th scope="col">
						{{ t('learniq', 'Expired credentials') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in rows"
					:key="row.department"
					:data-testid="'department-row-' + row.department">
					<th
						scope="row"
						:style="{ paddingInlineStart: row.depth * 16 + 8 + 'px' }">
						{{ departmentLabel(row) }}
					</th>
					<td>{{ row.learners }}</td>
					<td>{{ coverageLabel(row) }}</td>
					<td>{{ row.excused }}</td>
					<td>{{ row.upcomingDeadlines }}</td>
					<td>{{ row.overdue }}</td>
					<td>{{ row.expiredCredentials }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'DepartmentComplianceWidget',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			rows: [],
			loading: true,
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Fetch the roll-up. A failure is shown as an error, never as zeroes.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl('/apps/learniq/api/compliance/departments'),
				)
				this.rows = Array.isArray(data?.departments) ? data.departments : []
			} catch {
				this.error = this.t(
					'learniq',
					'Compliance per department could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * The last level of the department path, or a label for no department.
		 *
		 * @param {object} row Roll-up row.
		 * @return {string}
		 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
		 */
		departmentLabel(row) {
			if (!row.department) {
				return this.t('learniq', 'No department')
			}
			const parts = row.department.split('/')
			return parts[parts.length - 1]
		},

		/**
		 * Coverage as a percentage with the counts behind it.
		 *
		 * @param {object} row Roll-up row.
		 * @return {string}
		 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
		 */
		coverageLabel(row) {
			if (row.coveragePercent === null || row.coveragePercent === undefined) {
				return this.t('learniq', 'No obligations')
			}
			return this.t('learniq', '{percent}% ({covered} of {total})', {
				percent: row.coveragePercent,
				covered: row.covered,
				total: row.obligations,
			})
		},
	},
}
</script>

<style scoped>
.learniq-department-compliance {
	overflow-x: auto;
}

.learniq-department-compliance__table {
	width: 100%;
	border-collapse: collapse;
}

.learniq-department-compliance__table th,
.learniq-department-compliance__table td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.learniq-department-compliance__table tbody th {
	font-weight: normal;
}
</style>
