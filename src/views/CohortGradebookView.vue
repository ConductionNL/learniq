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

 Under the grid, the publish panel (cohort-gradebook-batch-publish) previews
 how the marks of one column, or all of them, are spread and publishes every
 concept mark in that scope with one confirmed action: the same `publish`
 transition the grade's own page fires, once per entry, one after another.

 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
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

			<section
				v-if="grid.columns.length > 0"
				class="cohort-gradebook__publish"
				aria-labelledby="cohort-gradebook-publish-title">
				<h3 id="cohort-gradebook-publish-title">
					{{ t('learniq', 'Publish marks') }}
				</h3>
				<label for="cohort-gradebook-scope">{{
					t('learniq', 'Column')
				}}</label>
				<select
					id="cohort-gradebook-scope"
					v-model="scope"
					:disabled="publishing">
					<option
						v-for="column in grid.columns"
						:key="column.key"
						:value="column.key">
						{{ column.label }}
					</option>
					<option :value="allComponents">
						{{ t('learniq', 'All columns') }}
					</option>
				</select>

				<p v-if="summary.count === 0" class="cohort-gradebook__muted">
					{{ t('learniq', 'No marks in this column yet.') }}
				</p>
				<template v-else>
					<dl class="cohort-gradebook__stats">
						<div>
							<dt>{{ t('learniq', 'Marks') }}</dt>
							<dd>{{ summary.count }}</dd>
						</div>
						<div>
							<dt>{{ t('learniq', 'Average') }}</dt>
							<dd>{{ summary.average }}</dd>
						</div>
						<div>
							<dt>{{ t('learniq', 'Lowest') }}</dt>
							<dd>{{ summary.lowest }}</dd>
						</div>
						<div>
							<dt>{{ t('learniq', 'Highest') }}</dt>
							<dd>{{ summary.highest }}</dd>
						</div>
						<div v-if="summary.passing !== null">
							<dt>{{ t('learniq', 'Passing') }}</dt>
							<dd>{{ summary.passing }}</dd>
						</div>
					</dl>
					<ul
						class="cohort-gradebook__histogram"
						:aria-label="t('learniq', 'Spread of the marks')">
						<li
							v-for="band in summary.bands"
							:key="band.from"
							class="cohort-gradebook__band">
							<span class="cohort-gradebook__band-label">{{
								bandLabel(band)
							}}</span>
							<span
								class="cohort-gradebook__band-bar"
								:style="{ inlineSize: barWidth(band) }"
								aria-hidden="true" />
							<span class="cohort-gradebook__band-count">{{
								band.count
							}}</span>
						</li>
					</ul>
				</template>

				<p v-if="toPublish.length === 0" class="cohort-gradebook__muted">
					{{
						t(
							'learniq',
							'Nothing to publish: every mark here is published.',
						)
					}}
				</p>
				<NcButton
					v-else-if="!confirming"
					variant="primary"
					:disabled="publishing"
					@click="confirming = true">
					{{
						n(
							'learniq',
							'Publish %n mark',
							'Publish %n marks',
							toPublish.length,
						)
					}}
				</NcButton>
				<div
					v-else
					class="cohort-gradebook__confirm"
					role="group"
					aria-labelledby="cohort-gradebook-confirm-text">
					<p id="cohort-gradebook-confirm-text">
						{{
							n(
								'learniq',
								'Publish %n mark? Pupils and parents are notified according to their own settings.',
								'Publish %n marks? Pupils and parents are notified according to their own settings.',
								toPublish.length,
							)
						}}
					</p>
					<NcButton variant="primary" @click="publishAll">
						{{ t('learniq', 'Yes, publish') }}
					</NcButton>
					<NcButton variant="tertiary" @click="confirming = false">
						{{ t('learniq', 'Cancel') }}
					</NcButton>
				</div>

				<p v-if="publishing" aria-live="polite">
					{{
						t('learniq', 'Publishing {done} of {total}', {
							done: progress.done,
							total: progress.total,
						})
					}}
				</p>
				<NcNoteCard
					v-if="report"
					:type="report.refused.length > 0 ? 'warning' : 'success'">
					<p>
						{{
							n(
								'learniq',
								'%n mark published.',
								'%n marks published.',
								report.published,
							)
						}}
					</p>
					<template v-if="report.refused.length > 0">
						<p>{{ t('learniq', 'Not published:') }}</p>
						<ul>
							<li v-for="item in report.refused" :key="item.learnerId">
								{{ learnerName(item.learnerId) }}: {{ item.reason }}
							</li>
						</ul>
					</template>
				</NcNoteCard>
			</section>
		</template>
	</div>
</template>

<script>
import { CnDataMatrix } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	gradebookGrid,
	gradeEntryBody,
	listRows,
	objectId,
	objectsUrl,
	oneObject,
	parseMark,
	transitionUrl,
} from '../utils/customPages.js'
import {
	ALL_COMPONENTS,
	distribution,
	publishable,
	publishReport,
	scopeEntries,
} from '../utils/gradebookPublish.js'

export default {
	name: 'CohortGradebookView',

	components: { CnDataMatrix, NcButton, NcLoadingIcon, NcNoteCard },

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
			scale: null,
			scope: ALL_COMPONENTS,
			allComponents: ALL_COMPONENTS,
			confirming: false,
			publishing: false,
			progress: { done: 0, total: 0 },
			report: null,
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

		/**
		 * @return {object[]} The live entries of the chosen column, or of all.
		 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
		 */
		scoped() {
			return scopeEntries(this.entries, this.scope)
		},

		/**
		 * @return {object} How the marks in scope are spread.
		 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
		 */
		summary() {
			return distribution(this.scoped, this.scale)
		},

		/**
		 * @return {object[]} The concept marks a publish would send.
		 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
		 */
		toPublish() {
			return publishable(this.scoped)
		},
	},

	watch: {
		/**
		 * A new scope starts without a pending confirmation or an old report.
		 *
		 * @return {void}
		 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
		 */
		scope() {
			this.confirming = false
			this.report = null
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
				this.scope = this.grid.columns[0]?.key ?? ALL_COMPONENTS
				await Promise.all([this.loadNames(), this.loadScale()])
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
		 * The plan's GradeScale, for the pass threshold and the histogram range.
		 * Best effort: without it the preview skips the passing count.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
		 */
		async loadScale() {
			if (!this.plan.gradeScaleId) return
			try {
				this.scale = oneObject(
					(
						await axios.get(
							generateUrl(
								objectsUrl('grade-scale', this.plan.gradeScaleId),
							),
						)
					).data,
				)
			} catch {
				this.scale = null
			}
		},

		/**
		 * @param {string} learnerId Nextcloud user id.
		 * @return {string} The display name.
		 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
		 */
		learnerName(learnerId) {
			return this.names[learnerId] || learnerId
		},

		/**
		 * @param {{from: number, to: number}} band A histogram band.
		 * @return {string} "6 to 7", or one value for a single-point band.
		 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
		 */
		bandLabel(band) {
			const round = (v) => Math.round(v * 10) / 10
			if (band.from === band.to) return String(round(band.from))
			return this.t('learniq', '{from} to {to}', {
				from: round(band.from),
				to: round(band.to),
			})
		},

		/**
		 * @param {{count: number}} band A histogram band.
		 * @return {string} The bar length relative to the fullest band.
		 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
		 */
		barWidth(band) {
			const top = Math.max(1, ...this.summary.bands.map((b) => b.count))
			return `${Math.round((band.count / top) * 100)}%`
		},

		/**
		 * Publish every concept mark in scope, one after another, and report.
		 * A refused entry never stops the rest.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
		 */
		async publishAll() {
			const batch = [...this.toPublish]
			this.confirming = false
			this.publishing = true
			this.report = null
			this.progress = { done: 0, total: batch.length }
			const outcomes = []
			for (const entry of batch) {
				try {
					await axios.post(generateUrl(transitionUrl(objectId(entry))), {
						action: 'publish',
					})
					entry.lifecycle = 'published'
					outcomes.push({ entry, ok: true })
				} catch (e) {
					outcomes.push({
						entry,
						ok: false,
						reason: this.refusalReason(e),
					})
				}
				this.progress.done++
			}
			this.entries = [...this.entries]
			this.report = publishReport(outcomes)
			this.publishing = false
		},

		/**
		 * @param {object} error The axios error of a refused transition.
		 * @return {string} The server's reason, or a plain fallback.
		 * @spec openspec/specs/grading/spec.md#requirement-a-teacher-previews-and-batch-publishes-a-cohorts-concept-grades
		 */
		refusalReason(error) {
			const data = error?.response?.data ?? {}
			for (const candidate of [
				data.message,
				data.error,
				data.errors?.message,
			]) {
				if (typeof candidate === 'string' && candidate !== '')
					return candidate
			}
			return this.t('learniq', 'The server refused this mark.')
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

.cohort-gradebook__publish {
	margin-block-start: calc(var(--default-grid-baseline, 4px) * 6);
	max-inline-size: 48rem;
}

.cohort-gradebook__publish select {
	display: block;
	margin-block: calc(var(--default-grid-baseline, 4px) * 1)
		calc(var(--default-grid-baseline, 4px) * 3);
}

.cohort-gradebook__muted {
	color: var(--color-text-maxcontrast);
}

.cohort-gradebook__stats {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 6);
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 3);
}

.cohort-gradebook__stats dt {
	color: var(--color-text-maxcontrast);
}

.cohort-gradebook__stats dd {
	margin: 0;
	font-weight: bold;
}

.cohort-gradebook__histogram {
	list-style: none;
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 4);
	padding: 0;
}

.cohort-gradebook__band {
	display: grid;
	grid-template-columns: 6rem 1fr 3rem;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.cohort-gradebook__band-bar {
	display: block;
	block-size: calc(var(--default-grid-baseline, 4px) * 3);
	background-color: var(--color-primary-element);
	border-radius: var(--border-radius);
}

.cohort-gradebook__confirm {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.cohort-gradebook__confirm p {
	flex-basis: 100%;
}
</style>
