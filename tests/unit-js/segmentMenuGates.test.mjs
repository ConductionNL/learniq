// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Which surfaces each kind of organisation sees (segment-menu-gating, and
// company-segment-menu-gating for decision D26).
//
// 🔴 THE MENU IS BUILT, NOT READ. The first version of this test evaluated
// the fragment nodes, and passed while five gates never ran: menu-layout.json
// relocates their groups, and the shared applyMenuRelocations() dissolves a
// relocated group and drops its visibleIf. This version builds the menu with
// the library's own buildManifest() and the app's menu-layout.json, as
// src/main.js does, and evaluates what CnAppNav, CnNavCardGrid and the Reports
// page render: a menu entry, a card on a nav-card-grid landing page, and a
// Reports card after applyReportCardGates().
// Run via `node --test tests/unit-js/` (package.json's `test:js-unit`).

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import {
	copyFileSync,
	mkdirSync,
	mkdtempSync,
	readdirSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { passesContextPredicates } from '../../node_modules/@conduction/nextcloud-vue/src/utils/visibleIfContext.js'
import { applyReportCardGates } from '../../src/utils/reportCardGates.js'
import { buildWorkspaceRuntime, SEGMENTS } from '../../src/utils/workspaceRuntime.js'

const dir = new URL('../../src/manifest.d/', import.meta.url)
const readJson = (url) => JSON.parse(readFileSync(url, 'utf8'))
const BASE = readJson(new URL('../../src/manifest.json', import.meta.url))
const LAYOUT = readJson(new URL('../../src/menu-layout.json', import.meta.url))
const FRAGMENT_NAMES = readdirSync(dir)
	.filter((n) => n.endsWith('.json'))
	.sort()
const FRAGMENTS = FRAGMENT_NAMES.map((name) => readJson(new URL(name, dir)))

/** An install that never chose: the default segment, no chosen segment. */
const NEVER = { segment: 'corporate', chosen: null }

/**
 * The state after an admin chose a segment in the wizard.
 *
 * @param {string} code Segment code.
 * @return {{segment: string, chosen: string}} The state.
 */
const chose = (code) => ({ segment: code, chosen: code })

/**
 * Build the manifest the app renders for an admin in a state.
 *
 * @param {{segment: string, chosen: (string|null)}} state The workspace state.
 * @return {object} The built manifest, Reports cards filtered.
 */
function build(state) {
	const base = structuredClone(BASE)
	base.runtime = {
		user: {
			primaryRole: 'admin',
			canAdminDashboard: true,
			canTeachDashboard: true,
			canLearnDashboard: true,
			isConfidentialCounsellor: true,
			managesLearners: true,
		},
		workspace: buildWorkspaceRuntime(undefined, state.segment, state.chosen),
	}
	return applyReportCardGates(
		buildManifest(base, structuredClone(FRAGMENTS), structuredClone(LAYOUT)),
	)
}

/**
 * The ids of every surface that renders, or of every surface there is when
 * `all` is set. Ids: `menu:<id>`, `card:<page>:<card>`, `report:<card>`.
 *
 * @param {{segment: string, chosen: (string|null)}} state The workspace state.
 * @param {boolean} all Ignore every gate (the full structure).
 * @return {Set<string>} Surface ids.
 */
function surfaces(state, all = false) {
	const manifest = all ? build(NEVER) : build(state)
	const runtime = manifest.runtime
	const passes = (visibleIf) =>
		all || passesContextPredicates(visibleIf || {}, runtime)
	const out = new Set()
	const shownRoutes = new Set()
	const walk = (items, parentShown) => {
		for (const item of items || []) {
			const shown = parentShown && passes(item.visibleIf)
			if (shown) {
				out.add(`menu:${item.id}`)
				if (item.route) shownRoutes.add(item.route)
			}
			walk(item.children, shown)
		}
	}
	walk(manifest.menu, true)

	const pages = all ? build(NEVER).pages : manifest.pages
	for (const page of pages) {
		const reachable = shownRoutes.has(page.id)
		if (page.type === 'reports' && reachable) {
			const cards = all
				? BASE.pages.find((p) => p.id === page.id).config.cards
				: page.config.cards
			for (const card of cards) out.add(`report:${card.id}`)
		}
		for (const widget of page.config?.widgets || []) {
			if (widget.type !== 'nav-card-grid' || !reachable) continue
			for (const card of widget.content?.entries || []) {
				if (passes(card.visibleIf)) out.add(`card:${page.id}:${card.id}`)
			}
		}
	}
	return out
}

const ALL = surfaces(NEVER, true)
function hiddenIn(state) {
	const shown = surfaces(state)
	return [...ALL].filter((id) => !shown.has(id)).sort()
}

const BPV_CARDS = [
	'BpvPlacementsMenu',
	'PraktijkovereenkomstenMenu',
	'WerkprocesAssessmentsMenu',
	'BpvVisitReportsMenu',
	'PraktijkopleidersMenu',
].map((id) => `card:ProgressLanding:${id}`)
const BSA_CARDS = [
	'BsaRiskDashboardMenu',
	'BsaTrajectoriesMenu',
	'BsaWarningsMenu',
	'BsaDecisionsMenu',
].map((id) => `card:ProgressLanding:${id}`)
const ENGAGEMENT_AND_EVALUATION_CARDS = [
	'LeaderboardViewMenu',
	'PointRulesMenu',
	'EngagementLevelsMenu',
	'LeaderboardsMenu',
	'PointAwardsMenu',
	'LearnerEngagementsMenu',
	'EvaluationCampaignsMenu',
	'CourseQualityReportMenu',
	'CourseEvaluationResponsesMenu',
	'ImprovementActionsMenu',
].map((id) => `card:ProgressLanding:${id}`)
const EXAM_BOARD_CARDS = [
	'ExemptionCasesMenu',
	'FraudCasesMenu',
	'ItemRevisionFlagsMenu',
].map((id) => `card:ComplianceLanding:${id}`)
const COMPANY_CARDS = ['Compliance', 'ExternalTraining'].map(
	(id) => `card:ComplianceLanding:${id}`,
)
const SCHOOL_ONLY_MENU = [
	'ReportPeriodsMenu',
	'ReportCardsMenu',
	'ReportCardTemplatesMenu',
	'ApplicationsMenu',
	'AdmissionsRoundsMenu',
	'AdmissionsReviewBoardMenu',
	'SchoolAdviezenMenu',
	'BookConferenceSlotsMenu',
	'ConferenceRoundsMenu',
	'TeacherAvailabilitiesMenu',
	'ConferenceScheduleBoardMenu',
	'ConferenceReportsMenu',
].map((id) => `menu:${id}`)

test('the built structure has the surfaces this test reasons about', () => {
	for (const id of [
		...SCHOOL_ONLY_MENU,
		...BPV_CARDS,
		...BSA_CARDS,
		...EXAM_BOARD_CARDS,
		...COMPANY_CARDS,
		'menu:GroupCompliance',
		'menu:Attendance',
		'report:AttendanceFlags',
		'report:BpvVisitReports',
	]) {
		assert.ok(ALL.has(id), `${id} exists in the built manifest`)
	}
})

test('an install that never chose sees every surface (no behaviour change)', () => {
	assert.deepEqual(hiddenIn(NEVER), [])
})

const SUBJECT_CHOICE_MENU = ['SubjectChoicesMenu', 'SubjectChoicePickerMenu'].map(
	(id) => `menu:${id}`,
)

test('a company that chose Company loses exactly the school-only surfaces (D26, D34)', () => {
	assert.deepEqual(
		hiddenIn(chose('corporate')),
		[
			...SCHOOL_ONLY_MENU,
			...BPV_CARDS,
			...BSA_CARDS,
			...EXAM_BOARD_CARDS,
			...SUBJECT_CHOICE_MENU,
			'report:AttendanceFlags',
			'report:BpvVisitReports',
			'report:BsaRiskDashboard',
		].sort(),
	)
})

test('a primary school sees the school shape and not the rest', () => {
	const shown = surfaces(chose('po'))
	for (const id of [
		...SCHOOL_ONLY_MENU.filter(
			(m) => !/Applications|AdmissionsRounds|AdmissionsReviewBoard/.test(m),
		),
		'menu:LearnerProfilesMenu',
		'menu:Attendance',
		'menu:Schools',
		'menu:Locations',
		'menu:DossierNotesMenu',
		'menu:GroupPlans',
		'menu:SupportRequests',
		'menu:GroupCompliance',
		'report:AttendanceFlags',
	]) {
		assert.ok(shown.has(id), `${id} shows for a primary school`)
	}
	for (const id of [
		...BPV_CARDS,
		...BSA_CARDS,
		...ENGAGEMENT_AND_EVALUATION_CARDS,
		...EXAM_BOARD_CARDS,
		...COMPANY_CARDS,
		'menu:ApplicationsMenu',
		'menu:AdmissionsRoundsMenu',
		'menu:AdmissionsReviewBoardMenu',
		'menu:SubjectChoicesMenu',
		'menu:SubjectChoicePickerMenu',
		'menu:ExamAccommodationsMenu',
		'report:BpvVisitReports',
		'report:BsaRiskDashboard',
		'report:CourseQualityReport',
	]) {
		assert.equal(shown.has(id), false, `${id} is hidden for a primary school`)
	}
})

test('BPV is for MBO and installs that never chose; BSA for higher education only (D34)', () => {
	const states = [['never', NEVER], ...SEGMENTS.map((s) => [s, chose(s)])]
	const seeing = (id) =>
		states.filter(([, state]) => surfaces(state).has(id)).map(([name]) => name)
	for (const id of [...BPV_CARDS, 'report:BpvVisitReports']) {
		assert.deepEqual(seeing(id), ['never', 'mbo'], id)
	}
	for (const id of [...BSA_CARDS, 'report:BsaRiskDashboard']) {
		assert.deepEqual(seeing(id), ['never', 'he'], id)
	}
	for (const id of SUBJECT_CHOICE_MENU) {
		assert.deepEqual(seeing(id), ['never', 'vo', 'mbo', 'he'], id)
	}
})

test('every school keeps Compliance, with the exam board from secondary school up', () => {
	for (const code of SEGMENTS) {
		const shown = surfaces(chose(code))
		assert.ok(shown.has('menu:GroupCompliance'), `Compliance shows for ${code}`)
		const company = ['corporate', 'training'].includes(code)
		for (const id of COMPANY_CARDS) {
			assert.equal(shown.has(id), company, `${id} for ${code}`)
		}
		const exam = ['vo', 'mbo', 'he', 'training'].includes(code)
		for (const id of EXAM_BOARD_CARDS) {
			assert.equal(shown.has(id), exam, `${id} for ${code}`)
		}
	}
})

test('a training institute keeps admissions, school-only reporting aside, and the exam board', () => {
	const shown = surfaces(chose('training'))
	for (const id of [
		'menu:ApplicationsMenu',
		'menu:AdmissionsRoundsMenu',
		'menu:AdmissionsReviewBoardMenu',
		...EXAM_BOARD_CARDS,
	]) {
		assert.ok(shown.has(id), `${id} shows for a training institute`)
	}
})

test('a card carries the workspace gate of the menu entry on its route', () => {
	const gateOf = (visibleIf) =>
		Object.fromEntries(
			Object.entries(visibleIf || {}).filter(([k]) =>
				k.startsWith('workspace.'),
			),
		)
	const byRoute = new Map()
	const collect = (items) => {
		for (const item of items || []) {
			if (item.route && Object.keys(gateOf(item.visibleIf)).length > 0) {
				byRoute.set(item.route, gateOf(item.visibleIf))
			}
			collect(item.children)
		}
	}
	for (const doc of [BASE, ...FRAGMENTS]) collect(doc.menu)
	for (const doc of [BASE, ...FRAGMENTS]) {
		for (const page of doc.pages || []) {
			const cards = [
				...(page.type === 'reports' ? page.config.cards : []),
				...(page.config?.widgets || [])
					.filter((w) => w.type === 'nav-card-grid')
					.flatMap((w) => w.content?.entries || []),
			]
			for (const card of cards) {
				if (!byRoute.has(card.route)) continue
				assert.deepEqual(
					gateOf(card.visibleIf),
					byRoute.get(card.route),
					`${page.id} card ${card.id} matches the menu entry for ${card.route}`,
				)
			}
		}
	}
})

/**
 * Run the menu validator against a copy of the manifest whose fragment
 * `name` was changed by `mutate`, and return its exit status and stderr.
 *
 * @param {string} name Fragment file name.
 * @param {Function} mutate Receives the parsed fragment.
 * @return {{status: number, stderr: string}} The validator's result.
 */
function validateWith(name, mutate) {
	const root = mkdtempSync(join(tmpdir(), 'lq-menu-gates-'))
	const repo = fileURLToPath(new URL('../../', import.meta.url))
	for (const rel of [
		'tests/validate-menu-role-gates.js',
		'src/manifest.json',
		'src/menu-layout.json',
		'lib/Service/DashboardRoleService.php',
		'lib/Settings/learniq_register.json',
	]) {
		mkdirSync(dirname(join(root, rel)), { recursive: true })
		copyFileSync(join(repo, rel), join(root, rel))
	}
	mkdirSync(join(root, 'src/manifest.d'), { recursive: true })
	for (const [index, fragmentName] of FRAGMENT_NAMES.entries()) {
		const doc = structuredClone(FRAGMENTS[index])
		if (fragmentName === name) mutate(doc)
		writeFileSync(
			join(root, 'src/manifest.d', fragmentName),
			JSON.stringify(doc),
		)
	}
	const run = spawnSync(
		process.execPath,
		[join(root, 'tests/validate-menu-role-gates.js')],
		{ encoding: 'utf8' },
	)
	rmSync(root, { recursive: true, force: true })
	return { status: run.status, stderr: run.stderr }
}

function leaf(doc, id) {
	const find = (items) => {
		for (const item of items || []) {
			if (item.id === id) return item
			const hit = find(item.children)
			if (hit) return hit
		}
		return null
	}
	return find(doc.menu)
}

test('the validator refuses a segment gate that hides an entry from corporate', () => {
	const result = validateWith('work-placement.json', (doc) => {
		leaf(doc, 'BpvPlacementsMenu').visibleIf['workspace.segment'] = {
			in: ['mbo'],
		}
	})
	assert.equal(result.status, 1)
	assert.match(result.stderr, /BpvPlacementsMenu/)
	assert.match(result.stderr, /omits "corporate"/)
})

test('the validator refuses an unknown segment literal', () => {
	const result = validateWith('work-placement.json', (doc) => {
		leaf(doc, 'BpvPlacementsMenu').visibleIf['workspace.segment'] = {
			in: ['mbo', 'corporate', 'hbo'],
		}
	})
	assert.equal(result.status, 1)
	assert.match(result.stderr, /unknown segment literal\(s\): hbo/)
})

test('the validator refuses a chosen-segment gate that would hide from installs that never chose', () => {
	const result = validateWith('learning.json', (doc) => {
		leaf(doc, 'ReportCardsMenu').visibleIf['workspace.chosenSegment'] = {
			in: ['po'],
		}
	})
	assert.equal(result.status, 1)
	assert.match(result.stderr, /ReportCardsMenu/)
	assert.match(result.stderr, /must be \{ notIn: \[\.\.\.\] \}/)
})

test('the validator refuses an unknown chosen-segment literal, on a card too', () => {
	const result = validateWith('progress.json', (doc) => {
		const landing = doc.pages.find((p) => p.id === 'ProgressLanding')
		landing.config.widgets[0].content.entries.find(
			(c) => c.id === 'BpvPlacementsMenu',
		).visibleIf['workspace.chosenSegment'] = { notIn: ['company'] }
	})
	assert.equal(result.status, 1)
	assert.match(result.stderr, /ProgressLanding card BpvPlacementsMenu/)
	assert.match(result.stderr, /unknown segment literal\(s\): company/)
})

test('the validator refuses a segment gate on a relocated group, because it never runs', () => {
	const result = validateWith('work-placement.json', (doc) => {
		doc.menu.find((m) => m.id === 'GroupBpv').visibleIf['workspace.segment'] = {
			in: ['mbo', 'corporate'],
		}
	})
	assert.equal(result.status, 1)
	assert.match(result.stderr, /GroupBpv/)
	assert.match(result.stderr, /never runs/)
})

test('the validator accepts the shipped gates', () => {
	const result = validateWith('work-placement.json', () => {})
	assert.equal(result.status, 0, result.stderr)
})
