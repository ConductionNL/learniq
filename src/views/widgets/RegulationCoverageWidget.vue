<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RegulationCoverageWidget (compliance-rule-coverage-table): every active
 regulation's coverage next to each other on the compliance page. One row per
 rule with the learners in scope, how many are covered, the percentage and a
 red, amber or green state from the rule's own thresholds. Sortable by
 percentage, filterable by department, each row linked to its regulation.
 Figures come from GET /api/compliance/coverage-by-regulation, which walks
 the same learners as the department roll-up beside it.

 @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
-->
<template>
	<div class="learniq-rule-coverage">
		<NcSelect
			v-model="department"
			class="learniq-rule-coverage__filter"
			:options="departments"
			:inputLabel="t('learniq', 'Department')"
			:placeholder="t('learniq', 'All departments')"
			data-testid="rule-coverage-department"
			@update:modelValue="load" />
		<NcLoadingIcon
			v-if="loading"
			:name="t('learniq', 'Loading coverage per rule')" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcEmptyContent
			v-else-if="rows.length === 0"
			:name="t('learniq', 'No active regulations')"
			:description="
				t(
					'learniq',
					'Coverage per rule appears once a regulation is published.',
				)
			" />
		<table v-else class="learniq-rule-coverage__table">
			<caption class="hidden-visually">
				{{
					t('learniq', 'Coverage per rule')
				}}
			</caption>
			<thead>
				<tr>
					<th scope="col">
						{{ t('learniq', 'Regulation') }}
					</th>
					<th scope="col">
						{{ t('learniq', 'In scope') }}
					</th>
					<th scope="col">
						{{ t('learniq', 'Covered') }}
					</th>
					<th
						scope="col"
						:aria-sort="
							direction === 'asc' ? 'ascending' : 'descending'
						">
						<NcButton
							variant="tertiary"
							data-testid="rule-coverage-sort"
							@click="toggleSort">
							{{
								direction === 'asc'
									? t('learniq', 'Percentage, lowest first')
									: t('learniq', 'Percentage, highest first')
							}}
						</NcButton>
					</th>
					<th scope="col">
						{{ t('learniq', 'State') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in sortedRows"
					:key="row.id || row.slug"
					:data-testid="'rule-coverage-row-' + row.slug">
					<th scope="row">
						<router-link
							:to="
								'/compliance/regulations/'
								+ encodeURIComponent(row.slug)
							">
							{{ row.name }}
						</router-link>
					</th>
					<td>{{ row.inScope }}</td>
					<td>{{ row.covered }}</td>
					<td>
						{{
							row.coveragePercent === null
								? ''
								: row.coveragePercent + '%'
						}}
					</td>
					<td>
						<span
							v-if="row.rag"
							:class="
								'learniq-rule-coverage__rag learniq-rule-coverage__rag--'
								+ row.rag
							">
							{{ ragLabel(row.rag) }}
						</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import {
	coverageUrl,
	departmentPaths,
	sortByPercent,
} from '../../utils/regulationCoverage.js'

const RAG_LABELS = {
	red: 'Red',
	amber: 'Amber',
	green: 'Green',
}

export default {
	name: 'RegulationCoverageWidget',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			rows: [],
			departments: [],
			department: null,
			direction: 'asc',
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * The rows in the chosen order.
		 *
		 * @return {object[]}
		 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
		 */
		sortedRows() {
			return sortByPercent(this.rows, this.direction)
		},
	},

	mounted() {
		this.load()
		this.loadDepartments()
	},

	methods: {
		/**
		 * Fetch the figures for the chosen department. A failure is shown, never zeroes.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#scenario-an-officer-compares-all-rules
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					generateUrl(coverageUrl(this.department)),
				)
				this.rows = Array.isArray(response.data?.regulations)
					? response.data.regulations
					: []
			} catch {
				this.rows = []
				this.error = this.t(
					'learniq',
					'Coverage per rule could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * The department levels the filter offers, from the department roll-up.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#scenario-the-department-filter-narrows-the-table
		 */
		async loadDepartments() {
			try {
				const response = await axios.get(
					generateUrl('/apps/learniq/api/compliance/departments'),
				)
				this.departments = departmentPaths(response.data?.departments)
			} catch {
				this.departments = []
			}
		},

		/**
		 * Flip the percentage sort.
		 *
		 * @return {void}
		 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
		 */
		toggleSort() {
			this.direction = this.direction === 'asc' ? 'desc' : 'asc'
		},

		/**
		 * The label of a RAG state.
		 *
		 * @param {string} rag red, amber or green.
		 * @return {string}
		 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#scenario-an-officer-compares-all-rules
		 */
		ragLabel(rag) {
			return this.t('learniq', RAG_LABELS[rag] ?? rag)
		},
	},
}
</script>

<style scoped>
.learniq-rule-coverage {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.learniq-rule-coverage__filter {
	max-width: 320px;
}

.learniq-rule-coverage__table {
	width: 100%;
	border-collapse: collapse;
}

.learniq-rule-coverage__table th,
.learniq-rule-coverage__table td {
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.learniq-rule-coverage__rag {
	padding: 0 var(--default-grid-baseline);
	border-radius: var(--border-radius);
	font-weight: bold;
}

.learniq-rule-coverage__rag--red {
	color: var(--color-error-text);
}

.learniq-rule-coverage__rag--amber {
	color: var(--color-warning-text);
}

.learniq-rule-coverage__rag--green {
	color: var(--color-success-text);
}
</style>
