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
		),
	)
}

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
		'DashboardAdmin',
		'DashboardDirectorMenu',
		'DashboardIbMenu',
		'DashboardMentorMenu',
		'DashboardStudent',
		'DashboardTeacher',
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
		'DashboardDirectorMenu',
		'DashboardStudent',
		'DashboardTeacher',
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
		'DashboardIbMenu',
		'DashboardStudent',
		'DashboardTeacher',
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
		'DashboardMentorMenu',
		'DashboardStudent',
		'DashboardTeacher',
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
	'MarkingSimple',
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
		'MarkingSimple',
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
		'MarkingSimple',
		'LearnersSimple',
		'AttendanceSimple',
		'GroupProgress',
		'CareSimple',
		'GroupCompliance',
	],
}

/** The two pages that gain a menu entry they never had. Everything else mirrors the full menu. */
const NEW_DOORS = ['Cohorts', 'Submissions']

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
		[100, 4, 4],
	)
	assert.equal(plain.pages.length, 350)
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
	const overlaid = new Set(SIMPLE.pages.map((overlay) => overlay.id))
	for (const [index, page] of full.pages.entries()) {
		const twin = simple.pages[index]
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
	for (const overlay of SIMPLE.pages) {
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
	for (const overlay of SIMPLE.pages) {
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

test('the two new doors open for the roles that already had the pages next to them', () => {
	// Cohorts and Submissions had no menu entry. They borrow the gate of the
	// entry they sit beside, or a narrower one.
	const allowed = {
		GroupsSimple: [
			'instructor',
			'coordinator',
			'administration-manager',
			'admin',
		],
		MarkingSimple: [
			'instructor',
			'coordinator',
			'administration-manager',
			'admin',
		],
	}
	for (const [id, roles] of Object.entries(allowed)) {
		const entry = SIMPLE.menu.find((item) => item.id === id)
		for (const role of entry.visibleIf['user.primaryRole'].in) {
			assert.ok(roles.includes(role), `${id} opens for ${role}`)
		}
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
	for (const overlay of SIMPLE.pages) {
		for (const items of Object.values(overlay.configAppend)) {
			for (const item of items) {
				strings.push(item.label)
				if (item.description) {
					strings.push(item.description)
				}
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
