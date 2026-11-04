// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Reading the self check-in code of an open CheckInWindow for CnQrCode.
// @spec openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson

import { objectId } from './customPages.js'

/** Seconds between reads of the code and the check-in count. */
export const CHECK_IN_READ_SECONDS = 5

/**
 * Where CnQrCode reads the current code of a window.
 *
 * @param {object} checkInWindow The open CheckInWindow.
 * @return {string} An app-absolute path.
 * @spec openspec/specs/attendance/spec.md#requirement-the-check-in-code-changes-every-thirty-seconds-in-the-room
 */
export function checkInCodePath(checkInWindow) {
	return `/apps/learniq/api/check-in/${objectId(checkInWindow)}/code`
}

/**
 * Turns the code endpoint's answer into what CnQrCode reads: the check-in
 * link (which carries the code) as the QR value, and the code to type in.
 *
 * @param {object} data `{ code, url, secondsLeft }` from the code endpoint.
 * @return {{ value: string, code: string, secondsLeft: number|undefined }}
 * @spec openspec/specs/attendance/spec.md#requirement-the-check-in-code-changes-every-thirty-seconds-in-the-room
 */
export function qrFromCodeResponse(data) {
	return {
		value: data?.url ?? '',
		code: data?.code ?? '',
		secondsLeft: data?.secondsLeft,
	}
}
