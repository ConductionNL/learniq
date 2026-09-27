<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CohortGradebookView: the gradebook of one cohort for one curriculum plan
 (route /grades/cohort/:cohortId/plan/:planId, learniq#947).

 A CnDataMatrix grid: one row per learner of the cohort, one column per plan
 component. Typing a mark saves a concept GradeEntry (sourceKind manual):
 a new one, or the learner's existing concept entry for that component. A
 published mark is not overwritten here; it changes through the grade's own
 revise transition on its detail page.

 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<div class="cohort-gradebook">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<template v-else>
			<CnDataMatrix
				:rows="displayRows"
				:columns="grid.columns"
				:title="t('learniq', 'Gradebook')"
				:description="plan.name + ' · ' + cohort.name"
				:rowHeader="t('learniq', 'Learner')"
				:emptyLabel="t('learniq', 'No learners in this group.')"
				:showColumnTotals="true"
				:columnTotalsLabel="t('learniq', 'Average')"
				@cellEdit="onCellEdit" />
			<NcNoteCard v-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import { CnDataMatrix } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	gradebookGrid,
	gradeEntryBody,
	listRows,
	objectId,
	objectsUrl,
	oneObject,
	parseMark,
} from '../utils/customPages.js'

export default {
	name: 'CohortGradebookView',

	components: { CnDataMatrix, NcLoadingIcon, NcNoteCard },

	props: {
		/** Cohort UUID from the route. */
		cohortId: { type: String, required: true },
		/** CurriculumPlan UUID from the route. */
		planId: { type: String, required: true },
	},

	data() {
		return {
			loading: true,
			loadError: '',
			cohort: {},
			plan: {},
			entries: [],
			names: {},
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/**
		 * @return {{columns: object[], rows: object[], entryIndex: object}} The grid.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		grid() {
			return gradebookGrid(
				this.cohort.learnerIds ?? [],
				this.plan.components ?? [],
				this.entries,
			)
		},

		/**
		 * @return {object[]} Grid rows labelled with learner names.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		displayRows() {
			return this.grid.rows.map((row) => ({
				...row,
				label: this.names[row.id] || row.id,
			}))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the cohort, the plan, its grade entries and learner names.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async load() {
			this.loading = true
			try {
				const [cohort, plan] = await Promise.all([
					axios.get(generateUrl(objectsUrl('cohort', this.cohortId))),
					axios.get(
						generateUrl(objectsUrl('curriculum-plan', this.planId)),
					),
				])
				this.cohort = oneObject(cohort.data)
				this.plan = oneObject(plan.data)
				this.entries = listRows(
					(
						await axios.get(generateUrl(objectsUrl('grade-entry')), {
							params: {
								curriculumPlanId: this.planId,
								cohortId: this.cohortId,
								_limit: 2000,
							},
						})
					).data,
				)
				await this.loadNames()
			} catch {
				this.loadError = this.t(
					'learniq',
					'This gradebook could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async loadNames() {
			try {
				const profiles = listRows(
					(
						await axios.get(generateUrl(objectsUrl('learner-profile')), {
							params: { _limit: 1000 },
						})
					).data,
				)
				const names = {}
				for (const p of profiles) {
					const name = [p.givenName, p.familyName]
						.filter(Boolean)
						.join(' ')
					if (p.ncUserId && name) names[p.ncUserId] = name
				}
				this.names = names
			} catch {
				this.names = {}
			}
		},

		/**
		 * Save a typed mark as a concept GradeEntry.
		 *
		 * @param {{rowId: string, colKey: string, value: unknown}} edit The edited cell.
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async onCellEdit({ rowId, colKey, value }) {
			const mark = parseMark(value)
			if (mark === null) {
				this.showMessage(
					this.t('learniq', 'Type a number as the mark.'),
					'error',
				)
				return
			}
			const existing = this.grid.entryIndex[rowId + '|' + colKey]
			if (existing && existing.lifecycle === 'published') {
				this.showMessage(
					this.t(
						'learniq',
						'This mark is published. Revise it on the grade itself.',
					),
					'warning',
				)
				return
			}
			const body = gradeEntryBody({
				learnerId: rowId,
				componentId: colKey,
				value: mark,
				plan: this.plan,
				cohortId: this.cohortId,
				grader: getCurrentUser()?.uid ?? '',
				gradedAt: new Date().toISOString(),
			})
			try {
				if (existing) {
					const id = objectId(existing)
					await axios.put(generateUrl(objectsUrl('grade-entry', id)), body)
					Object.assign(existing, body)
				} else {
					const created = oneObject(
						(
							await axios.post(
								generateUrl(objectsUrl('grade-entry')),
								body,
							)
						).data,
					)
					this.entries.push({
						...body,
						...created,
						lifecycle: created.lifecycle ?? 'concept',
					})
				}
				this.entries = [...this.entries]
				this.showMessage(
					this.t('learniq', 'Mark saved as a concept.'),
					'success',
				)
			} catch (e) {
				this.showMessage(
					e?.response?.data?.error
						|| this.t('learniq', 'The mark could not be saved.'),
					'error',
				)
			}
		},

		/**
		 * @param {string} text Message.
		 * @param {string} type NcNoteCard type.
		 * @return {void}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		showMessage(text, type) {
			this.message = text
			this.messageType = type
		},
	},
}
</script>

<style scoped>
.cohort-gradebook {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}
</style>
