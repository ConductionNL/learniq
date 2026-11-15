// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The conference round picker that the guardian's booking screen
 * (BookConferenceSlotsView) and the coordinator's schedule board
 * (ConferenceScheduleBoard) share: the rounds of one lifecycle, the picker
 * options, the selected round and the teachers it offers.
 *
 * Pure: the transport and the URL builder are passed in, so the module runs
 * under `node --test` with nothing mocked.
 *
 * @spec openspec/specs/parent-conferences/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
 */

const ROUNDS =
	'/apps/openregister/api/objects/learniq/conference-round?lifecycle={lifecycle}&_limit=100'

/**
 * The id a round is picked by.
 *
 * @param {object} round The round.
 * @return {string}
 */
function roundId(round) {
	return round.id || round.uuid
}

/**
 * Read the rounds in one lifecycle state. An answer without a list is
 * kept as it came.
 *
 * @param {{get: function(string): Promise<{data: unknown}>, urlFor: function(string): string}} http The transport and the URL builder.
 * @param {string} lifecycle The lifecycle state.
 * @return {Promise<Array<object>|unknown>}
 */
export async function fetchRounds({ get, urlFor }, lifecycle) {
	const response = await get(urlFor(ROUNDS.replace('{lifecycle}', lifecycle)))
	return (
		(response.data && (response.data.results || response.data.objects))
		|| response.data
		|| []
	)
}

/**
 * The picker options of the rounds.
 *
 * @param {Array<object>} rounds The rounds.
 * @return {Array<{id: string, label: string}>}
 */
export function roundOptions(rounds) {
	return rounds.map((r) => ({
		id: roundId(r),
		label: r.name || r.id || r.uuid,
	}))
}

/**
 * The round with this id, or null.
 *
 * @param {Array<object>} rounds The rounds.
 * @param {string} id The selected id.
 * @return {object|null}
 */
export function findRound(rounds, id) {
	return rounds.find((r) => roundId(r) === id) || null
}

/**
 * The teacher options of a round, none without a round.
 *
 * @param {object|null} round The round.
 * @return {Array<{id: string, label: string}>}
 */
export function roundTeacherOptions(round) {
	if (!round) return []
	return (round.teacherIds || []).map((id) => ({
		id,
		label: id,
	}))
}
