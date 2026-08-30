<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LearniqDashboards — the single role-aware dashboard (ADR-009 §6).

 One component, one CnDashboardPage, that re-renders for the active role:
   - admin   → KPI overview + manage lists
   - teacher → instructor management lists (courses, assignments, sessions, cohorts)
   - student → the learner's own mandatory-training obligations

 The default view comes from the user's resolved role (initial state
 `dashboardRole`); users who can access more than one view (initial state
 `dashboardRoles`) get an in-page switcher. Replaces the old
 LearniqDashboard.vue wrapper that nested a second CnDashboardPage inside a
 dashboard widget (the dashboard-in-dashboard antipattern).

 @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
-->
<template>
	<div class="learniq-dashboards">
		<CnDashboardPage
			:key="activeRole"
			:title="pageTitle"
			:widgets="widgets"
			:layout="layout">
			<!-- Admin view -->
			<template #widget-manage-courses>
				<ManageCoursesWidget />
			</template>
			<template #widget-manage-cohorts>
				<ManageCohortsWidget />
			</template>
			<template #widget-manage-programmes>
				<ManageProgrammesWidget />
			</template>

			<!-- Teacher view -->
			<template #widget-teacher-courses>
				<ManageListWidget
					schema="Course"
					:schemaLabel="t('learniq', 'course')"
					:columns="['name', 'lifecycle', 'lessonCount']"
					indexRoute="/courses"
					:limit="6" />
			</template>
			<template #widget-teacher-assignments>
				<ManageListWidget
					schema="Assignment"
					:schemaLabel="t('learniq', 'assignment')"
					:columns="['name', 'dueDate', 'lifecycle']"
					indexRoute="/assignments"
					:limit="6" />
			</template>
			<template #widget-teacher-sessions>
				<ManageListWidget
					schema="Session"
					:schemaLabel="t('learniq', 'session')"
					:columns="['name', 'startsAt', 'lifecycle']"
					indexRoute="/sessions"
					:limit="6" />
			</template>
			<template #widget-teacher-cohorts>
				<ManageListWidget
					schema="Cohort"
					:schemaLabel="t('learniq', 'cohort')"
					:columns="['name', 'learnerCount', 'programmeId']"
					indexRoute="/cohorts"
					:limit="6" />
			</template>

			<!-- Student view -->
			<template #widget-my-mandatory-training>
				<MyMandatoryTrainingWidget />
			</template>
		</CnDashboardPage>
	</div>
</template>

<script>
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import ManageCohortsWidget from './widgets/ManageCohortsWidget.vue'
import ManageCoursesWidget from './widgets/ManageCoursesWidget.vue'
import ManageListWidget from './widgets/ManageListWidget.vue'
import ManageProgrammesWidget from './widgets/ManageProgrammesWidget.vue'
import MyMandatoryTrainingWidget from './widgets/MyMandatoryTrainingWidget.vue'

const VALID_ROLES = ['admin', 'teacher', 'student']

export default {
	name: 'LearniqDashboards',

	components: {
		CnDashboardPage,
		ManageCoursesWidget,
		ManageCohortsWidget,
		ManageProgrammesWidget,
		ManageListWidget,
		MyMandatoryTrainingWidget,
	},

	props: {
		/**
		 * Which dashboard view to render: 'admin' | 'teacher' | 'student'.
		 * Supplied by the thin per-role route wrapper (DashboardAdmin/Teacher/
		 * Student). Falls back to the user's resolved default view when empty.
		 */
		role: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * The active dashboard role — the `role` prop when valid, otherwise the
		 * user's resolved default view (initial state `dashboardRole`).
		 *
		 * @return {string}
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		activeRole() {
			if (VALID_ROLES.includes(this.role)) {
				return this.role
			}
			const dflt = loadState('learniq', 'dashboardRole', 'student')
			return VALID_ROLES.includes(dflt) ? dflt : 'student'
		},

		/**
		 * The dashboard page title for the active role view.
		 *
		 * @return {string}
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		pageTitle() {
			return (
				this.roleLabel(this.activeRole)
				+ ' · '
				+ this.t('learniq', 'Dashboard')
			)
		},

		/**
		 * The CnDashboardPage `widgets` declaration for the active role.
		 *
		 * @return {Array<object>}
		 */
		widgets() {
			return this.viewConfig.widgets
		},

		/**
		 * The CnDashboardPage `layout` declaration for the active role.
		 *
		 * @return {Array<object>}
		 */
		layout() {
			return this.viewConfig.layout
		},

		/**
		 * Resolve the widgets + layout for the active role view.
		 *
		 * @return {{widgets: Array<object>, layout: Array<object>}}
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		viewConfig() {
			if (this.activeRole === 'admin') {
				return this.adminConfig
			}
			if (this.activeRole === 'teacher') {
				return this.teacherConfig
			}
			return this.studentConfig
		},

		/**
		 * Admin KPI + manage layout (the previous default dashboard).
		 *
		 * @return {{widgets: Array<object>, layout: Array<object>}}
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		adminConfig() {
			return {
				widgets: [
					{
						id: 'kpi-courses',
						title: this.t('learniq', 'Courses'),
						// Declared, not written. `type: 'stat'` resolves to the shared
						// CnStatWidget, which aggregates SERVER-side; the wrappers this
						// replaces each did their own fetch and swallowed failure into a
						// zero. `schema` is the OpenRegister SLUG.
						type: 'stat',
						content: {
							label: this.t('learniq', 'Courses'),
							variant: 'primary',
							clickRoute: { path: '/courses' },
							source: {
								register: 'learniq',
								schema: 'course',
								metric: 'count',
							},
						},
					},
					{
						id: 'kpi-cohorts',
						title: this.t('learniq', 'Cohorts'),
						// Declared, not written. `type: 'stat'` resolves to the shared
						// CnStatWidget, which aggregates SERVER-side; the wrappers this
						// replaces each did their own fetch and swallowed failure into a
						// zero. `schema` is the OpenRegister SLUG.
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
						id: 'kpi-learners',
						title: this.t('learniq', 'Learners'),
						// Declared, not written. `type: 'stat'` resolves to the shared
						// CnStatWidget, which aggregates SERVER-side; the wrappers this
						// replaces each did their own fetch and swallowed failure into a
						// zero. `schema` is the OpenRegister SLUG.
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
						// Declared, not written. `type: 'stat'` resolves to the shared
						// CnStatWidget, which aggregates SERVER-side; the wrappers this
						// replaces each did their own fetch and swallowed failure into a
						// zero. `schema` is the OpenRegister SLUG.
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
						id: 'kpi-open-flags',
						title: this.t('learniq', 'Open attendance flags'),
						// Declared, not written. `type: 'stat'` resolves to the shared
						// CnStatWidget, which aggregates SERVER-side; the wrappers this
						// replaces each did their own fetch and swallowed failure into a
						// zero. `schema` is the OpenRegister SLUG.
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
						id: 'manage-courses',
						title: this.t('learniq', 'Courses'),
						type: 'custom',
					},
					{
						id: 'manage-cohorts',
						title: this.t('learniq', 'Cohorts'),
						type: 'custom',
					},
					{
						id: 'manage-programmes',
						title: this.t('learniq', 'Programmes'),
						type: 'custom',
					},
				],

				layout: [
					{
						id: 1,
						widgetId: 'kpi-courses',
						gridX: 0,
						gridY: 0,
						gridWidth: 2,
						gridHeight: 2,
						showTitle: false,
					},
					{
						id: 2,
						widgetId: 'kpi-cohorts',
						gridX: 2,
						gridY: 0,
						gridWidth: 2,
						gridHeight: 2,
						showTitle: false,
					},
					{
						id: 3,
						widgetId: 'kpi-learners',
						gridX: 4,
						gridY: 0,
						gridWidth: 2,
						gridHeight: 2,
						showTitle: false,
					},
					{
						id: 4,
						widgetId: 'kpi-active-enrolments',
						gridX: 6,
						gridY: 0,
						gridWidth: 2,
						gridHeight: 2,
						showTitle: false,
					},
					{
						id: 5,
						widgetId: 'kpi-open-flags',
						gridX: 8,
						gridY: 0,
						gridWidth: 2,
						gridHeight: 2,
						showTitle: false,
					},
					{
						id: 6,
						widgetId: 'manage-courses',
						gridX: 0,
						gridY: 2,
						gridWidth: 4,
						gridHeight: 4,
					},
					{
						id: 7,
						widgetId: 'manage-cohorts',
						gridX: 4,
						gridY: 2,
						gridWidth: 4,
						gridHeight: 4,
					},
					{
						id: 8,
						widgetId: 'manage-programmes',
						gridX: 8,
						gridY: 2,
						gridWidth: 4,
						gridHeight: 4,
					},
				],
			}
		},

		/**
		 * Teacher management layout.
		 *
		 * @return {{widgets: Array<object>, layout: Array<object>}}
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		teacherConfig() {
			return {
				widgets: [
					// learning-progress-and-analytics: declarative KPI tiles
					// surfacing average EngagementScore.score and open
					// EngagementRiskFlag counts — no new chart component.
					{
						id: 'kpi-engagement-score',
						title: this.t('learniq', 'Avg. engagement score'),
						// Declared, not written. `type: 'stat'` resolves to the shared
						// CnStatWidget, which aggregates SERVER-side; the wrappers this
						// replaces each did their own fetch and swallowed failure into a
						// zero. `schema` is the OpenRegister SLUG.
						type: 'stat',
						content: {
							label: this.t('learniq', 'Avg. engagement score'),
							clickRoute: { path: '/progress/engagement-scores' },
							source: {
								register: 'learniq',
								schema: 'engagement-score',
								metric: 'avg',
								field: 'score',
							},
						},
					},
					{
						id: 'kpi-engagement-flags',
						title: this.t('learniq', 'Open engagement flags'),
						// Declared, not written. `type: 'stat'` resolves to the shared
						// CnStatWidget, which aggregates SERVER-side; the wrappers this
						// replaces each did their own fetch and swallowed failure into a
						// zero. `schema` is the OpenRegister SLUG.
						type: 'stat',
						content: {
							label: this.t('learniq', 'Open engagement flags'),
							variant: 'warning',
							clickRoute: { path: '/progress/engagement-flags' },
							source: {
								register: 'learniq',
								schema: 'engagement-risk-flag',
								metric: 'count',
								filter: { lifecycle: 'open' },
							},
						},
					},
					{
						id: 'teacher-courses',
						title: this.t('learniq', 'My courses'),
						type: 'custom',
					},
					{
						id: 'teacher-assignments',
						title: this.t('learniq', 'Assignments to grade'),
						type: 'custom',
					},
					{
						id: 'teacher-sessions',
						title: this.t('learniq', 'Sessions to mark'),
						type: 'custom',
					},
					{
						id: 'teacher-cohorts',
						title: this.t('learniq', 'My cohorts'),
						type: 'custom',
					},
				],

				layout: [
					{
						id: 1,
						widgetId: 'kpi-engagement-score',
						gridX: 0,
						gridY: 0,
						gridWidth: 6,
						gridHeight: 2,
						showTitle: false,
					},
					{
						id: 2,
						widgetId: 'kpi-engagement-flags',
						gridX: 6,
						gridY: 0,
						gridWidth: 6,
						gridHeight: 2,
						showTitle: false,
					},
					{
						id: 3,
						widgetId: 'teacher-courses',
						gridX: 0,
						gridY: 2,
						gridWidth: 6,
						gridHeight: 4,
					},
					{
						id: 4,
						widgetId: 'teacher-assignments',
						gridX: 6,
						gridY: 2,
						gridWidth: 6,
						gridHeight: 4,
					},
					{
						id: 5,
						widgetId: 'teacher-sessions',
						gridX: 0,
						gridY: 6,
						gridWidth: 6,
						gridHeight: 4,
					},
					{
						id: 6,
						widgetId: 'teacher-cohorts',
						gridX: 6,
						gridY: 6,
						gridWidth: 6,
						gridHeight: 4,
					},
				],
			}
		},

		/**
		 * Student layout — the learner's own mandatory-training obligations,
		 * plus (engagement-gamification) the learner's own points/level/streak
		 * KPI, visible unconditionally regardless of any Leaderboard/opt-out
		 * state. Deliberately limited to user-scoped widgets to avoid exposing
		 * other learners' records.
		 *
		 * @return {{widgets: Array<object>, layout: Array<object>}}
		 * @spec openspec/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
		 */
		studentConfig() {
			return {
				widgets: [
					{
						id: 'my-mandatory-training',
						title: this.t('learniq', 'My mandatory training'),
						type: 'custom',
					},
					// engagement-gamification: the learner's own points/level/streak KPI —
					// always visible regardless of any Leaderboard/opt-out state.
					//
					// Declared, not written. This was the last bespoke KPI tile in the
					// app: it could not be a plain `source` aggregation because it needs
					// a JOIN across learner-engagement and engagement-level, and a
					// two-call frontend could not make that join succeed or fail as one
					// — a silent level-lookup failure rendered as "has points, no level",
					// indistinguishable from a learner who has not reached one.
					// /api/engagement/me serves the joined record, so the tile is config.
					{
						id: 'kpi-points-level',
						title: this.t('learniq', 'My points'),
						type: 'stat',
						content: {
							label: this.t('learniq', 'My points'),
							variant: 'primary',
							endpointSource: {
								url: '/apps/learniq/api/engagement/me',
							},

							valueField: 'totalPoints',
							// The server composes this line: a caption template resolves a
							// missing token to '', which would leave a learner with no level
							// showing an orphan separator.
							caption: '{summary}',
						},
					},
				],

				layout: [
					{
						id: 1,
						widgetId: 'my-mandatory-training',
						gridX: 0,
						gridY: 0,
						gridWidth: 6,
						gridHeight: 5,
					},
					{
						id: 2,
						widgetId: 'kpi-points-level',
						gridX: 6,
						gridY: 0,
						gridWidth: 6,
						gridHeight: 2,
						showTitle: false,
					},
				],
			}
		},
	},

	methods: {
		/**
		 * Localized human label for a dashboard role view.
		 *
		 * @param {string} role One of admin|teacher|student.
		 * @return {string}
		 * @spec exclude Presentation-only label map for the three fixed role names; the role-gating behaviour itself is covered by viewConfig/activeRole, not by this string lookup.
		 */
		roleLabel(role) {
			if (role === 'admin') {
				return this.t('learniq', 'Administrator')
			}
			if (role === 'teacher') {
				return this.t('learniq', 'Teacher')
			}
			return this.t('learniq', 'Student')
		},
	},
}
</script>

<style scoped>
.learniq-dashboards__rolebar {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px 0;
}

.learniq-dashboards__rolebar-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.learniq-dashboards__roleselect {
	min-width: 200px;
}
</style>
