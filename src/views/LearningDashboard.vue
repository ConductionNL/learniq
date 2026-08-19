<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LearningDashboard — the landing page for the Learning group (ADR-009 v2).

 The Learning group is both navigable (this dashboard) and collapsible (its
 six leaves render as nav sub-children). This component is the group's landing
 page: a single CnDashboardPage giving the learning domain (courses,
 curriculum, learning plans, assignments, assessments, grades) real
 information value — a KPI tile plus a manage-list per sub-area — rather than
 the former tile-grid navigational aid (supersedes ADR-044 cards-collapse).

 One component, exactly one CnDashboardPage: never referenced as a widget slot
 on another dashboard (avoids the dashboard-in-dashboard antipattern).

 @spec openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-learning-domain-dashboard
-->
<template>
	<div class="scholiq-domain-dashboard">
		<CnDashboardPage :title="pageTitle" :widgets="widgets" :layout="layout">
			<template #widget-kpi-courses>
				<KpiCoursesWidget />
			</template>
			<template #widget-manage-courses>
				<ManageListWidget
					schema="Course"
					:schemaLabel="t('learniq', 'course')"
					:columns="['name', 'lifecycle', 'lessonCount']"
					indexRoute="/courses"
					:limit="6" />
			</template>
			<template #widget-manage-curriculum>
				<ManageListWidget
					schema="Programme"
					:schemaLabel="t('learniq', 'programme')"
					:columns="['name', 'lifecycle']"
					indexRoute="/curriculum/programmes"
					:limit="6" />
			</template>
			<template #widget-manage-assignments>
				<ManageListWidget
					schema="Assignment"
					:schemaLabel="t('learniq', 'assignment')"
					:columns="['name', 'dueDate', 'lifecycle']"
					indexRoute="/assignments"
					:limit="6" />
			</template>
			<template #widget-manage-assessments>
				<ManageListWidget
					schema="Assessment"
					:schemaLabel="t('learniq', 'assessment')"
					:columns="['name', 'lifecycle']"
					indexRoute="/assessments"
					:limit="6" />
			</template>
			<template #widget-manage-learning-plans>
				<ManageListWidget
					schema="learning-plan"
					:schemaLabel="t('learniq', 'learning plan')"
					:columns="['name', 'lifecycle']"
					indexRoute="/learning-plans"
					:limit="6" />
			</template>
			<template #widget-manage-grades>
				<ManageListWidget
					schema="grade-entry"
					:schemaLabel="t('learniq', 'grade')"
					:columns="['name', 'lifecycle']"
					indexRoute="/grades/entries"
					:limit="6" />
			</template>
		</CnDashboardPage>
	</div>
</template>

<script>
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import KpiCoursesWidget from './widgets/KpiCoursesWidget.vue'
import ManageListWidget from './widgets/ManageListWidget.vue'

export default {
	name: 'LearningDashboard',

	components: {
		CnDashboardPage,
		KpiCoursesWidget,
		ManageListWidget,
	},

	computed: {
		/**
		 * The dashboard page title.
		 *
		 * @return {string}
		 * @spec openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-learning-domain-dashboard
		 */
		pageTitle() {
			return this.t('learniq', 'Learning')
		},

		/**
		 * The CnDashboardPage `widgets` declaration.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-learning-domain-dashboard
		 */
		widgets() {
			return [
				{
					id: 'kpi-courses',
					title: this.t('learniq', 'Courses'),
					type: 'custom',
				},
				{
					id: 'manage-courses',
					title: this.t('learniq', 'Courses'),
					type: 'custom',
				},
				{
					id: 'manage-curriculum',
					title: this.t('learniq', 'Curriculum'),
					type: 'custom',
				},
				{
					id: 'manage-assignments',
					title: this.t('learniq', 'Assignments'),
					type: 'custom',
				},
				{
					id: 'manage-assessments',
					title: this.t('learniq', 'Assessments'),
					type: 'custom',
				},
				{
					id: 'manage-learning-plans',
					title: this.t('learniq', 'Learning plans'),
					type: 'custom',
				},
				{
					id: 'manage-grades',
					title: this.t('learniq', 'Grades'),
					type: 'custom',
				},
			]
		},

		/**
		 * The CnDashboardPage `layout` declaration (12-column grid).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-learning-domain-dashboard
		 */
		layout() {
			return [
				{
					id: 1,
					widgetId: 'kpi-courses',
					gridX: 0,
					gridY: 0,
					gridWidth: 3,
					gridHeight: 2,
					showTitle: false,
				},
				{
					id: 2,
					widgetId: 'manage-courses',
					gridX: 0,
					gridY: 2,
					gridWidth: 6,
					gridHeight: 4,
				},
				{
					id: 3,
					widgetId: 'manage-curriculum',
					gridX: 6,
					gridY: 2,
					gridWidth: 6,
					gridHeight: 4,
				},
				{
					id: 4,
					widgetId: 'manage-assignments',
					gridX: 0,
					gridY: 6,
					gridWidth: 6,
					gridHeight: 4,
				},
				{
					id: 5,
					widgetId: 'manage-assessments',
					gridX: 6,
					gridY: 6,
					gridWidth: 6,
					gridHeight: 4,
				},
				{
					id: 6,
					widgetId: 'manage-learning-plans',
					gridX: 0,
					gridY: 10,
					gridWidth: 6,
					gridHeight: 4,
				},
				{
					id: 7,
					widgetId: 'manage-grades',
					gridX: 6,
					gridY: 10,
					gridWidth: 6,
					gridHeight: 4,
				},
			]
		},
	},
}
</script>
