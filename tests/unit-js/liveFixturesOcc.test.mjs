// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

// The live fixtures read and delete learniq app config and run its jobs
// through occ. They resolve occ through the suite's binding to the instance
// under test, never a default container: `docker exec nextcloud` is the
// SHARED developer instance.

import assert from 'node:assert/strict'
import { afterEach, test } from 'node:test'
import { occCommand } from '../e2e/live-fixtures.ts'

const KEYS = ['LEARNIQ_E2E_OCC', 'LEARNIQ_E2E_CONTAINER', 'NEXTCLOUD_CONTAINER']
const saved = Object.fromEntries(KEYS.map((k) => [k, process.env[k]]))

afterEach(() => {
	for (const k of KEYS) {
		if (saved[k] === undefined) delete process.env[k]
		else process.env[k] = saved[k]
	}
})

test('with nothing named, occ is not sent to the shared nextcloud container', () => {
	for (const k of KEYS) delete process.env[k]
	assert.deepEqual(occCommand(), ['php', 'occ'])
})

test('a named container is the one occ runs in', () => {
	for (const k of KEYS) delete process.env[k]
	process.env.NEXTCLOUD_CONTAINER = 'livepass-rig-1'
	assert.deepEqual(occCommand(), ['docker', 'exec', '-u', 'www-data', 'livepass-rig-1', 'php', 'occ'])
})
