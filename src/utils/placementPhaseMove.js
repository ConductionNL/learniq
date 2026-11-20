// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Moving a card on the placement board (bpv-coach-today-and-placement-board T4).
 *
 * A placement's `phase` is the server's: it follows the placement's facts and
 * a value sent for it is replaced. A drag on the board is therefore never a
 * write of `phase`; it sends `phaseOverride: { phase }`, which the server
 * stamps with who and when and keeps until one of the facts changes.
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
 */

import { objectsUrl } from './customPages.js'

/** The columns of the board, in order: the phases a placement can be in. */
export const PLACEMENT_PHASES = ['searching', 'agreement', 'running', 'assessment', 'rounding-off', 'done']

/**
 * The body of a manual move to a phase.
 *
 * @param {string} phase The column the card was dropped in.
 * @return {{phaseOverride: {phase: string}}} The PATCH body.
 * @throws {Error} When the column is not a phase.
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
 */
export function phaseMovePatch(phase) {
	if (!PLACEMENT_PHASES.includes(phase)) {
		throw new Error(`Not a placement phase: ${phase}`)
	}
	return { phaseOverride: { phase } }
}

/**
 * The board's move handler: PATCH the dropped card's placement with the move.
 *
 * @param {{patch: (url: string, body: object) => Promise<{data: object}>}} http An axios-like client.
 * @param {(url: string) => string} [urlOf] Makes the app-relative URL absolute (generateUrl).
 * @return {(move: {card: object, toKey: string, value?: string}) => Promise<object>} The handler.
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-placement-has-a-phase-that-follows-its-facts-and-says-when-it-is-late
 */
export function placementPhaseMover(http, urlOf = (url) => url) {
	return async ({ card, toKey, value }) => {
		const id = card?.id ?? card?.['@self']?.id ?? card?.uuid
		if (!id) {
			throw new Error('This placement cannot be moved: it has no id.')
		}
		const body = phaseMovePatch(String(value ?? toKey))
		const { data } = await http.patch(urlOf(objectsUrl('bpv-placement', String(id))), body)
		return data && typeof data === 'object' ? data : {}
	}
}
