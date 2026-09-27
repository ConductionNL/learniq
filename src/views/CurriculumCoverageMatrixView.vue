<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CurriculumCoverageMatrixView: which goals of a framework the curriculum
 covers, per year and subject (route /curriculum/coverage,
 curriculum-coverage-matrix-view).

 A read-only CnDataMatrix: the framework's goals as rows under their domains,
 its year labels as columns, and in each cell whether the goal is planned
 (a lesson or course aligns to it), assessed (an assignment or assessment
 does), both or neither. Below it, the gap list per subject and year. The data
 comes from the CurriculumCoverage rows the rollup keeps current; this view
 computes nothing itself beyond laying the rows out.

 @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
-->
<template>
	<div class="curriculum-coverage">
		<header class="curriculum-coverage__header">
			<h2 class="curriculum-coverage__title">
				{{ t('learniq', 'Curriculum coverage') }}
			</h2>
			<p class="curriculum-coverage__intro">
				{{
					t(
						'learniq',
						'Planned means a lesson or course works on a goal. Assessed means an assignment or assessment tests it. This shows your plan, not what learners have mastered.',
					)
				}}
			</p>
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<NcEmptyContent
			v-else-if="frameworkOptions.length === 0"
			:name="t('learniq', 'No frameworks yet')"
			:description="
				t('learniq', 'Add a competency framework with its goals first.')
			" />
		<template v-else>
			<div class="curriculum-coverage__filters">
				<NcSelect
					v-model="frameworkId"
					:options="frameworkOptions"
					:reduce="(o) => o.id"
					label="label"
					:clearable="false"
					:inputLabel="t('learniq', 'Framework')" />
				<NcSelect
					v-model="subjectKey"
					:options="subjectChoices"
					:reduce="(o) => o.id"
					label="label"
					:clearable="false"
					:inputLabel="t('learniq', 'Subject')" />
			</div>

			<NcLoadingIcon v-if="loadingFramework" :size="32" />
			<NcNoteCard v-else-if="coverageRows.length === 0" type="info">
				{{
					t(
						'learniq',
						'No coverage has been worked out for this framework yet. It fills in as soon as a goal, lesson, course, assignment or assessment in it is saved.',
					)
				}}
			</NcNoteCard>
			<template v-else>
				<p
					v-if="matrix.total"
					class="curriculum-coverage__summary"
					role="status">
					{{ summary }}
				</p>
				<CnDataMatrix
					:rows="matrix.rows"
					:columns="matrix.columns"
					:readOnly="true"
					:title="t('learniq', 'Goals by year')"
					:rowHeader="t('learniq', 'Goal')"
					:emptyLabel="t('learniq', 'No goals for this subject.')" />

				<section class="curriculum-coverage__gaps">
					<h3>{{ t('learniq', 'Gaps per subject and year') }}</h3>
					<p v-if="gaps.length === 0">
						{{
							t(
								'learniq',
								'No gaps. Every goal is planned and assessed.',
							)
						}}
					</p>
					<div
						v-for="gap in gaps"
						:key="gap.key"
						class="curriculum-coverage__gap">
						<h4>{{ gap.subject }} · {{ gap.year }}</h4>
						<template v-if="gap.notCovered.length > 0">
							<p class="curriculum-coverage__gap-kind">
								{{ t('learniq', 'Not covered') }}
							</p>
							<ul>
								<li
									v-for="name in gap.notCovered"
									:key="'n-' + name">
									{{ name }}
								</li>
							</ul>
						</template>
						<template v-if="gap.plannedNotAssessed.length > 0">
							<p class="curriculum-coverage__gap-kind">
								{{ t('learniq', 'Planned, not assessed') }}
							</p>
							<ul>
								<li
									v-for="name in gap.plannedNotAssessed"
									:key="'p-' + name">
									{{ name }}
								</li>
							</ul>
						</template>
					</div>
				</section>
			</template>
		</template>
	</div>
</template>

<script>
import { CnDataMatrix } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import {
	coverageMatrix,
	gapList,
	subjectIds,
	subjectOptions,
	subjectSelection,
} from '../utils/curriculumCoverage.js'
import { listRows, objectId, objectsUrl, oneObject } from '../utils/customPages.js'

export default {
	name: 'CurriculumCoverageMatrixView',

	components: {
		CnDataMatrix,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			loadingFramework: false,
			loadError: '',
			frameworks: [],
			frameworkId: '',
			subjectKey: 'all',
			goals: [],
			coverageRows: [],
			subjectNames: {},
		}
	},

	computed: {
		/**
		 * @return {object} The translated labels the pure builders use.
		 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
		 */
		labels() {
			return {
				plannedAssessed: this.t('learniq', 'Planned and assessed'),
				planned: this.t('learniq', 'Planned'),
				assessed: this.t('learniq', 'Assessed'),
				notCovered: this.t('learniq', 'Not covered'),
				allYears: this.t('learniq', 'All years'),
				allSubjects: this.t('learniq', 'All subjects'),
				noSubject: this.t('learniq', 'No subject'),
			}
		},

		/**
		 * @return {Array<{id: string, label: string}>} Framework choices.
		 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
		 */
		frameworkOptions() {
			return this.frameworks.map((framework) => ({
				id: objectId(framework),
				label: [framework.name, framework.edition]
					.filter(Boolean)
					.join(' · '),
			}))
		},

		/**
		 * @return {object} The selected framework row.
		 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
		 */
		framework() {
			return (
				this.frameworks.find(
					(framework) => objectId(framework) === this.frameworkId,
				) ?? {}
			)
		},

		/**
		 * @return {object} levelId to label, from the framework's scale.
		 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
		 */
		levelLabels() {
			const labels = {}
			for (const level of this.framework.proficiencyLevels ?? []) {
				if (level?.levelId)
					labels[level.levelId] = level.label || level.levelId
			}
			return labels
		},

		/**
		 * @return {Array<{id: string, label: string}>} Subject choices.
		 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
		 */
		subjectChoices() {
			return subjectOptions(this.coverageRows, this.subjectNames, this.labels)
		},

		/**
		 * @return {{columns: object[], rows: object[], total: object|null}} The matrix input.
		 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
		 */
		matrix() {
			return coverageMatrix({
				goals: this.goals,
				coverageRows: this.coverageRows,
				selection: subjectSelection(this.subjectKey),
				labels: this.labels,
				levelLabels: this.levelLabels,
			})
		},

		/**
		 * @return {string} One sentence with the selection's totals.
		 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
		 */
		summary() {
			const total = this.matrix.total ?? {}
			return this.t(
				'learniq',
				'{planned} of {goals} goals are planned and {assessed} are assessed.',
				{
					planned: total.plannedCount ?? 0,
					goals: total.goalCount ?? 0,
					assessed: total.assessedCount ?? 0,
				},
			)
		},

		/**
		 * @return {object[]} The gap list sections.
		 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-gap-list-names-the-uncovered-goals-per-subject-and-year
		 */
		gaps() {
			return gapList({
				goals: this.goals,
				coverageRows: this.coverageRows,
				subjectNames: this.subjectNames,
				labels: this.labels,
			})
		},
	},

	watch: {
		/**
		 * @return {void}
		 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
		 */
		frameworkId() {
			this.subjectKey = 'all'
			this.loadFramework()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the frameworks and preselect one: the ?framework= query, or the first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
		 */
		async load() {
			this.loading = true
			try {
				this.frameworks = listRows(
					(
						await axios.get(
							generateUrl(objectsUrl('competency-framework')),
							{ params: { _limit: 500 } },
						)
					).data,
				)
				const wanted = String(this.$route?.query?.framework ?? '')
				const ids = this.frameworkOptions.map((option) => option.id)
				this.frameworkId = ids.includes(wanted) ? wanted : (ids[0] ?? '')
			} catch {
				this.loadError = this.t(
					'learniq',
					'The frameworks could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the selected framework's goals, coverage rows and subject names.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-coverage-matrix-shows-goals-by-year-with-planned-and-assessed-marked
		 */
		async loadFramework() {
			if (!this.frameworkId) return
			this.loadingFramework = true
			try {
				const params = { frameworkId: this.frameworkId, _limit: 5000 }
				const [goals, coverage] = await Promise.all([
					axios.get(generateUrl(objectsUrl('competency')), { params }),
					axios.get(generateUrl(objectsUrl('curriculum-coverage')), {
						params,
					}),
				])
				this.goals = listRows(goals.data)
				this.coverageRows = listRows(coverage.data)
				await this.loadSubjectNames()
			} catch {
				this.goals = []
				this.coverageRows = []
				this.loadError = this.t(
					'learniq',
					'The coverage of this framework could not be loaded.',
				)
			} finally {
				this.loadingFramework = false
			}
		},

		/**
		 * Resolve each subject's Course name; an unreadable course keeps its id.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/curriculum-coverage-matrix-view/specs/competency/spec.md#requirement-a-gap-list-names-the-uncovered-goals-per-subject-and-year
		 */
		async loadSubjectNames() {
			const names = {}
			await Promise.all(
				subjectIds(this.coverageRows).map(async (id) => {
					try {
						const course = oneObject(
							(await axios.get(generateUrl(objectsUrl('course', id))))
								.data,
						)
						names[id] = course.name || course.code || id
					} catch {
						names[id] = id
					}
				}),
			)
			this.subjectNames = names
		},
	},
}
</script>

<style scoped>
.curriculum-coverage {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

.curriculum-coverage__intro,
.curriculum-coverage__summary {
	color: var(--color-text-maxcontrast);
	margin-block: calc(var(--default-grid-baseline, 4px) * 2);
}

.curriculum-coverage__filters {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 4);
}

.curriculum-coverage__gaps {
	margin-block-start: calc(var(--default-grid-baseline, 4px) * 6);
}

.curriculum-coverage__gap {
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 4);
}

.curriculum-coverage__gap-kind {
	font-weight: bold;
}
</style>
