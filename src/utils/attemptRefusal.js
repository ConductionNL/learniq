// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The error for an attempt the server did not create. A 422 is the attempt
 * gate's refusal and carries its reason and message.
 *
 * @param {Response} resp The failed create response.
 * @return {Promise<Error>}
 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
 */
export async function attemptCreateError(resp) {
	const err = new Error(`AssessmentResult create failed: ${resp.status}`)
	if (resp.status === 422) {
		// AssessmentAttemptGateListener refused the attempt.
		const body = await resp.json().catch(() => ({}))
		err.gateReason = body?.errors?.reason ?? null
		err.gateMessage = body?.errors?.message ?? body?.error ?? null
	}
	return err
}
