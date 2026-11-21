// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { declineBody, TRANSITION_URL } from './signUpDecision.js'

/**
 * Decline one pending sign-up through its lifecycle transition.
 *
 * @param {string} objectId The Enrolment's uuid.
 * @param {string} reason The reason the learner reads.
 * @return {Promise<object>} The axios answer.
 * @spec openspec/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager
 */
export function declineSignUp(objectId, reason) {
	return axios.post(generateUrl(TRANSITION_URL, { id: objectId }), declineBody(reason))
}
