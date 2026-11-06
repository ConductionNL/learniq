/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The flow page turns on the Canvas / List switch (`config.listMode`) and the
 * test run on an example payload (`config.testPayload`) of CnFlowEditorPage.
 * Both are opt-in, so losing a key hides the screen without any error.
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const manifest = JSON.parse(readFileSync(new URL('../../src/manifest.json', import.meta.url), 'utf8'))
const flowPages = manifest.pages.filter((page) => page.type === 'flow')

test('the app declares a flow page', () => {
	assert.ok(flowPages.length > 0)
})

for (const page of flowPages) {
	test(`${page.id} offers the list view and the payload test`, () => {
		assert.equal(page.config.listMode, true)
		assert.equal(page.config.testPayload, true)
	})
}
