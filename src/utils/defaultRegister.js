/**
 * The admin settings "Default register" picker's round trip.
 *
 * The picker stores one string under the `register` app config key (the only
 * register key `SettingsService::CONFIG_KEYS` persists, and the one the
 * plain settings form reads) and has to find that string again among the
 * registers OpenRegister lists, whether it was saved as a slug, an id or a
 * uuid.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a
 * Node test runner without an SFC compile step.
 *
 * @spec openspec/changes/archive/retrofit-2026-05-25-app-shell-settings/tasks.md#tasks
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * The app config key the picker writes and reads.
 *
 * @type {string}
 */
export const DEFAULT_REGISTER_KEY = 'register'

/**
 * The string saved for a picked register: its slug, else its id.
 *
 * @param {object|string|null} option The picked register, or a bare value.
 * @return {string} The value to save, or '' when nothing is picked.
 * @spec openspec/changes/archive/retrofit-2026-05-25-app-shell-settings/tasks.md#tasks
 */
export function registerValue(option) {
	if (option === null || option === undefined) {
		return ''
	}
	if (typeof option !== 'object') {
		return String(option)
	}
	return String(option.slug || option.id || option.uuid || '')
}

/**
 * The listed register a saved value points at.
 *
 * @param {Array<object>} options The registers OpenRegister lists.
 * @param {string|null|undefined} saved The saved value.
 * @return {object|null} The matching register, or null when none matches.
 * @spec openspec/changes/archive/retrofit-2026-05-25-app-shell-settings/tasks.md#tasks
 */
export function selectedRegister(options, saved) {
	const wanted = saved === null || saved === undefined ? '' : String(saved)
	if (wanted === '' || !Array.isArray(options)) {
		return null
	}
	return (
		options.find((option) =>
			[option?.slug, option?.id, option?.uuid]
				.filter((v) => v !== null && v !== undefined)
				.map(String)
				.includes(wanted),
		) || null
	)
}
