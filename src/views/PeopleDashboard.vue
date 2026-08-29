<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 PeopleDashboard — the landing page for the People group (ADR-009 v2).

 The People group is both navigable (this dashboard) and collapsible (its four
 leaves render as nav sub-children). This component is the group's landing
 page: a single CnDashboardPage giving the people domain (learners,
 enrolments, attendance, credentials) real information value — KPI tiles plus
 a manage-list per sub-area — rather than the former tile-grid navigational
 aid (supersedes ADR-044 cards-collapse).

 One component, exactly one CnDashboardPage: never referenced as a widget slot
 on another dashboard (avoids the dashboard-in-dashboard antipattern).

 @spec openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-people-domain-dashboard
-->
<template>
	<div class="learniq-domain-dashboard">
		<CnDashboardPage :title="pageTitle" :widgets="widgets" :layout="layout">
			<template #widget-manage-learners>
				<ManageListWidget
					schema="learner-profile"
					:schemaLabel="t('learniq', 'learner')"
					:columns="['name']"
					:nameResolver="learnerName"
					indexRoute="/learner-profiles"
					:limit="6" />
			</template>
			<template #widget-manage-enrolments>
				<ManageListWidget
					schema="Enrolment"
					:schemaLabel="t('learniq', 'enrolment')"
					:columns="['name']"
					:extend="['learnerId', 'courseId']"
					:nameResolver="enrolmentName"
					indexRoute="/enrolments"
					:limit="6" />
			</template>
			<template #widget-manage-attendance>
				<ManageListWidget
					schema="attendance-record"
					:schemaLabel="t('learniq', 'attendance record')"
					:columns="['name', 'lifecycle']"
					indexRoute="/attendance/records"
					:limit="6" />
			</template>
			<template #widget-manage-credentials>
				<ManageListWidget
					schema="Credential"
					:schemaLabel="t('learniq', 'credential')"
					:columns="['name', 'lifecycle']"
					indexRoute="/credentials"
					:limit="6" />
			</template>
		</CnDashboardPage>
	</div>
</template>

<script>
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import ManageListWidget from './widgets/ManageListWidget.vue'

export default {
	name: 'PeopleDashboard',

	components: {
		CnDashboardPage,
		ManageListWidget,
	},

	computed: {
		/**
		 * The dashboard page title.
		 *
		 * @return {string}
		 * @spec openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-people-domain-dashboard
		 */
		pageTitle() {
			return this.t('learniq', 'People')
		},

		/**
		 * The CnDashboardPage `widgets` declaration.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-people-domain-dashboard
		 */
		widgets() {
			return [
				// KPI tiles are declared, not written. `type: 'stat'` resolves to
				// the shared CnStatWidget through the dashboard widget registry,
				// which counts server-side via the OpenRegister aggregation API.
				// The wrappers these replace each re-implemented the fetch and
				// answered `catch { this.count = 0 }`, so a dashboard whose
				// backend was down showed four confident zeroes.
				//
				// `schema` is the OpenRegister SLUG. It resolves by lower(slug),
				// so a multi-word title would 404 while a single-word one works
				// by coincidence.
				{
					id: 'kpi-learners',
					title: this.t('learniq', 'Learners'),
					type: 'stat',
					content: {
						label: this.t('learniq', 'Learners'),
						variant: 'success',
						clickRoute: { path: '/learner-profiles' },
						source: {
							register: 'learniq',
							schema: 'learner-profile',
							metric: 'count',
						},
					},
				},
				{
					id: 'kpi-active-enrolments',
					title: this.t('learniq', 'Active enrolments'),
					type: 'stat',
					content: {
						label: this.t('learniq', 'Active enrolments'),
						variant: 'primary',
						clickRoute: { path: '/enrolments' },
						source: {
							register: 'learniq',
							schema: 'enrolment',
							metric: 'count',
							filter: { lifecycle: 'active' },
						},
					},
				},
				{
					id: 'kpi-cohorts',
					title: this.t('learniq', 'Cohorts'),
					type: 'stat',
					content: {
						label: this.t('learniq', 'Cohorts'),
						clickRoute: { path: '/cohorts' },
						source: {
							register: 'learniq',
							schema: 'cohort',
							metric: 'count',
						},
					},
				},
				{
					id: 'kpi-open-flags',
					title: this.t('learniq', 'Open attendance flags'),
					type: 'stat',
					content: {
						label: this.t('learniq', 'Open attendance flags'),
						variant: 'warning',
						clickRoute: { path: '/attendance/flags' },
						source: {
							register: 'learniq',
							schema: 'attendance-flag',
							metric: 'count',
							filter: { lifecycle: 'open' },
						},
					},
				},
				{
					id: 'manage-learners',
					title: this.t('learniq', 'Learners'),
					type: 'custom',
				},
				{
					id: 'manage-enrolments',
					title: this.t('learniq', 'Enrolments'),
					type: 'custom',
				},
				{
					id: 'manage-attendance',
					title: this.t('learniq', 'Attendance'),
					type: 'custom',
				},
				{
					id: 'manage-credentials',
					title: this.t('learniq', 'Credentials'),
					type: 'custom',
				},
			]
		},

		/**
		 * The CnDashboardPage `layout` declaration (12-column grid).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-people-domain-dashboard
		 */
		layout() {
			return [
				{
					id: 1,
					widgetId: 'kpi-learners',
					gridX: 0,
					gridY: 0,
					gridWidth: 3,
					gridHeight: 2,
					showTitle: false,
				},
				{
					id: 2,
					widgetId: 'kpi-active-enrolments',
					gridX: 3,
					gridY: 0,
					gridWidth: 3,
					gridHeight: 2,
					showTitle: false,
				},
				{
					id: 3,
					widgetId: 'kpi-cohorts',
					gridX: 6,
					gridY: 0,
					gridWidth: 3,
					gridHeight: 2,
					showTitle: false,
				},
				{
					id: 4,
					widgetId: 'kpi-open-flags',
					gridX: 9,
					gridY: 0,
					gridWidth: 3,
					gridHeight: 2,
					showTitle: false,
				},
				{
					id: 5,
					widgetId: 'manage-learners',
					gridX: 0,
					gridY: 2,
					gridWidth: 6,
					gridHeight: 4,
				},
				{
					id: 6,
					widgetId: 'manage-enrolments',
					gridX: 6,
					gridY: 2,
					gridWidth: 6,
					gridHeight: 4,
				},
				{
					id: 7,
					widgetId: 'manage-attendance',
					gridX: 0,
					gridY: 6,
					gridWidth: 6,
					gridHeight: 4,
				},
				{
					id: 8,
					widgetId: 'manage-credentials',
					gridX: 6,
					gridY: 6,
					gridWidth: 6,
					gridHeight: 4,
				},
			]
		},
	},

	methods: {
		/**
		 * Display label for a learner-profile row: the person's name when set,
		 * otherwise the linked Nextcloud user id — never the raw object UUID.
		 *
		 * @param {object} item A learner-profile object.
		 * @return {string}
		 * @spec exclude Presentation-only helper composing a display label from object fields; no behavioural spec requirement.
		 */
		learnerName(item) {
			const full = [item.givenName, item.familyName]
				.filter(Boolean)
				.join(' ')
				.trim()
			return full || item.ncUserId || item['@self']?.name || item.id
		},

		/**
		 * Display label for an enrolment row: "learner → course", resolved from
		 * the `_extend`-expanded learnerId/courseId relations (each may arrive as
		 * an object or a plain label string).
		 *
		 * @param {object} item An enrolment object with extended relations.
		 * @return {string}
		 * @spec exclude Presentation-only helper composing a "learner → course" label from resolved relations; no behavioural spec requirement.
		 */
		enrolmentName(item) {
			const resolve = (rel) =>
				rel && typeof rel === 'object'
					? rel.name || rel['@self']?.name || rel.id
					: rel
			const learner = resolve(item.learnerId) || '?'
			const course = resolve(item.courseId) || '?'
			return `${learner} → ${course}`
		},
	},
}
</script>
