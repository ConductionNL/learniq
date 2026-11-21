// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The function entries of the app registry: handlers a manifest names by id
 * (a board's `moveHandler`). They sit apart from `registry.js`, which holds
 * components only, and main.js hands both to CnAppRoot.
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
 */

import { placementPhaseMover } from './utils/placementPhaseMove.js'

/**
 * A client that loads @nextcloud/axios and the router on first use, so this
 * module has no side effects when it is imported.
 */
const http = {
	async patch(url, body) {
		const [{ default: axios }, { generateUrl }] = await Promise.all([
			import('@nextcloud/axios'),
			import('@nextcloud/router'),
		])
		return axios.patch(generateUrl(url), body)
	},
}

export default {
	// The placement board drops a card through the manual move, never by
	// writing the server's phase (bpv-coach-today-and-placement-board T4).
	movePlacementPhase: { kind: 'handler', handler: placementPhaseMover(http) },
}
