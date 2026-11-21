// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// OpenRegister's object list filters on the property name itself. A
// `filters[name]=value` parameter is not an error there: it matches nothing, so
// the page shows an empty list and says nothing.
//
// @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const SRC = fileURLToPath(new URL('../../src', import.meta.url))

test('no object list is filtered with the filters[name] parameter', () => {
	const files = readdirSync(SRC, { recursive: true }).map(String).filter((file) => /\.(vue|js)$/.test(file))
	const offenders = files.filter((file) => readFileSync(join(SRC, file), 'utf8').includes('filters['))
	assert.deepEqual(offenders, [])
})
