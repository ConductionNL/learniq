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
			:greeting="greeting"
			:description="pageSubtitle"
			:widgets="widgets"
			:layout="layout"
			:headerActions="headerActions"
			:showActionsMenu="false"
			:showWidgetActions="false">
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
	countTile,
	customTile,
	freshLayout,
	statTile,
} from '../utils/dashboardTiles.js'
import {
	ROLL_CALL_PATH,
	rollCallRoute,
	sessionsToMarkFilter,
} from '../utils/rollCall.js'
import { roleLine, soleSchoolName } from '../utils/schoolName.js'
import {
	appendFilter,
	isScopedTeacher,
	teacherScope,
	teacherTileSource,
	teacherWidgetFilters,
} from '../utils/teacherScope.js'

const VALID_ROLES = ['admin', 'teacher', 'student']

/** The admin dashboard grid: the KPI row, then the three manage lists. */
const ADMIN_LAYOUT = [
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
]

/** The teacher dashboard grid: the two engagement tiles, then four lists. */
const TEACHER_LAYOUT = [
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
]

/** The learner dashboard grid: mandatory training beside the points tile. */
const STUDENT_LAYOUT = [
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
]

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

		/**
		 * The page title, from the page's manifest `config`, in English; the
		 * page translates it. The boards call every role's start page Today.
		 *
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		title: {
			type: String,
			default: 'Today',
		},

		/**
		 * What leads the subtitle under the title (CnDashboardPage
		 * `greeting`), from the page's manifest `config`. `date` puts the
		 * date there, as the boards do.
		 *
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		greeting: {
			type: [Boolean, String],
			default: false,
		},

		/**
		 * The subtitle after the date, per primary role, from the page's
		 * manifest `config` (`{ "<primary role>": "<English text>" }`). A
		 * role without a line gets the date alone.
		 *
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		subtitleByRole: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * The subtitle per primary role that names the school (`{school}`),
		 * from the page's manifest `config`. Used once the school is known;
		 * until then, and on an instance with several schools, the role's
		 * `subtitleByRole` line shows.
		 *
		 * @spec openspec/changes/vo-lesson-store-and-wednesday-records/specs/dashboard/spec.md#requirement-the-hr-today-subtitle-names-the-school
		 */
		subtitleOfSchoolByRole: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			rollCallPath: ROLL_CALL_PATH,
			// The group teacher's cohorts and courses; null = school-wide.
			scope: null,
			scopePending: false,
			// The name of the instance's one school; '' when not known.
			schoolName: '',
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
		 * The dashboard page title, translated.
		 *
		 * @return {string}
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		pageTitle() {
			return this.t('learniq', this.title)
		},

		/**
		 * The subtitle line for the reader's primary role, translated, or ''.
		 *
		 * @return {string}
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		pageSubtitle() {
			const role = loadState('learniq', 'primaryRole', '')
			const ofSchool = roleLine(this.subtitleOfSchoolByRole, role)
			if (this.schoolName !== '' && ofSchool !== '') {
				return this.t('learniq', ofSchool, { school: this.schoolName }, undefined, { escape: false })
			}
			const line = roleLine(this.subtitleByRole, role)
			return line !== '' ? this.t('learniq', line) : ''
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
					...this.adminCountTiles(),
					customTile('manage-courses', this.t('learniq', 'Courses')),
					customTile('manage-cohorts', this.t('learniq', 'Cohorts')),
					customTile('manage-programmes', this.t('learniq', 'Programmes')),
				],

				layout: freshLayout(ADMIN_LAYOUT),
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
					this.engagementScoreTile,
					this.engagementFlagsTile,
					...this.teacherListTiles(),
				],

				layout: freshLayout(TEACHER_LAYOUT),
			}
		},

		/**
		 * The teacher's average engagement score tile. A group teacher's tile
		 * averages only the pupils of their own groups
		 * (teacher-dashboard-engagement-tiles).
		 *
		 * @return {object} The widget.
		 * @spec openspec/changes/teacher-dashboard-engagement-tiles/specs/dashboard/spec.md#requirement-the-engagement-tiles-of-a-group-teacher-count-only-their-own-groups
		 */
		engagementScoreTile() {
			return statTile('kpi-engagement-score', {
				label: this.t('learniq', 'Avg. engagement score'),
				clickRoute: { path: '/progress/engagement-scores' },
				source: this.teacherTile({
					register: 'learniq',
					schema: 'engagement-score',
					metric: 'avg',
					field: 'score',
				}),
			})
		},

		/**
		 * The teacher's open engagement flags tile, scoped like the score tile.
		 *
		 * @return {object} The widget.
		 * @spec openspec/changes/teacher-dashboard-engagement-tiles/specs/dashboard/spec.md#requirement-the-engagement-tiles-of-a-group-teacher-count-only-their-own-groups
		 */
		engagementFlagsTile() {
			return statTile('kpi-engagement-flags', {
				label: this.t('learniq', 'Open engagement flags'),
				variant: 'warning',
				clickRoute: { path: '/progress/engagement-flags' },
				source: this.teacherTile({
					register: 'learniq',
					schema: 'engagement-risk-flag',
					metric: 'count',
					filter: { lifecycle: 'open' },
				}),
			})
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
					customTile(
						'my-mandatory-training',
						this.t('learniq', 'My mandatory training'),
					),
					this.pointsTile(),
				],

				layout: freshLayout(STUDENT_LAYOUT),
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

	/**
	 * Load what the page's lists and subtitle read.
	 *
	 * @spec openspec/changes/vo-lesson-store-and-wednesday-records/specs/dashboard/spec.md#requirement-the-hr-today-subtitle-names-the-school
	 */
	created() {
		this.loadTeacherScope()
		this.loadSchoolName()
	},

	methods: {
		/**
		 * The admin dashboard's KPI row.
		 *
		 * @return {Array<object>} The count tiles.
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		adminCountTiles() {
			return [
				countTile('kpi-courses', this.t('learniq', 'Courses')),
				countTile('kpi-cohorts', this.t('learniq', 'Cohorts')),
				countTile('kpi-learners', this.t('learniq', 'Learners')),
				countTile('kpi-active-enrolments', this.t('learniq', 'Active enrolments')),
				countTile('kpi-open-flags', this.t('learniq', 'Open attendance flags')),
			]
		},

		/**
		 * The teacher dashboard's four lists.
		 *
		 * @return {Array<object>} The list tiles.
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		teacherListTiles() {
			return [
				customTile('teacher-courses', this.t('learniq', 'My courses')),
				customTile('teacher-assignments', this.t('learniq', 'Assignments to grade')),
				customTile('teacher-sessions', this.t('learniq', 'Sessions to mark')),
				customTile('teacher-cohorts', this.t('learniq', 'My cohorts')),
			]
		},

		/**
		 * The learner's own points/level/streak KPI (engagement-gamification),
		 * always visible regardless of any Leaderboard/opt-out state.
		 *
		 * Declared, not written. This was the last bespoke KPI tile in the
		 * app: it could not be a plain `source` aggregation because it needs
		 * a JOIN across learner-engagement and engagement-level, and a
		 * two-call frontend could not make that join succeed or fail as one
		 * — a silent level-lookup failure rendered as "has points, no level",
		 * indistinguishable from a learner who has not reached one.
		 * /api/engagement/me serves the joined record, so the tile is config.
		 *
		 * @return {object} The widget.
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		pointsTile() {
			return statTile('kpi-points-level', {
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
			})
		},

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
		 * Read the school's name when the reader's subtitle names it. A failed
		 * read leaves the subtitle without the name.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/vo-lesson-store-and-wednesday-records/specs/dashboard/spec.md#requirement-the-hr-today-subtitle-names-the-school
		 */
		async loadSchoolName() {
			const role = loadState('learniq', 'primaryRole', '')
			if (roleLine(this.subtitleOfSchoolByRole, role) === '') {
				return
			}
			try {
				this.schoolName = soleSchoolName(await this.listObjects('School', {}))
			} catch {
				this.schoolName = ''
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
