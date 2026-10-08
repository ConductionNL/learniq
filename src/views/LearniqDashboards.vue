<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LearniqDashboards — the single role-aware dashboard (ADR-009 §6).

 One component, one CnDashboardPage, that re-renders for the active role:
   - admin   → KPI overview + manage lists
   - teacher → instructor management lists (courses, assignments, sessions, cohorts).
               A group teacher (primary role `instructor`) sees only the
               cohorts they teach and what hangs off them; coordinators,
               directors and team leads keep the school-wide lists.
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
			:layout="layout"
			:headerActions="headerActions">
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
					:filter="teacherFilters.courses"
					:pending="scopePending"
					indexRoute="/courses"
					:limit="6" />
			</template>
			<template #widget-teacher-assignments>
				<ManageListWidget
					schema="Assignment"
					:schemaLabel="t('learniq', 'assignment')"
					:columns="['title', 'dueAt', 'lifecycle']"
					:filter="teacherFilters.assignments"
					:pending="scopePending"
					indexRoute="/assignments"
					:limit="6" />
			</template>
			<template #widget-teacher-sessions>
				<ManageListWidget
					schema="Session"
					:schemaLabel="t('learniq', 'session')"
					:columns="['title', 'startsAt', 'lifecycle']"
					:filter="sessionsToMark"
					:pending="scopePending"
					indexRoute="/sessions"
					:rowRoute="rollCallRoute"
					:footerLabel="t('learniq', 'Today\'s register')"
					:footerRoute="rollCallPath"
					:limit="6" />
			</template>
			<template #widget-teacher-cohorts>
				<ManageListWidget
					schema="Cohort"
					:schemaLabel="t('learniq', 'cohort')"
					:columns="['name', 'period', 'lifecycle']"
					:filter="teacherFilters.cohorts"
					:pending="scopePending"
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
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import ManageCohortsWidget from './widgets/ManageCohortsWidget.vue'
import ManageCoursesWidget from './widgets/ManageCoursesWidget.vue'
import ManageListWidget from './widgets/ManageListWidget.vue'
import ManageProgrammesWidget from './widgets/ManageProgrammesWidget.vue'
import MyMandatoryTrainingWidget from './widgets/MyMandatoryTrainingWidget.vue'
import {
	ROLL_CALL_PATH,
	rollCallRoute,
	sessionsToMarkFilter,
} from '../utils/rollCall.js'
import {
	appendFilter,
	isScopedTeacher,
	teacherScope,
	teacherTileSource,
	teacherWidgetFilters,
} from '../utils/teacherScope.js'

const VALID_ROLES = ['admin', 'teacher', 'student']

/** A teacher has a handful of groups; this bounds the scope read. */
const SCOPE_LIMIT = 200

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

		/**
		 * Buttons in the page header, from the page's manifest `config`
		 * (CnDashboardPage `headerActions`). The simple structure gives
		 * pupils and guardians "Report a concern" here; nothing else sets it,
		 * so the page shows no extra button.
		 *
		 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
		 */
		headerActions: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			rollCallPath: ROLL_CALL_PATH,
			// The group teacher's cohorts and courses; null = school-wide.
			scope: null,
			scopePending: false,
		}
	},

	computed: {
		/**
		 * The list filter of each teacher widget: the group teacher's own
		 * groups, or no filter for school-wide roles.
		 *
		 * @return {{cohorts: object, courses: object, sessions: object, assignments: object}}
		 * @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups
		 */
		teacherFilters() {
			return teacherWidgetFilters(getCurrentUser()?.uid ?? '', this.scope)
		},

		/**
		 * "Sessions to mark": the lessons of the teacher's scope that started
		 * today or earlier, newest first (attendance-roll-call).
		 *
		 * @return {object}
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-the-teacher-reaches-the-register-from-the-menu-and-the-dashboard
		 */
		sessionsToMark() {
			return sessionsToMarkFilter(this.teacherFilters.sessions, new Date())
		},

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
						// A group teacher's tile averages only the pupils of their
						// own groups (teacher-dashboard-engagement-tiles).
						content: {
							label: this.t('learniq', 'Avg. engagement score'),
							clickRoute: { path: '/progress/engagement-scores' },
							source: this.teacherTile({
								register: 'learniq',
								schema: 'engagement-score',
								metric: 'avg',
								field: 'score',
							}),
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
							source: this.teacherTile({
								register: 'learniq',
								schema: 'engagement-risk-flag',
								metric: 'count',
								filter: { lifecycle: 'open' },
							}),
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

	watch: {
		/**
		 * Work out the teacher scope when the teacher view opens.
		 *
		 * @return {void}
		 * @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups
		 */
		activeRole() {
			this.loadTeacherScope()
		},
	},

	created() {
		this.loadTeacherScope()
	},

	methods: {
		/**
		 * The roll-call of a lesson's group and day, where "Sessions to mark" leads.
		 *
		 * @param {object} session A Session row.
		 * @return {object} A vue-router location.
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-the-teacher-reaches-the-register-from-the-menu-and-the-dashboard
		 */
		rollCallRoute(session) {
			return rollCallRoute(session)
		},

		/**
		 * The aggregation source of a teacher-view stat tile: scoped to the
		 * pupils of a group teacher's own groups, school-wide for other roles.
		 * A tile without a source (scope loading, or no pupils) asks nothing.
		 *
		 * @param {object} source The declared aggregation source.
		 * @return {object|undefined} The source to aggregate, or none.
		 * @spec openspec/changes/teacher-dashboard-engagement-tiles/specs/dashboard/spec.md#requirement-the-engagement-tiles-of-a-group-teacher-count-only-their-own-groups
		 */
		teacherTile(source) {
			return (
				teacherTileSource(source, this.scope, this.scopePending) ?? undefined
			)
		},

		/**
		 * Work out which cohorts and courses a group teacher's lists show:
		 * the cohorts that list them in `teacherIds`, and the courses those
		 * cohorts run. School-wide roles get no scope. A failed read leaves
		 * the teacher with empty lists, never the whole school.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups
		 */
		async loadTeacherScope() {
			const userId = getCurrentUser()?.uid ?? ''
			if (
				this.activeRole !== 'teacher'
				|| !isScopedTeacher(loadState('learniq', 'primaryRole', ''))
			) {
				this.scope = null
				this.scopePending = false
				return
			}

			this.scopePending = true
			try {
				const cohorts = await this.listObjects('Cohort', {
					teacherIds: userId,
				})
				const { programmeIds } = teacherScope({ userId, cohorts })
				const programmes = programmeIds.length
					? await this.listObjects('Programme', { _ids: programmeIds })
					: []
				this.scope = teacherScope({ userId, cohorts, programmes })
			} catch {
				this.scope = {
					cohortIds: [],
					courseIds: [],
					programmeIds: [],
					learnerIds: [],
				}
			} finally {
				this.scopePending = false
			}
		},

		/**
		 * One page of learniq objects of a schema, filtered.
		 *
		 * @param {string} schema The schema.
		 * @param {object} filter The list filter.
		 * @return {Promise<object[]>}
		 * @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups
		 */
		async listObjects(schema, filter) {
			const params = appendFilter(
				new URLSearchParams({ _limit: String(SCOPE_LIMIT) }),
				filter,
			)
			const response = await axios.get(
				generateUrl(
					'/apps/openregister/api/objects/learniq/'
						+ schema
						+ '?'
						+ params.toString(),
				),
			)
			const data = response.data ?? {}
			return data.results ?? (Array.isArray(data) ? data : [])
		},

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
