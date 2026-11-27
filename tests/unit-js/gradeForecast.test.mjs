// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A learner enters a possible next mark with its weight and sees the average
// it would become and whether that passes; nothing is saved.
// Run by `npm run test:js-unit`.
//
// @spec openspec/changes/capability-rows-say-what-they-do/specs/grading/spec.md#scenario-a-learner-tries-a-possible-next-mark

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { forecastAverage, passMark } from '../../src/utils/gradeForecast.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

// Fenna's published marks: 4.0 (weight 1), 5.0 (weight 1) and 6.0 (weight 1.5)
// -> (4 + 5 + 9) / 3.5 = 5.14; with a pass rule of 5.5 on the final grade.
const plan = {
	components: [{ componentId: 'toets', weight: 1.5 }],
	passRules: [{ componentId: 'toets', minValue: 4 }, { componentId: null, minValue: 5.5 }],
}
const entries = [
	{ value: 4, weight: 1 },
	{ value: '5,0', weight: null, componentId: 'other' },
	{ value: 6, componentId: 'toets' },
]

test('a possible 7.0 with weight 2 shows the average it would become and that it passes', () => {
	const forecast = forecastAverage({ entries, plan, mark: '7.0', weight: 2 })

	assert.deepEqual(forecast, { current: 5.14, average: 5.82, passMark: 5.5, passes: true })
})

test('a low next mark forecasts a fail', () => {
	const forecast = forecastAverage({ entries, plan, mark: 3, weight: 2 })

	assert.equal(forecast.average, 4.36)
	assert.equal(forecast.passes, false)
})

test('the forecast does not change the grades it was computed from', () => {
	const before = JSON.stringify(entries)
	forecastAverage({ entries, plan, mark: 9, weight: 3 })

	assert.equal(JSON.stringify(entries), before)
})

test('no forecast until the mark and a positive weight are numbers', () => {
	assert.equal(forecastAverage({ entries, plan, mark: '', weight: 2 }), null)
	assert.equal(forecastAverage({ entries, plan, mark: 'abc', weight: 2 }), null)
	assert.equal(forecastAverage({ entries, plan, mark: 7, weight: 0 }), null)
})

test('without earlier grades the average is the possible mark itself', () => {
	assert.deepEqual(forecastAverage({ entries: [], plan: null, mark: 6, weight: 1 }), { current: null, average: 6, passMark: 5.5, passes: true })
})

test('the pass mark is the final-grade rule, else the Dutch 5.5', () => {
	assert.equal(passMark({ passRules: [{ componentId: null, minValue: 6 }] }), 6)
	assert.equal(passMark({ passRules: [{ componentId: 'x', minValue: 3 }] }), 5.5)
	assert.equal(passMark(null), 5.5)
})

test('the grade page renders the forecast and saves nothing', () => {
	const view = readFileSync(resolve(root, 'src/views/GradeImpactDetail.vue'), 'utf8')

	assert.match(view, /forecastAverage\(/)
	assert.match(view, /t\('learniq', 'What would one more mark do\?'\)/)
	const forecastBlock = view.slice(view.indexOf('grade-impact__forecast'))
	assert.doesNotMatch(forecastBlock.slice(0, 2500), /fetch\(|axios|saveObject|method: 'POST'/)
})
