// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import {
	resolveStructureProfile,
	STRUCTURE_SETTING,
} from '../utils/structureProfile.js'

/**
 * Read which structure the app shows, for the admin settings page.
 *
 * The admin page is rendered by OpenRegister's generic settings class, which
 * provides no initial state of learniq's own, so the section asks learniq's
 * settings endpoint. That endpoint is admin-guarded on the server.
 *
 * @param {{ get: (url: string) => Promise<{ data: object }> }} http An axios-like client.
 * @param {string} url The settings endpoint.
 * @return {Promise<string>} `simple` or `full`.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
 */
export async function loadMenuStructure(http, url) {
	const { data } = await http.get(url)
	return resolveStructureProfile(data?.[STRUCTURE_SETTING])
}

/**
 * Save which structure the app shows.
 *
 * It goes through learniq's own settings write, which carries
 * `#[AuthorizedAdminSetting]`, and it names the key from
 * `utils/structureProfile.js` so the admin section, the boot code and the PHP
 * side cannot spell it three ways.
 *
 * The settings write answers success for a key it does not know. Reading the
 * stored value back from the answer is the only way to tell a save from a
 * no-op, so a write that does not echo the wanted word is an error here.
 *
 * @param {{ put: (url: string, body: object) => Promise<{ data: object }> }} http An axios-like client.
 * @param {string} url The settings endpoint.
 * @param {string} structure `simple` or `full`.
 * @return {Promise<string>} The structure the server stored.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
 */
export async function saveMenuStructure(http, url, structure) {
	const wanted = resolveStructureProfile(structure)
	const { data } = await http.put(url, { [STRUCTURE_SETTING]: wanted })
	const stored = data?.config?.[STRUCTURE_SETTING]
	if (stored !== wanted) {
		throw new Error('settings write did not store the structure')
	}
	return wanted
}
