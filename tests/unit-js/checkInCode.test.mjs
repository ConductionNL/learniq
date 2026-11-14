// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The self check-in code reaches CnQrCode as the check-in link (QR value) and
// the code to type in, read from the window's own code endpoint.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { checkInCodePath, qrFromCodeResponse } from '../../src/utils/checkInCode.js'

test('the code is read from the window it belongs to', () => {
	assert.equal(
		checkInCodePath({ id: 'w-1' }),
		'/apps/learniq/api/check-in/w-1/code',
	)
})

test('the QR says the check-in link and shows the code to type in', () => {
	const data = { code: 'K7QM4RTA', url: 'https://x/check-in?window=w-1&code=K7QM4RTA', secondsLeft: 18, checkInCount: 3 }
	assert.deepEqual(qrFromCodeResponse(data), {
		value: 'https://x/check-in?window=w-1&code=K7QM4RTA',
		code: 'K7QM4RTA',
		secondsLeft: 18,
	})
})

test('an empty answer draws no code', () => {
	assert.deepEqual(qrFromCodeResponse(null), { value: '', code: '', secondsLeft: undefined })
})
