// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Which menus each kind of organisation sees (segment-menu-gating). Evaluated
// with the shared library's own predicate function, against the runtime shape
// src/main.js builds, so the test sees what CnAppNav will render.
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
import { passesContextPredicates } from '../../node_modules/@conduction/nextcloud-vue/src/utils/visibleIfContext.js'
import { SEGMENTS } from '../../src/utils/workspaceRuntime.js'

const dir = new URL('../../src/manifest.d/', import.meta.url)
const entries = new Map()
/**
 * Index every menu entry, children included, by id.
 *
 * @param {Array<object>|undefined} items Menu entries.
 */
function collect(items) {
	for (const item of items || []) {
		entries.set(item.id, item)
		collect(item.children)
	}
}
for (const name of readdirSync(dir).filter((n) => n.endsWith('.json'))) {
	collect(JSON.parse(readFileSync(new URL(name, dir), 'utf8')).menu)
}

/**
 * Whether an entry renders for an admin in a segment. Admin passes every role
 * gate (and every dashboard gate), so only the segment decides here.
 *
 * @param {string} id Menu entry id.
 * @param {string} segment Segment code.
 * @return {boolean} Whether CnAppNav would render it.
 */
function visible(id, segment) {
	return passesContextPredicates(entries.get(id).visibleIf || {}, {
		user: {
			primaryRole: 'admin',
			canAdminDashboard: true,
			canTeachDashboard: true,
			canLearnDashboard: true,
		},
		workspace: { segment },
	})
}

const COMPANY = ['GroupCompliance', 'ExternalTraining', 'Compliance']
const MBO = ['GroupBpv']
const HE = ['GroupStudyProgress']
const SECONDARY_AND_UP = [
	'GroupExamBoard',
	'ExamAccommodationsMenu',
	'SubjectChoicesMenu',
	'SubjectChoicePickerMenu',
	'ApplicationsMenu',
	'AdmissionsRoundsMenu',
	'AdmissionsReviewBoardMenu',
]
const SCHOOL_SHAPE = [
	'GroupPeople',
	'LearnerProfilesMenu',
	'Attendance',
	'Schools',
	'Locations',
	'GroupPupilDossier',
	'GroupPlans',
	'SupportRequests',
	'ReportCardsMenu',
	'ReportPeriodsMenu',
	'GroupConferences',
	'SchoolAdviezenMenu',
]

test('the company default keeps every menu (no behaviour change)', () => {
	for (const id of entries.keys()) {
		assert.equal(
			visible(id, 'corporate'),
			true,
			`${id} must stay visible for corporate`,
		)
	}
})

test('a primary school sees the school shape', () => {
	for (const id of SCHOOL_SHAPE) {
		assert.equal(visible(id, 'po'), true, `${id} is part of the school shape`)
	}
	for (const id of [...COMPANY, ...MBO, ...HE, ...SECONDARY_AND_UP]) {
		assert.equal(
			visible(id, 'po'),
			false,
			`${id} is hidden for a primary school`,
		)
	}
})

test('company menus hide for every school and stay for companies and training institutes', () => {
	for (const id of COMPANY) {
		for (const segment of ['po', 'vo', 'mbo', 'he']) {
			assert.equal(visible(id, segment), false, `${id} hidden for ${segment}`)
		}
		assert.equal(visible(id, 'training'), true, `${id} visible for training`)
	}
})

test('BPV is MBO, BSA is higher education', () => {
	assert.deepEqual(
		SEGMENTS.filter((s) => visible('GroupBpv', s)),
		['mbo', 'corporate'],
	)
	assert.deepEqual(
		SEGMENTS.filter((s) => visible('GroupStudyProgress', s)),
		['he', 'corporate'],
	)
})

test('exam board, subject choices and intake start at secondary school', () => {
	for (const id of SECONDARY_AND_UP) {
		assert.equal(visible(id, 'po'), false, `${id} hidden for po`)
		for (const segment of ['vo', 'mbo', 'he']) {
			assert.equal(visible(id, segment), true, `${id} visible for ${segment}`)
		}
	}
	assert.deepEqual(
		SEGMENTS.filter((s) => visible('SchoolAdviezenMenu', s)),
		['po', 'vo', 'corporate'],
	)
})

/**
 * Run the menu validator against a copy of the manifest whose BPV gate was
 * changed by `mutate`, and return its exit status and stderr.
 *
 * @param {Function} mutate Receives the parsed work-placement fragment.
 * @return {{status: number, stderr: string}} The validator's result.
 */
function validateWith(mutate) {
	const root = mkdtempSync(join(tmpdir(), 'lq-menu-gates-'))
	const repo = fileURLToPath(new URL('../../', import.meta.url))
	for (const rel of [
		'tests/validate-menu-role-gates.js',
		'src/manifest.json',
		'lib/Service/DashboardRoleService.php',
		'lib/Settings/learniq_register.json',
	]) {
		mkdirSync(dirname(join(root, rel)), { recursive: true })
		copyFileSync(join(repo, rel), join(root, rel))
	}
	mkdirSync(join(root, 'src/manifest.d'), { recursive: true })
	for (const name of readdirSync(dir).filter((n) => n.endsWith('.json'))) {
		const doc = JSON.parse(readFileSync(new URL(name, dir), 'utf8'))
		if (name === 'work-placement.json') mutate(doc)
		writeFileSync(join(root, 'src/manifest.d', name), JSON.stringify(doc))
	}
	const run = spawnSync(
		process.execPath,
		[join(root, 'tests/validate-menu-role-gates.js')],
		{ encoding: 'utf8' },
	)
	rmSync(root, { recursive: true, force: true })
	return { status: run.status, stderr: run.stderr }
}

test('the validator refuses a gate that hides an entry from corporate', () => {
	const result = validateWith((doc) => {
		doc.menu.find((m) => m.id === 'GroupBpv').visibleIf['workspace.segment'] = {
			in: ['mbo'],
		}
	})
	assert.equal(result.status, 1)
	assert.match(result.stderr, /GroupBpv/)
	assert.match(result.stderr, /omits "corporate"/)
})

test('the validator refuses an unknown segment literal', () => {
	const result = validateWith((doc) => {
		doc.menu.find((m) => m.id === 'GroupBpv').visibleIf['workspace.segment'] = {
			in: ['mbo', 'corporate', 'hbo'],
		}
	})
	assert.equal(result.status, 1)
	assert.match(result.stderr, /unknown segment literal\(s\): hbo/)
})

test('the validator accepts the shipped gates', () => {
	assert.equal(validateWith(() => {}).status, 0)
})
