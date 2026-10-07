// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The structure profile (openspec/changes/simple-structure-profile): two menus
// built from one manifest, the simple one by default.
//
// 🔴 BOTH MENUS ARE BUILT, NOT READ. Every check below builds the manifest
// with the library's own buildManifest() and evaluates each `visibleIf` with
// the library's own evaluator, per role, the way src/main.js and CnAppNav do.
// A check that read the layout file would pass while a group swallowed an
// entry or a gate hid it.
//
// What this file holds still:
//   1. the full profile is EXACTLY the plain manifest build (nothing moved);
//   2. both profiles hold the same pages;
//   3. the simple menu is flat, captioned and at most ten entries per role;
//   4. a gate written in the simple file only narrows, never widens;
//   5. NO-LOSS, per role: every page the full menu offers a role is one step
//      away in the simple one, or is named in KNOWN_UNLINKED below. That list
//      is exact in both directions, so it cannot grow or shrink unnoticed.
//
// Run by `npm run check:structure-profile`, which `check:specs` (a CI
// frontend check) calls.
//
// @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { test } from 'node:test'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { passesContextPredicates } from '../../node_modules/@conduction/nextcloud-vue/src/utils/visibleIfContext.js'
import {
	loadMenuStructure,
	saveMenuStructure,
} from '../../src/services/menuStructureSetting.js'
import { applyReportCardGates } from '../../src/utils/reportCardGates.js'
import {
	applyPageOverlay,
	buildProfiledManifest,
	navTheming,
	resolveStructureProfile,
	STRUCTURE_FULL,
	STRUCTURE_SETTING,
	STRUCTURE_SIMPLE,
} from '../../src/utils/structureProfile.js'
import { buildWorkspaceRuntime } from '../../src/utils/workspaceRuntime.js'

const root = new URL('../../', import.meta.url)
const readText = (path) => readFileSync(new URL(path, root), 'utf8')
const readJson = (path) => JSON.parse(readText(path))

const BASE = readJson('src/manifest.json')
const FULL = readJson('src/menu-layout.json')
const SIMPLE = readJson('src/menu-layout.simple.json')
const FRAGMENTS = readdirSync(new URL('src/manifest.d/', root))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src/manifest.d/' + name))

/** Every role DashboardRoleService::resolvePrimaryRole() can emit. */
const ROLES = [
	'admin',
	'compliance-officer',
	'hr',
	'administration-manager',
	'team-lead',
	'coordinator',
	'instructor',
	'confidential-counsellor',
	'guardian',
	'learner',
]

/** An install that never chose a segment: every segment gate passes. */
const NEVER = { segment: 'corporate', chosen: null }
/** The states after an admin chose a segment in the wizard. */
const CHOSEN = ['corporate', 'training', 'po', 'vo', 'mbo', 'he'].map((code) => ({
	segment: code,
	chosen: code,
}))

/**
 * The dashboard views a role may open, as DashboardRoleService::resolveViews()
 * answers them.
 *
 * @param {string} role The primary role.
 * @return {Array<string>} The views.
 */
function viewsOf(role) {
	if (role === 'admin') {
		return ['admin', 'teacher', 'student']
	}
	const views = []
	if (['hr', 'compliance-officer'].includes(role)) {
		views.push('admin')
	}
	if (
		[
			'administration-manager',
			'team-lead',
			'coordinator',
			'instructor',
		].includes(role)
	) {
		views.push('teacher')
	}
	views.push('student')
	return views
}

/**
 * Build one profile for one signed-in user, as src/main.js does.
 *
 * @param {object} layout The profile file.
 * @param {string} role The primary role.
 * @param {{segment: string, chosen: (string|null)}} state The workspace state.
 * @param {{counsellor?: boolean, manager?: boolean}} flags The two per-user flags.
 * @return {object} The built manifest, Reports cards filtered.
 */
function build(layout, role, state = NEVER, flags = {}) {
	const base = structuredClone(BASE)
	const views = viewsOf(role)
	base.runtime = {
		user: {
			primaryRole: role,
			canAdminDashboard: views.includes('admin'),
			canTeachDashboard: views.includes('teacher'),
			canLearnDashboard: views.includes('student'),
			isConfidentialCounsellor:
				role === 'confidential-counsellor' || flags.counsellor === true,
			managesLearners: flags.manager === true,
		},
		workspace: buildWorkspaceRuntime(undefined, state.segment, state.chosen),
	}
	return applyReportCardGates(
		buildProfiledManifest(
			buildManifest,
			base,
			structuredClone(FRAGMENTS),
			structuredClone(layout),
			passesContextPredicates,
		),
	)
}

/** The overlays that only append links: header links on lists, cards on Reports. */
const LINK_OVERLAYS = SIMPLE.pages.filter((overlay) => overlay.configAppend)
/** The overlay that turns the page at `/` into the Today dashboard. */
const TODAY = SIMPLE.pages.find((overlay) => overlay.id === 'Dashboard')
/** The roles the Today dashboard is for. */
const TODAY_ROLES = ['instructor', 'coordinator', 'administration-manager', 'admin']

function flat(items) {
	return items.flatMap((item) => [item, ...flat(item.children || [])])
}
const sectionOf = (item) => item.section ?? 'main'
const isCaption = (item) => item.type === 'caption'
function shown(manifest) {
	return flat(manifest.menu).filter((item) =>
		passesContextPredicates(item.visibleIf, manifest.runtime),
	)
}
function mainOf(manifest) {
	return shown(manifest).filter(
		(item) => sectionOf(item) === 'main' && !isCaption(item),
	)
}

/**
 * Pages a custom dashboard links from lists its own component declares. The
 * manifest cannot see them, so they are named here and checked against the
 * component source in a test below.
 */
const COMPONENT_LINKS = {
	LearningDashboard: {
		file: 'src/views/LearningDashboard.vue',
		pages: [
			'Courses',
			'Programmes',
			'Assignments',
			'Assessments',
			'LearningPlans',
			'GradeEntries',
		],
	},
	PeopleDashboard: {
		file: 'src/views/PeopleDashboard.vue',
		pages: ['LearnerProfiles', 'Enrolments', 'AttendanceRecords', 'Credentials'],
	},
}

/**
 * The route name behind a widget's `route`, which is a name or `{ name, query }`.
 *
 * @param {(string|object|undefined)} route The route.
 * @return {(string|undefined)} The route name.
 */
function routeName(route) {
	return typeof route === 'string' ? route : route?.name
}

/**
 * The pages a dashboard's tiles and cards open: a tile's `route` and a
 * banner's action routes.
 *
 * @param {object} page A built page.
 * @return {Array<string>} The route names.
 */
function dashboardLinks(page) {
	const names = []
	for (const widget of page.config?.widgets || []) {
		names.push(routeName(widget.content?.route))
		for (const action of widget.content?.actions || []) {
			names.push(routeName(action.route))
		}
	}
	return names.filter(Boolean)
}

/**
 * Every page id a signed-in user reaches in at most one step from the menu:
 * the entries they see, plus the cards, header links and lists on the pages
 * those entries open.
 *
 * @param {object} manifest A built manifest with `runtime`.
 * @return {Set<string>} The page ids.
 */
function reachable(manifest) {
	const reach = new Set(
		shown(manifest)
			.map((item) => item.route)
			.filter(Boolean),
	)
	for (const route of [...reach]) {
		const page = manifest.pages.find((candidate) => candidate.id === route)
		if (!page) {
			continue
		}
		for (const widget of page.config?.widgets || []) {
			for (const entry of widget.content?.entries || []) {
				if (passesContextPredicates(entry.visibleIf, manifest.runtime)) {
					reach.add(entry.route)
				}
			}
		}
		// Reports cards were filtered by applyReportCardGates() in build().
		for (const card of page.config?.cards || []) {
			reach.add(card.route)
		}
		for (const action of page.config?.headerActions || []) {
			if (action.handler === 'navigate' && action.route) {
				reach.add(action.route)
			}
			if (action.type === 'open-page' && action.target) {
				reach.add(action.target)
			}
		}
		for (const name of dashboardLinks(page)) {
			reach.add(name)
		}
		for (const linked of COMPONENT_LINKS[route]?.pages || []) {
			reach.add(linked)
		}
	}
	return reach
}

/**
 * The full-menu entries a role sees whose page the simple profile does not
 * put within one step for that role.
 *
 * @param {string} role The primary role.
 * @return {Array<string>} Their ids, sorted.
 */
function unlinkedFor(role) {
	const full = build(FULL, role)
	const reach = reachable(build(SIMPLE, role))
	return shown(full)
		.filter((item) => item.route && !reach.has(item.route))
		.map((item) => item.id)
		.sort()
}

/**
 * WHAT THE SIMPLE MENU DOES NOT LINK YET, per role.
 *
 * Each id is an entry of the full menu that this role sees there and that no
 * simple menu entry, card, header link or list puts within one step. The
 * pages are still routable and the full menu still offers them; what is
 * missing is a door in the simple one. They wait for the link-cards hub page
 * (three hubs would empty this list: planning, admissions, my learning).
 *
 * The list is EXACT. An entry that becomes linked must be taken out here, and
 * a new unlinked entry must be put in, or the test fails. That is the point:
 * the list is the record of what a role loses, and it may not drift.
 */
const PLANNING = [
	'ElectiveOffersMenu',
	'ExamSittingsMenu',
	'InvigilatorAssignmentsMenu',
	'InvigilatorAvailabilityMenu',
]
const KNOWN_UNLINKED = {
	admin: [
		...PLANNING,
		'AdmissionsReviewBoardMenu',
		'BookConferenceSlotsMenu',
		'CatalogueMenu',
		'CheckInMenu',
		'ConferenceScheduleBoardMenu',
		'ExamAccommodationsMenu',
		'GroupPeople',
		'HourPlanActivitiesMenu',
		'MyElectivesMenu',
		'MyEvaluationsMenu',
		'MyLearningRecordMenu',
		'MyWorkGroupsMenu',
		'RegulationExemptionsMenu',
		'SchoolAdviezenMenu',
		'SchoolEventsMenu',
		'StandbyPlanningMenu',
		'SubjectChoicePickerMenu',
		'SubjectChoicesMenu',
		'TeacherAvailabilitiesMenu',
		'TimetableConflictQueueMenu',
	],
	'compliance-officer': [
		'AbsenceReportsComplianceMenu',
		'DashboardAdmin',
		'DashboardStudent',
	],
	hr: ['DashboardAdmin', 'DashboardStudent'],
	'administration-manager': [
		...PLANNING,
		'AdmissionsReviewBoardMenu',
		'CatalogueMenu',
		'ConferenceScheduleBoardMenu',
		'ExamAccommodationsMenu',
		'GradeCorrectionsMenu',
		'GroupPlans',
		'MyLearningRecordMenu',
		'SchoolAdviezenMenu',
		'SchoolEventsMenu',
		'StandbyPlanningMenu',
		'SubjectChoicesMenu',
		'SupportRequests',
		'TeacherAvailabilitiesMenu',
		'TrajectoriesMenu',
	],
	'team-lead': [
		...PLANNING,
		'ConferenceScheduleBoardMenu',
		'DashboardStudent',
		'DashboardTeacher',
		'GradeCorrectionsMenu',
		'SchoolEventsMenu',
	],
	coordinator: [
		...PLANNING,
		'AdmissionsReviewBoardMenu',
		'CatalogueMenu',
		'ExamAccommodationsMenu',
		'GroupPeople',
		'HourPlanActivitiesMenu',
		'MyLearningRecordMenu',
		'SchoolAdviezenMenu',
		'SchoolEventsMenu',
		'StandbyPlanningMenu',
		'SubjectChoicePickerMenu',
		'SubjectChoicesMenu',
		'TeacherAvailabilitiesMenu',
		'TimetableConflictQueueMenu',
	],
	instructor: [
		...PLANNING,
		'CatalogueMenu',
		'GroupPeople',
		'HourPlanActivitiesMenu',
		'MyLearningRecordMenu',
		'SubjectChoicesMenu',
		'TeacherAvailabilitiesMenu',
		'TimetablesMenu',
	],
	'confidential-counsellor': ['DashboardStudent'],
	guardian: ['DashboardStudent'],
	learner: ['BookConferenceSlotsMenu', 'DashboardStudent', 'TimetablesMenu'],
}

/** The simple main menu, in order, captions included. */
const SIMPLE_MAIN_ORDER = [
	'HomeCaption',
	'Dashboard',
	'GroupMyLearning',
	'GroupsSimple',
	'TeachingCaption',
	'MyTimetableMenu',
	'TimetablesMenu',
	'GroupLearning',
	'GradesSimple',
	'CatalogueMenu',
	'MyLearningRecordMenu',
	'CheckInMenu',
	'MyWorkGroupsMenu',
	'MyEvaluationsMenu',
	'MyElectivesMenu',
	'SubjectChoicePickerSimple',
	'BookConversationSimple',
	'LearnersCaption',
	'LearnersSimple',
	'AttendanceSimple',
	'GroupProgress',
	'CareSimple',
	'GroupPeople',
	'GroupCompliance',
	'TeamLeadSignUpRequestsMenu',
	'ManagerSignUpRequestsMenu',
	'RegulationExemptionsMenu',
	'RequestExemptionMenu',
	'ConfidentialNotesMenu',
	'ConcernReportsMenu',
]

/** What each role sees in the simple main menu on an install that never chose a segment. */
const SIMPLE_MAIN_BY_ROLE = {
	instructor: [
		'Dashboard',
		'GroupMyLearning',
		'GroupsSimple',
		'MyTimetableMenu',
		'GroupLearning',
		'GradesSimple',
		'LearnersSimple',
		'AttendanceSimple',
		'GroupProgress',
		'CareSimple',
	],
	coordinator: [
		'Dashboard',
		'GroupMyLearning',
		'GroupsSimple',
		'MyTimetableMenu',
		'TimetablesMenu',
		'GroupLearning',
		'LearnersSimple',
		'AttendanceSimple',
		'GroupProgress',
		'CareSimple',
	],
	'administration-manager': [
		'Dashboard',
		'GroupMyLearning',
		'GroupsSimple',
		'MyTimetableMenu',
		'TimetablesMenu',
		'GroupLearning',
		'LearnersSimple',
		'AttendanceSimple',
		'GroupProgress',
		'GroupPeople',
	],
	learner: [
		'Dashboard',
		'GroupMyLearning',
		'MyTimetableMenu',
		'CatalogueMenu',
		'MyLearningRecordMenu',
		'CheckInMenu',
		'MyWorkGroupsMenu',
		'MyEvaluationsMenu',
		'MyElectivesMenu',
		'SubjectChoicePickerSimple',
	],
	guardian: [
		'Dashboard',
		'GroupMyLearning',
		'MyTimetableMenu',
		'MyLearningRecordMenu',
		'SubjectChoicePickerSimple',
		'BookConversationSimple',
	],
	admin: [
		'Dashboard',
		'GroupMyLearning',
		'GroupsSimple',
		'MyTimetableMenu',
		'TimetablesMenu',
		'GroupLearning',
		'GradesSimple',
		'LearnersSimple',
		'AttendanceSimple',
		'GroupProgress',
		'CareSimple',
		'GroupCompliance',
	],
}

/** The one page that gains a menu entry it never had. Everything else mirrors the full menu. */
const NEW_DOORS = ['Cohorts']

test('the full profile is exactly the plain manifest build', () => {
	const plain = buildManifest(
		structuredClone(BASE),
		structuredClone(FRAGMENTS),
		structuredClone(FULL),
	)
	const profiled = buildProfiledManifest(
		buildManifest,
		structuredClone(BASE),
		structuredClone(FRAGMENTS),
		structuredClone(FULL),
	)
	assert.deepEqual(profiled, plain)

	// The measured size of today's menu. A change here is a change to the
	// full menu, which this profile work must never make.
	const entries = flat(plain.menu)
	const count = (section) =>
		entries.filter((item) => sectionOf(item) === section).length
	assert.equal(entries.length, 108)
	assert.equal(plain.menu.length, 25)
	assert.deepEqual(
		[count('main'), count('footer'), count('settings')],
		[100, 3, 5],
	)
	// 351 since internship-hours T6 added the school's BPV hours list (BpvHourWeeks).
	assert.equal(plain.pages.length, 351)
})

test('menu-layout.json holds no profile key, so the full profile cannot gain an overlay', () => {
	assert.equal(FULL.menu, undefined)
	assert.equal(FULL.pages, undefined)
})

test('both profiles hold the same pages, and an overlay only appends', () => {
	const full = build(FULL, 'admin')
	const simple = build(SIMPLE, 'admin')
	assert.deepEqual(
		simple.pages.map((page) => page.id),
		full.pages.map((page) => page.id),
	)
	const overlaid = new Set(LINK_OVERLAYS.map((overlay) => overlay.id))
	for (const [index, page] of full.pages.entries()) {
		const twin = simple.pages[index]
		if (page.id === TODAY.id) {
			// The Today dashboard changes the page's kind for the roles it is
			// for. Its own tests are further down; the address must not move.
			assert.equal(twin.route, page.route)
			continue
		}
		if (!overlaid.has(page.id)) {
			assert.deepEqual(twin, page, `${page.id} differs with no overlay`)
			continue
		}
		assert.equal(twin.route, page.route)
		assert.equal(twin.type, page.type)
		for (const key of Object.keys(twin.config)) {
			const before = page.config[key]
			const after = twin.config[key]
			if (Array.isArray(after) && after.length !== (before || []).length) {
				assert.deepEqual(
					after.slice(0, (before || []).length),
					before || [],
					`${page.id}.${key}: an overlay changed an existing item`,
				)
			} else {
				assert.deepEqual(after, before, `${page.id}.${key} changed`)
			}
		}
	}
})

test('every overlay names a page, and every link in it names a page', () => {
	const full = build(FULL, 'admin')
	const pageIds = new Set(full.pages.map((page) => page.id))
	for (const overlay of LINK_OVERLAYS) {
		const page = full.pages.find((candidate) => candidate.id === overlay.id)
		assert.ok(page, `overlay names no page: ${overlay.id}`)
		assert.deepEqual(Object.keys(overlay).sort(), ['configAppend', 'id'])
		for (const [key, items] of Object.entries(overlay.configAppend)) {
			const existing = new Set((page.config[key] || []).map((item) => item.id))
			for (const item of items) {
				assert.ok(pageIds.has(item.route), `${overlay.id}: ${item.route}`)
				assert.ok(
					!existing.has(item.id),
					`${overlay.id}: id ${item.id} taken`,
				)
				assert.ok(item.label && item.icon, `${overlay.id}: ${item.id}`)
				if (key === 'headerActions') {
					assert.equal(item.handler, 'navigate')
					assert.equal(page.type, 'index', `${overlay.id} is not a list`)
				}
				if (key === 'cards') {
					assert.ok(
						Object.hasOwn(page.config.categories, item.category),
						`${overlay.id}: category ${item.category}`,
					)
					assert.ok(item.description, `${overlay.id}: ${item.id}`)
				}
			}
		}
	}
})

test('the simple profile has no relocations key, and repeats what the full one retires', () => {
	// Any relocations object, even an empty one, makes the library drop the
	// captions: they have no route, href, action or children.
	assert.equal(Object.hasOwn(SIMPLE, 'relocations'), false)
	for (const id of FULL.removals) {
		assert.ok(SIMPLE.removals.includes(id), `removal not repeated: ${id}`)
	}
	for (const id of FULL.settingsSection) {
		assert.ok(SIMPLE.settingsSection.includes(id), `settings: ${id}`)
	}
})

test('the simple menu is flat, in order, and under three captions', () => {
	const simple = build(SIMPLE, 'admin')
	for (const item of simple.menu) {
		assert.ok(
			!Array.isArray(item.children) || item.children.length === 0,
			`${item.id} still has children`,
		)
	}
	const main = simple.menu
		.filter((item) => sectionOf(item) === 'main')
		.sort((a, b) => a.order - b.order)
	assert.deepEqual(
		main.map((item) => item.id),
		SIMPLE_MAIN_ORDER,
	)
	assert.deepEqual(
		main.filter(isCaption).map((item) => item.label),
		['Home', 'Teaching', 'Learners'],
	)
	const orders = main.map((item) => item.order)
	assert.equal(new Set(orders).size, orders.length, 'two entries share an order')
})

test('each role sees its own menu', () => {
	for (const [role, expected] of Object.entries(SIMPLE_MAIN_BY_ROLE)) {
		const ids = mainOf(build(SIMPLE, role))
			.sort((a, b) => a.order - b.order)
			.map((item) => item.id)
		assert.deepEqual(ids, expected, role)
	}
})

test('no role gets more than ten main entries, an administrator twelve, a flag two more', () => {
	for (const state of [NEVER, ...CHOSEN]) {
		for (const role of ROLES) {
			const ceiling = role === 'admin' ? 12 : 10
			const where = `${role} in ${state.chosen ?? 'no segment chosen'}`
			const plain = mainOf(build(SIMPLE, role, state)).length
			assert.ok(plain <= ceiling, `${where}: ${plain}`)
			assert.ok(
				plain >= 4,
				`${where}: only ${plain}, the menu is nearly empty`,
			)
			for (const flags of [{ counsellor: true }, { manager: true }]) {
				const flagged = mainOf(build(SIMPLE, role, state, flags)).length
				assert.ok(
					flagged <= ceiling + 2,
					`${where} ${JSON.stringify(flags)}`,
				)
			}
		}
	}
})

test('every simple entry has a label, a registered icon and a page', () => {
	const simple = build(SIMPLE, 'admin')
	const pageIds = new Set(simple.pages.map((page) => page.id))
	const icons = readText('src/icons.js')
	const registered = (name) => new RegExp(`^\\s*${name},$`, 'm').test(icons)
	for (const item of simple.menu) {
		assert.ok(item.label, `${item.id} has no label`)
		if (isCaption(item)) {
			assert.equal(item.route, undefined)
			continue
		}
		assert.ok(item.icon && registered(item.icon), `${item.id}: ${item.icon}`)
		assert.ok(
			item.href || pageIds.has(item.route),
			`${item.id} opens no page: ${item.route}`,
		)
	}
	for (const overlay of LINK_OVERLAYS) {
		for (const items of Object.values(overlay.configAppend)) {
			for (const item of items) {
				assert.ok(registered(item.icon), `${overlay.id}: ${item.icon}`)
			}
		}
	}
})

test('a gate in the simple file names only roles the resolver can emit', () => {
	const resolver = readText('lib/Service/DashboardRoleService.php')
	const known = new Set(['admin', 'learner'])
	const block = resolver.match(/GROUP_BACKED_ROLES\s*=\s*\[([\s\S]*?)\];/)
	assert.ok(block, 'GROUP_BACKED_ROLES not found')
	for (const match of block[1].matchAll(/'([a-z-]+)'\s*=>/g)) {
		known.add(match[1])
	}
	assert.deepEqual([...known].sort(), [...ROLES].sort())
	for (const item of SIMPLE.menu) {
		const gate = item.visibleIf?.['user.primaryRole']
		for (const role of [...(gate?.in || []), ...(gate?.notIn || [])]) {
			assert.ok(known.has(role), `${item.id} names unknown role ${role}`)
		}
	}
})

test('the simple menu never shows a role a page the full menu does not show it', () => {
	for (const state of [NEVER, ...CHOSEN]) {
		for (const role of ROLES) {
			for (const flags of [{}, { counsellor: true }, { manager: true }]) {
				const offered = new Set(
					shown(build(FULL, role, state, flags))
						.map((item) => item.route)
						.filter(Boolean),
				)
				for (const item of shown(build(SIMPLE, role, state, flags))) {
					if (!item.route || NEW_DOORS.includes(item.route)) {
						continue
					}
					assert.ok(
						offered.has(item.route),
						`${item.id} shows ${item.route} to ${role} (${state.chosen}), the full menu does not`,
					)
				}
			}
		}
	}
})

test('the new door opens for the roles that already had the pages next to it', () => {
	// Cohorts had no menu entry. It borrows the gate of Learning, the entry
	// its lists sit under in the full menu.
	const entry = SIMPLE.menu.find((item) => item.id === 'GroupsSimple')
	assert.deepEqual(entry.visibleIf['user.primaryRole'].in, [
		'instructor',
		'coordinator',
		'administration-manager',
		'admin',
	])
})

test('no entry and no link opens a page that needs something in its address', () => {
	// A route with a parameter cannot be opened from a menu: vue-router
	// refuses to build the address and the click does nothing. Submissions
	// (`/assignments/:assignmentId/submissions`) is why this test exists.
	const simple = build(SIMPLE, 'admin')
	const routeOf = (id) => simple.pages.find((page) => page.id === id)?.route
	const targets = simple.menu
		.filter((item) => item.route)
		.map((item) => item.route)
	for (const overlay of LINK_OVERLAYS) {
		for (const items of Object.values(overlay.configAppend)) {
			targets.push(...items.map((item) => item.route))
		}
	}
	assert.ok(targets.length > 40)
	for (const target of targets) {
		assert.ok(
			typeof routeOf(target) === 'string' && !routeOf(target).includes(':'),
			`${target} opens ${routeOf(target)}`,
		)
	}
})

test('nothing the full menu offers a role is lost without being named', () => {
	for (const role of ROLES) {
		assert.deepEqual(
			unlinkedFor(role),
			[...KNOWN_UNLINKED[role]].sort(),
			`unlinked entries for ${role} differ from KNOWN_UNLINKED`,
		)
	}
})

test('the lists a custom dashboard is credited with are in its component', () => {
	const full = build(FULL, 'admin')
	for (const [pageId, { file, pages }] of Object.entries(COMPONENT_LINKS)) {
		const source = readText(file)
		for (const linked of pages) {
			const page = full.pages.find((candidate) => candidate.id === linked)
			assert.ok(page, `${pageId} is credited with no such page: ${linked}`)
			assert.ok(
				source.includes(`indexRoute="${page.route}"`),
				`${file} does not link ${page.route}`,
			)
		}
	}
})

test('every string of the simple profile has a Dutch one', () => {
	// en.json is a partial catalogue here: an English source string is its
	// own fallback. Dutch is the strict locale (check:l10n), so Dutch is what
	// a new string must have.
	const dutch = readJson('l10n/nl.json').translations
	const strings = []
	for (const item of SIMPLE.menu) {
		if (item.label) {
			strings.push(item.label)
		}
	}
	for (const overlay of LINK_OVERLAYS) {
		for (const items of Object.values(overlay.configAppend)) {
			for (const item of items) {
				strings.push(item.label)
				if (item.description) {
					strings.push(item.description)
				}
			}
		}
	}
	strings.push(TODAY.page.title, TODAY.config.description)
	for (const action of TODAY.config.headerActions) {
		strings.push(action.label)
	}
	for (const widget of TODAY.config.widgets) {
		const content = widget.content
		strings.push(widget.title)
		for (const key of ['label', 'kicker', 'title', 'reason', 'emptyText']) {
			if (content[key]) {
				strings.push(content[key])
			}
		}
		for (const item of [
			...(content.actions || []),
			...(content.entries || []),
		]) {
			strings.push(item.label)
			if (item.description) {
				strings.push(item.description)
			}
		}
	}
	const section = readText('src/views/settings/MenuStructureSection.vue')
	for (const match of section.matchAll(/'learniq',\s*'((?:[^'\\]|\\.)+)'/g)) {
		strings.push(match[1])
	}
	assert.ok(strings.length > 30)
	for (const text of strings) {
		assert.ok(
			typeof dutch[text] === 'string' && dutch[text].trim() !== '',
			`nl.json misses: ${text}`,
		)
	}
})

/**
 * The schema a widget source names, from the register.
 *
 * @param {string} name The schema key or slug, as a page or widget writes it.
 * @return {object} The schema.
 */
function schemaOf(name) {
	const schemas = readJson('lib/Settings/learniq_register.json').components.schemas
	const wanted = name.toLowerCase()
	const found = Object.entries(schemas).find(
		([key, schema]) =>
			key.toLowerCase() === wanted
			|| String(schema.slug).toLowerCase() === wanted,
	)
	assert.ok(found, `no schema ${name}`)
	return found[1]
}

/**
 * A filter as an address carries it: `{ a: { gte: x } }` becomes `a[gte]=x`.
 *
 * @param {object} filter A widget filter.
 * @return {object} The flat map of strings.
 */
function asQuery(filter) {
	const query = {}
	for (const [field, value] of Object.entries(filter)) {
		if (value !== null && typeof value === 'object') {
			for (const [op, operand] of Object.entries(value)) {
				query[`${field}[${op}]`] = String(operand)
			}
		} else {
			query[field] = String(value)
		}
	}
	return query
}

/** Every widget of the Today dashboard that counts records, with its filter and where it leads. */
function todayCounts() {
	const counts = []
	for (const widget of TODAY.config.widgets) {
		if (widget.type === 'stat') {
			counts.push({
				id: widget.id,
				source: widget.content.source,
				routes: [widget.content.route],
			})
		}
		if (widget.type === 'banner') {
			counts.push({
				id: widget.id,
				source: widget.content.visibleWhen.source,
				routes: widget.content.actions.map((action) => action.route),
			})
		}
	}
	return counts
}

test('the page at / is the Today dashboard for the teaching roles, in the simple structure only', () => {
	const manifestPage = build(FULL, 'admin').pages.find(
		(page) => page.id === 'Dashboard',
	)
	assert.equal(manifestPage.type, 'custom')
	assert.deepEqual(TODAY.when, { 'user.primaryRole': { in: TODAY_ROLES } })
	for (const role of ROLES) {
		const page = build(SIMPLE, role).pages.find(
			(candidate) => candidate.id === 'Dashboard',
		)
		const full = build(FULL, role).pages.find(
			(candidate) => candidate.id === 'Dashboard',
		)
		assert.deepEqual(
			full,
			manifestPage,
			`the full structure changed for ${role}`,
		)
		assert.equal(page.route, '/')
		if (TODAY_ROLES.includes(role)) {
			assert.equal(page.type, 'dashboard', role)
			assert.equal(page.title, 'Today')
			assert.equal(Object.hasOwn(page, 'component'), false)
			assert.deepEqual(page.config, TODAY.config)
		} else {
			assert.deepEqual(page, manifestPage, `${role} lost the role dashboard`)
		}
	}
})

test('an overlay with a condition is skipped when nothing can judge the condition', () => {
	const built = buildProfiledManifest(
		buildManifest,
		structuredClone(BASE),
		structuredClone(FRAGMENTS),
		structuredClone(SIMPLE),
	)
	assert.equal(built.pages.find((page) => page.id === 'Dashboard').type, 'custom')
})

test('the Today dashboard holds library widgets only, each laid out once, none overlapping', () => {
	const catalog = readText(
		'node_modules/@conduction/nextcloud-vue/src/utils/libraryWidgetKeys.js',
	)
	const ids = TODAY.config.widgets.map((widget) => widget.id)
	assert.equal(new Set(ids).size, ids.length)
	for (const widget of TODAY.config.widgets) {
		assert.notEqual(widget.type, 'custom', `${widget.id} is a custom widget`)
		assert.ok(
			catalog.includes(`'${widget.type}'`),
			`${widget.id}: ${widget.type}`,
		)
	}
	assert.deepEqual(
		TODAY.config.layout.map((item) => item.widgetId).sort(),
		[...ids].sort(),
	)
	const taken = new Set()
	for (const item of TODAY.config.layout) {
		assert.ok(item.gridX + item.gridWidth <= 12, `${item.widgetId} is too wide`)
		for (let x = item.gridX; x < item.gridX + item.gridWidth; x++) {
			for (let y = item.gridY; y < item.gridY + item.gridHeight; y++) {
				assert.ok(
					!taken.has(`${x},${y}`),
					`${item.widgetId} overlaps at ${x},${y}`,
				)
				taken.add(`${x},${y}`)
			}
		}
	}
})

test('the Other dashboards card has one frame and one title', () => {
	// The card grid wraps itself in a titled card. Left alone it reads
	// "Other dashboards / Actions / Explore / Actions".
	const grid = TODAY.config.widgets.find(
		(widget) => widget.type === 'nav-card-grid',
	)
	const placed = TODAY.config.layout.find((item) => item.widgetId === grid.id)
	assert.equal(grid.content.title, grid.title)
	assert.equal(placed.showTitle, false)
	assert.equal(placed.showActions, false)
})

test('a tile label is at most eighteen characters', () => {
	const tiles = TODAY.config.widgets.filter((widget) => widget.type === 'stat')
	assert.equal(tiles.length, 4)
	for (const tile of tiles) {
		assert.ok(tile.content.label.length <= 18, tile.content.label)
		assert.equal(tile.title, tile.content.label)
	}
})

test('every number on Today uses the filter of the list it opens', () => {
	const pages = build(FULL, 'admin').pages
	const counts = todayCounts()
	assert.equal(counts.length, 5)
	for (const { id, source, routes } of counts) {
		const properties = schemaOf(source.schema).properties
		for (const field of Object.keys(source.filter)) {
			assert.ok(Object.hasOwn(properties, field), `${id}: no field ${field}`)
		}
		assert.ok(routes.length > 0, `${id} opens nothing`)
		for (const route of routes) {
			const page = pages.find((candidate) => candidate.id === routeName(route))
			assert.ok(page, `${id} opens no page`)
			assert.equal(page.type, 'index', `${id} does not open a list`)
			assert.ok(!page.route.includes(':'), `${id}: ${page.route}`)
			assert.equal(page.config.register, source.register, id)
			assert.equal(
				schemaOf(page.config.schema).slug,
				schemaOf(source.schema).slug,
				`${id} counts another schema than its list shows`,
			)
			// A bare string is read as a PATH by the tile, not as a page name:
			// `SessionsToday` opened `/apps/learniq/SessionsToday`.
			assert.equal(typeof route, 'object', `${id}: route must be { name }`)
			assert.equal(typeof route.name, 'string', id)
			if (route.query === undefined) {
				// No filter in the address: the list must carry the same one itself.
				// The same filter, whichever way each side writes an operator:
				// a tile nests it (the count flattens it itself), a list must
				// write it flat (see the address test below).
				assert.deepEqual(
					asQuery(page.config.filter),
					asQuery(source.filter),
					id,
				)
			} else {
				assert.equal(
					page.config.filter,
					undefined,
					`${id}: the list adds a filter`,
				)
				assert.deepEqual(route.query, asQuery(source.filter), id)
			}
		}
	}
})

test('the First today card counts with a flat filter', () => {
	// The library's count source writes a nested operator as JSON, and
	// OpenRegister answers that with a 500. A card's filter stays flat.
	const card = TODAY.config.widgets.find((widget) => widget.type === 'banner')
	for (const value of Object.values(card.content.visibleWhen.source.filter)) {
		assert.notEqual(typeof value, 'object')
	}
	assert.deepEqual(
		[card.content.visibleWhen.op, card.content.visibleWhen.value],
		['gt', 0],
	)
	const lifecycle = schemaOf('attendance-flag').properties.lifecycle.enum
	assert.ok(lifecycle.includes(card.content.visibleWhen.source.filter.lifecycle))
})

test('the First today card is not collapsed before its condition is read', () => {
	// nextcloud-vue CnDashboardPage.isCollapsedWidget: a banner whose text is
	// empty gives up its cell BEFORE `visibleWhen` is looked at. Since 2.65.0
	// an attention card's `title` counts as its text (widgetDisplayConfig);
	// 2.60.0 read `text` alone. This mirrors that rule (it lives in a .vue
	// file node cannot import) and pins the source text, so the mirror fails
	// when the library changes the rule.
	const page = readText(
		'node_modules/@conduction/nextcloud-vue/src/components/CnDashboardPage/CnDashboardPage.vue',
	)
	assert.match(
		page,
		/if \(this\.isBannerDef\(def\) && text === ''\) \{\s*return true/,
		'the library changed its collapse rule; read it again',
	)
	assert.match(
		page,
		/const title = layout === 'attention' \? \(content\.title \|\| props\.title \|\| ''\) : ''/,
	)
	assert.match(page, /text: content\.text \|\| props\.text \|\| title \|\| ''/)
	for (const widget of TODAY.config.widgets) {
		if (widget.type !== 'banner') {
			continue
		}
		const layout = widget.content.layout || widget.props?.layout || ''
		const title =
			layout === 'attention'
				? widget.content.title || widget.props?.title || ''
				: ''
		const text = widget.content.text || widget.props?.text || title || ''
		assert.notEqual(text, '', `${widget.id} has no text and would never show`)
		assert.equal(widget.content.text, widget.content.title)
	}
})

/**
 * The few browser globals the library's utility modules touch at module load.
 *
 * @return {void}
 */
function browserGlobals() {
	const memory = () => {
		const map = new Map()
		return {
			getItem: (key) => map.get(key) ?? null,
			setItem: (key, value) => map.set(key, String(value)),
			removeItem: (key) => map.delete(key),
		}
	}
	const head = { getAttribute: () => null, dataset: {} }
	globalThis.window ??= globalThis
	globalThis.localStorage ??= memory()
	globalThis.sessionStorage ??= memory()
	globalThis.location ??= { pathname: '/index.php/apps/learniq/' }
	globalThis.addEventListener ??= () => {}
	globalThis.document ??= {
		head,
		documentElement: { getAttribute: () => 'en', dataset: {} },
		getElementsByTagName: () => [head],
		querySelector: () => null,
		getElementById: () => null,
		addEventListener: () => {},
	}
}

/**
 * The library's own visibleWhen functions.
 *
 * @return {Promise<object>} The module.
 */
async function libraryVisibleWhen() {
	browserGlobals()
	return import('../../node_modules/@conduction/nextcloud-vue/src/utils/visibleWhen.js')
}

test('the First today card shows for open flags and hides for none, by the library own rule', async () => {
	const { compareVisibleWhen, readVisibleWhenValue } = await libraryVisibleWhen()
	const card = TODAY.config.widgets.find((widget) => widget.type === 'banner')
	const condition = card.content.visibleWhen
	const realFetch = globalThis.fetch
	const asked = []
	const answerWith = (total) => {
		globalThis.fetch = async (url) => {
			asked.push(String(url))
			return {
				ok: true,
				status: 200,
				json: async () => ({ results: total > 0 ? [1] : [], total }),
			}
		}
	}
	try {
		answerWith(3)
		const open = await readVisibleWhenValue(condition, {})
		assert.equal(open, 3)
		assert.equal(compareVisibleWhen(open, condition.op, condition.value), true)

		answerWith(0)
		const none = await readVisibleWhenValue(condition, {})
		assert.equal(none, 0)
		assert.equal(compareVisibleWhen(none, condition.op, condition.value), false)
	} finally {
		globalThis.fetch = realFetch
	}
	// The address the count is read from: the flat filter, as written.
	assert.equal(asked.length, 2)
	assert.ok(
		asked[0].endsWith(
			'/apps/openregister/api/objects/learniq/attendance-flag?lifecycle=open&_limit=1',
		),
		asked[0],
	)
})

test('no address a Today number asks or opens carries an operator as JSON', async () => {
	// nextcloud-vue 2.60.0 writes a NESTED operator in a list filter as JSON
	// (`startsAt={"gte":…}`), and OpenRegister answers that with a 500: the
	// list then reads "No items found" under a tile that says 1 (seen live,
	// 5 October 2026, on /sessions/week). A flat key (`startsAt[gte]`) goes out
	// as written. So every list a Today number opens is serialised here with
	// the library's own functions, and so is every count.
	browserGlobals()
	const utils = '../../node_modules/@conduction/nextcloud-vue/src/utils/'
	const { resolveFilterMap, resolveQueryFilters } = await import(
		`${utils}routeFilters.js`
	)
	const { buildQueryString } = await import(`${utils}headers.js`)
	const { flattenAggFilter } = await import(`${utils}fetchAggregate.js`)
	const { resolveFilterTokens } = await import(`${utils}resolveFilterTokens.js`)
	const pages = build(FULL, 'admin').pages
	const hasJson = (text) => /[{}]|%7B|%7D/i.test(text)

	// The control: since nextcloud-vue 2.65.0 the library writes a nested
	// operator flat (`a[gte]=…`), so the sweep below guards against that
	// fix regressing rather than against the 2.60.0 behaviour.
	const nested = buildQueryString(
		resolveFilterMap({ a: { gte: '@today' } }, {}, {}),
	)
	assert.ok(!hasJson(nested), `the library writes JSON again: ${nested}`)
	assert.ok(decodeURIComponent(nested).includes('a[gte]'), nested)

	let lists = 0
	for (const { id, source, routes } of todayCounts()) {
		for (const route of routes) {
			const page = pages.find((candidate) => candidate.id === routeName(route))
			const address = buildQueryString({
				...resolveQueryFilters(route.query || {}, {}),
				...resolveFilterMap(page.config.filter || {}, {}, {}),
			})
			assert.ok(!hasJson(address), `${id} opens ${page.route}${address}`)
			// And it still filters on every field the count filters on.
			for (const field of Object.keys(source.filter)) {
				assert.ok(
					decodeURIComponent(address).includes(`${field}`),
					`${id}: ${field} is not in ${address}`,
				)
			}
			lists++
		}
		const isCard =
			TODAY.config.widgets.find((w) => w.id === id).type === 'banner'
		if (isCard) {
			const asked = buildQueryString(resolveFilterTokens(source.filter, {}))
			assert.ok(!hasJson(asked), `${id} asks ${asked}`)
		} else {
			const params = {}
			flattenAggFilter(params, source.filter, {})
			for (const [key, value] of Object.entries(params)) {
				assert.notEqual(typeof value, 'object', `${id}: ${key}`)
				assert.match(
					key,
					/^filter\[[A-Za-z_]+\](\[[a-z]+\])?$/,
					`${id}: ${key}`,
				)
			}
		}
	}
	assert.equal(lists, 5)
})

test('every widget type on Today is one the installed library build registers', () => {
	// The dashboard page resolves a type in this order: the app's registry,
	// the library's dashboard catalog (registerDashboardWidget), then
	// BUILT_IN_WIDGETS. A type in none of them renders "Widget not available".
	// The catalog is read from the BUILD the app bundles (dist/esm), not from
	// the source tree or a list of names, and the registration module must be
	// on the package's sideEffects list or a bundler may drop it.
	const lib = 'node_modules/@conduction/nextcloud-vue/'
	const registered = new Set()
	const walk = (dir) => {
		for (const entry of readdirSync(new URL(dir, root), {
			withFileTypes: true,
		})) {
			if (entry.isDirectory()) {
				walk(`${dir}${entry.name}/`)
			} else if (entry.name.endsWith('.js')) {
				const source = readText(dir + entry.name)
				for (const match of source.matchAll(
					/registerDashboardWidget\(\s*['"]([a-z-]+)['"]/g,
				)) {
					registered.add(match[1])
				}
			}
		}
	}
	walk(`${lib}dist/esm/components/`)
	assert.ok(registered.size > 30, `only ${registered.size} catalog widgets found`)

	const builtInSource = readText(
		`${lib}dist/esm/components/CnWidgetGrid/builtInWidgets.js`,
	)
	const builtIn = builtInSource.match(/const BUILT_IN_WIDGETS = \{([\s\S]*?)\n\}/)
	assert.ok(builtIn, 'BUILT_IN_WIDGETS not found in the build')
	const builtInKeys = new Set(
		[...builtIn[1].matchAll(/^\s*['"]?([a-z-]+)['"]?\s*:/gm)].map((m) => m[1]),
	)

	const appRegistry = readText('src/registry.js')
	for (const widget of TODAY.config.widgets) {
		assert.ok(
			!new RegExp(`^\\s*['"]?${widget.type}['"]?\\s*:`, 'm').test(appRegistry),
			`${widget.type} is overridden by the app registry`,
		)
		assert.ok(
			registered.has(widget.type) || builtInKeys.has(widget.type),
			`${widget.id}: no renderer for type ${widget.type} in the installed build`,
		)
	}
	// The two that only exist since 2.60.0, by name, in the catalog itself.
	assert.ok(registered.has('week-strip'))
	assert.ok(registered.has('header'))
	const greeting = readText(
		`${lib}dist/esm/components/CnHeaderWidget/CnHeaderWidget.vue2.js`,
	)
	assert.ok(greeting.includes('greetingText'), 'this build has no greeting header')

	const pkg = readJson(`${lib}package.json`)
	assert.ok(
		pkg.sideEffects.includes('**/CnWidgetGrid/registerDashboardWidgets.js'),
	)
	const [major, minor] = pkg.version.split('.').map(Number)
	assert.ok(
		major === 2 && minor >= 60,
		`installed nextcloud-vue is ${pkg.version}`,
	)
	const range = readJson('package.json').dependencies['@conduction/nextcloud-vue']
	const floor = range.match(/^\^2\.(\d+)\./)
	assert.ok(
		floor && Number(floor[1]) >= 60,
		`the declared range ${range} allows a build without these widgets`,
	)
	assert.match(
		readText('src/main.js'),
		/^registerBuiltinDashboardWidgets\(\)$/m,
		'main.js no longer registers the catalog',
	)
})

test('the week strip reads real fields and opens a lesson', () => {
	const strip = TODAY.config.widgets.find((widget) => widget.type === 'week-strip')
	const properties = schemaOf(strip.content.source.schema).properties
	for (const field of [
		strip.content.dateField,
		strip.content.titleField,
		...strip.content.metaFields,
		...Object.keys(strip.content.source.filter),
	]) {
		assert.ok(Object.hasOwn(properties, field), `no field ${field}`)
	}
	const detail = build(FULL, 'admin').pages.find(
		(page) => page.id === strip.content.itemRoute,
	)
	assert.equal(detail.type, 'detail')
	assert.ok(detail.route.endsWith('/:id'))
	// A lesson that has been is not late. The rule can never be true.
	assert.ok(strip.content.lateWhen.value < -1000)
})

test('Today links no page the full menu keeps from a role it is for', () => {
	for (const role of TODAY_ROLES) {
		const offered = reachable(build(FULL, role))
		const simple = build(SIMPLE, role)
		const page = simple.pages.find((candidate) => candidate.id === 'Dashboard')
		const links = [
			...dashboardLinks(page),
			...page.config.headerActions.map((action) => action.target),
		]
		for (const widget of page.config.widgets) {
			for (const entry of widget.content?.entries || []) {
				if (passesContextPredicates(entry.visibleIf, simple.runtime)) {
					links.push(entry.route)
				}
			}
		}
		assert.ok(links.length >= 7, `${role}: ${links.length} links`)
		for (const link of links) {
			assert.ok(offered.has(link), `${role} gets a link to ${link}`)
		}
	}
})

test('the Today page is a valid page of the installed manifest schema', () => {
	const require = createRequire(import.meta.url)
	const Ajv = require('ajv/dist/2020').default || require('ajv/dist/2020')
	const schema = readJson(
		'node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json',
	)
	const validate = new Ajv({ allErrors: true, strict: false }).compile(schema)
	const page = {
		id: 'Dashboard',
		route: '/',
		type: TODAY.page.type,
		title: TODAY.page.title,
		config: TODAY.config,
	}
	// The control: the manifest itself is valid, so a failure below is the page.
	assert.equal(validate(structuredClone(BASE)), true)
	const withToday = { ...structuredClone(BASE), pages: [page], menu: [] }
	assert.equal(
		validate(withToday),
		true,
		JSON.stringify(validate.errors?.slice(0, 5)),
	)
})

test('only the word full selects the full structure', () => {
	for (const raw of ['', undefined, null, 'simple', 'Full', 'ful', 1, true]) {
		assert.equal(resolveStructureProfile(raw), STRUCTURE_SIMPLE)
	}
	assert.equal(resolveStructureProfile('full'), STRUCTURE_FULL)
	assert.equal(STRUCTURE_SETTING, 'menu_structure')
})

test('an overlay leaves the page it was given untouched', () => {
	const page = { id: 'P', config: { headerActions: [{ id: 'a' }] } }
	const out = applyPageOverlay(page, {
		id: 'P',
		configAppend: { headerActions: [{ id: 'b' }] },
	})
	assert.deepEqual(
		out.config.headerActions.map((item) => item.id),
		['a', 'b'],
	)
	assert.deepEqual(page.config.headerActions, [{ id: 'a' }])
})

test('the admin section reads the stored structure and refuses a save that stored nothing', async () => {
	const calls = []
	const http = {
		get: async (url) => {
			calls.push(['get', url])
			return { data: { menu_structure: 'full' } }
		},
		put: async (url, body) => {
			calls.push(['put', url, body])
			return {
				data: {
					success: true,
					config: { menu_structure: body.menu_structure },
				},
			}
		},
	}
	assert.equal(await loadMenuStructure(http, '/s'), 'full')
	assert.equal(
		await loadMenuStructure({ get: async () => ({ data: {} }) }, '/s'),
		'simple',
	)
	assert.equal(await saveMenuStructure(http, '/s', 'full'), 'full')
	assert.deepEqual(calls[1], ['put', '/s', { menu_structure: 'full' }])

	// The endpoint answers success for a key it does not know. That must not
	// read as a save.
	const deaf = { put: async () => ({ data: { success: true, config: {} } }) }
	await assert.rejects(() => saveMenuStructure(deaf, '/s', 'full'))
})

// The navigation of the simple profile: a brand block and one primary button
// (LqDashboard, AppZijbalk). The school's name and logo are the instance's
// theming capabilities, read at boot; the app names no school.
const THEMING = { name: 'Gemeente Zuiddrecht', logo: '/apps/theming/image/logo?v=1' }

function buildNav(
	layout,
	role,
	theming = THEMING,
	passes = passesContextPredicates,
) {
	const base = structuredClone(BASE)
	base.runtime = { user: { primaryRole: role }, workspace: {} }
	return buildProfiledManifest(
		buildManifest,
		base,
		structuredClone(FRAGMENTS),
		structuredClone(layout),
		passes,
		{ theming },
	).nav
}

test('the simple navigation opens with the brand block, named after the instance', () => {
	assert.deepEqual(buildNav(SIMPLE, 'instructor').brand, {
		name: 'learniq',
		caption: 'Gemeente Zuiddrecht',
		logo: '/apps/theming/image/logo?v=1',
	})
	assert.ok(!/Zuiddrecht|Gemeente|school/i.test(JSON.stringify(SIMPLE.nav.brand)))
})

test('a theming value the instance does not answer stays empty, never a guess', () => {
	assert.deepEqual(buildNav(SIMPLE, 'instructor', null).brand, {
		name: 'learniq',
		caption: '',
		logo: '',
	})
	assert.equal(buildNav(SIMPLE, 'instructor', { name: 'X' }).brand.logo, '')
})

test('the primary button opens the register of today, for the Today roles only', () => {
	for (const role of TODAY_ROLES) {
		const action = buildNav(SIMPLE, role).primaryAction
		assert.deepEqual(
			action,
			{
				label: 'Fill in attendance',
				icon: 'ClipboardCheckOutline',
				route: 'RollCall',
			},
			role,
		)
		const page = build(SIMPLE, role).pages.find(
			(candidate) => candidate.id === action.route,
		)
		assert.ok(page && !page.route.includes(':'), role)
	}
	for (const role of ROLES.filter((role) => !TODAY_ROLES.includes(role))) {
		assert.equal(buildNav(SIMPLE, role).primaryAction, undefined, role)
	}
	// Nothing to judge the gate with: no button, the safe side.
	assert.equal(
		buildNav(SIMPLE, 'instructor', THEMING, null).primaryAction,
		undefined,
	)
	assert.ok(readText('src/icons.js').includes('ClipboardCheckOutline'))
	assert.equal(
		readJson('l10n/nl.json').translations['Fill in attendance'],
		'Aanwezigheid invullen',
	)
})

test('the full profile has no brand and no button, and main.js reads the theming capabilities', () => {
	assert.equal(FULL.nav, undefined)
	assert.equal(build(FULL, 'instructor').nav, BASE.nav)
	assert.ok(readText('src/main.js').includes('navTheming(getCapabilities())'))
})

test('Today sits in two columns as the board draws it', () => {
	const placed = (id) => TODAY.config.layout.find((item) => item.widgetId === id)
	const main = ['today-lessons', 'today-week', 'today-signals']
	for (const id of main) {
		assert.equal(placed(id).gridX, 0, id)
		assert.equal(placed(id).gridWidth, 8, id)
	}
	assert.equal(placed(main[0]).gridY, 4)
	for (let at = 1; at < main.length; at++) {
		assert.ok(placed(main[at]).gridY > placed(main[at - 1]).gridY, main[at])
	}
	const tiles = TODAY.config.widgets.filter((widget) => widget.type === 'stat')
	for (const tile of tiles) {
		const item = placed(tile.id)
		// One under the other, the full side column wide: at two columns the
		// stat card cut its label to "Assignm" (seen live, 6 October 2026).
		assert.equal(item.gridX, 8, tile.id)
		assert.equal(item.gridWidth, 4, tile.id)
	}
	const tileRows = tiles.map((tile) => placed(tile.id).gridY)
	assert.deepEqual(tileRows, [4, 6, 8, 10])
	const grid = placed('today-dashboards')
	assert.equal(grid.gridWidth, 12)
	assert.ok(
		grid.gridY
			>= Math.max(
				...TODAY.config.layout
					.filter((item) => item.id !== grid.id)
					.map((item) => item.gridY + item.gridHeight),
			),
	)
})

test('the two lists on Today open what they show', () => {
	const pages = build(SIMPLE, 'instructor').pages
	const pageOf = (name) => pages.find((candidate) => candidate.id === name)
	const lists = TODAY.config.widgets.filter(
		(widget) => widget.type === 'object-table',
	)
	assert.equal(lists.length, 2)
	for (const list of lists) {
		const { source, columns, rowRoute, viewAllRoute } = list.content
		const schema = schemaOf(source.schema)
		for (const field of [
			...Object.keys(source.filter),
			...columns.map((column) => column.key),
		]) {
			assert.ok(
				Object.hasOwn(schema.properties, field),
				`${list.id}: no field ${field}`,
			)
		}
		const detail = pageOf(rowRoute)
		assert.equal(detail.type, 'detail', list.id)
		assert.ok(detail.route.endsWith('/:id'), list.id)
		assert.equal(schemaOf(detail.config.schema).slug, schema.slug, list.id)
		const index = pageOf(viewAllRoute.name)
		assert.equal(index.type, 'index', list.id)
		assert.ok(!index.route.includes(':'), list.id)
		assert.equal(schemaOf(index.config.schema).slug, schema.slug, list.id)
		// The same filter: in the address, or carried by the list itself.
		assert.deepEqual(
			asQuery({
				...(index.config.filter || {}),
				...(viewAllRoute.query || {}),
			}),
			asQuery(source.filter),
			list.id,
		)
		assert.equal(list.content.hideHeader, true, list.id)
		assert.ok(list.content.emptyText, list.id)
	}
	// The flags list is the attention card's own count, opened the same way.
	const flags = lists.find((list) => list.id === 'today-signals')
	const card = TODAY.config.widgets.find((widget) => widget.type === 'banner')
	assert.deepEqual(
		flags.content.source.filter,
		card.content.visibleWhen.source.filter,
	)
	assert.equal(flags.content.viewAllRoute.name, card.content.actions[0].route.name)
})

test('the brand block shows the emblem, not the whole wordmark, when the set ships one', () => {
	// The board's brand block holds the shield only; the theming logo is the
	// full wordmark, which then stood twice beside the app name (seen live on
	// decidiq, 6 October 2026).
	assert.equal(SIMPLE.nav.brand.logo, '@theming.emblem|@theming.logo')
	const brand = buildNav(
		SIMPLE,
		'instructor',
		navTheming({
			theming: THEMING,
			nldesign: { logos: { emblem: '/apps/thematiq/img/emblem.svg' } },
		}),
	).brand
	assert.equal(brand.logo, '/apps/thematiq/img/emblem.svg')
	assert.equal(brand.caption, 'Gemeente Zuiddrecht')
})

test('the brand block falls back to the theming logo without an emblem, and is empty without either', () => {
	const fallback = buildNav(
		SIMPLE,
		'instructor',
		navTheming({ theming: THEMING, nldesign: { logos: {} } }),
	).brand
	assert.equal(fallback.logo, '/apps/theming/image/logo?v=1')
	const neither = buildNav(
		SIMPLE,
		'instructor',
		navTheming({ theming: { name: 'X' } }),
	).brand
	assert.equal(neither.logo, '')
})

test('navTheming reads the emblem from thematiq, and an empty string when there is none', () => {
	assert.equal(
		navTheming({ nldesign: { logos: { emblem: '/e.svg' } } }).emblem,
		'/e.svg',
	)
	assert.equal(navTheming({ theming: THEMING }).emblem, '')
	assert.equal(navTheming({ nldesign: { logos: { emblem: 42 } } }).emblem, '')
	assert.deepEqual(navTheming(null), { emblem: '' })
	assert.equal(navTheming({ theming: THEMING }).name, 'Gemeente Zuiddrecht')
})
