// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The setup wizard names a soft dependency as not installed when its id answers
// to nothing, so the manifest has to use the id the app ships in info.xml.
//
// @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = fileURLToPath(new URL('../..', import.meta.url))

test('the soft dependency uses integriq, the id the app ships', () => {
	const manifest = JSON.parse(readFileSync(`${ROOT}src/manifest.json`, 'utf8'))
	const ids = manifest.dependencies.map((dependency) => dependency.id ?? dependency)
	assert.ok(ids.includes('integriq'))
	assert.ok(!ids.includes('openconnector'))
})
