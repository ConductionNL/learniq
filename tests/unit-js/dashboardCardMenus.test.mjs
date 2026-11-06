// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A dashboard card on the learniq boards has no overflow Actions menu in its
// title row (LqTlVandaag, LqZoVandaag, LqMijnLeren, LqLlVandaag,
// LqCfPrivacygovernance, LqHrVandaag, LqHrPersonen, LqLessen). nextcloud-vue
// draws one per card unless the page says `showWidgetActions: false`, and the
// 40px menu pushed the card title 9.5px down (titleInsetTop 30.5 for 21).
// Their page headers carry no Actions menu either: only the buildiq button
// and the board's own buttons.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

const views = ['LearniqDashboards', 'LearniqLearnerHome', 'PeopleDashboard', 'LearningDashboard']

for (const view of views) {
	test(`${view} draws its dashboard cards without an Actions menu`, () => {
		const source = readFileSync(resolve(root, `src/views/${view}.vue`), 'utf8')
		const page = source.match(/<CnDashboardPage\b[^>]*>/)
		assert.ok(page, 'the view renders a CnDashboardPage')
		assert.match(page[0], /:showWidgetActions="false"/)
		assert.match(page[0], /:showActionsMenu="false"/)
	})
}

test('LearniqCompliance draws its page header without an Actions menu', () => {
	const source = readFileSync(resolve(root, 'src/views/LearniqCompliance.vue'), 'utf8')
	assert.match(source.match(/<CnDashboardPage\b[^>]*>/)[0], /:showActionsMenu="false"/)
})

test('the progress and Today dashboards draw their page header without an Actions menu', () => {
	const progress = JSON.parse(readFileSync(resolve(root, 'src/manifest.d/progress.json'), 'utf8')).pages.find((p) => p.id === 'ProgressLanding')
	assert.equal(progress.config.showActionsMenu, false)
	const today = JSON.parse(readFileSync(resolve(root, 'src/menu-layout.simple.json'), 'utf8')).pages.find((p) => p.id === 'Dashboard')
	assert.equal(today.config.showActionsMenu, false)
})

test('the privacy governance dashboard draws its cards without an Actions menu', () => {
	const fragment = JSON.parse(readFileSync(resolve(root, 'src/manifest.d/dashboard.json'), 'utf8'))
	const page = fragment.pages.find((p) => p.id === 'PrivacyGovernanceDashboard')
	assert.ok(page, 'the page exists')
	assert.equal(page.config.showWidgetActions, false)
	assert.equal(page.config.showActionsMenu, false)
})
