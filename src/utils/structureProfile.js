// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The structure profile: two shapes of the same app, built from one manifest.
 *
 * learniq ships `simple` and `full`. `full` is the navigation and the pages as
 * they were before this file existed. `simple` is what one role needs on a
 * working day: at most ten menu entries under three captions, with everything
 * else one level down. `simple` is the default, and an administrator brings
 * `full` back with the app setting `menu_structure`.
 *
 * A profile is a layout file next to the manifest:
 *
 *   src/menu-layout.json          full
 *   src/menu-layout.simple.json   simple
 *
 * Both hold the four keys the library's `buildManifest` already reads
 * (`relocations`, `removals`, `settingsSection`, `integrationsSection`). A
 * profile file may hold two more, which `buildManifest` has no word for and
 * this module applies around it:
 *
 *   menu    Entries merged BEFORE the manifest's own menu. `buildManifest`
 *           merges entries by id and the first definition of a key wins, so an
 *           entry that carries only `id` and `order` keeps its label, icon and
 *           route from the manifest and takes the order written here. An entry
 *           the manifest does not know is added as written.
 *   pages   Overlays on built pages, by id. `config` replaces the named
 *           config keys, `configPatch` changes items of a list by name (a
 *           `null` takes the item out), `configAppend` appends items, and
 *           `configOrder` moves the named items to the front. They apply in
 *           that order. A name is the item's `id`, else its `key`, else its
 *           `label`. `slots` adds entries to the page's slot map. `page`
 *           replaces the page's own `type`, `title` or `component` (a `null`
 *           takes the key out), which is how a page changes kind in one
 *           profile and stays what it was in the other. `when` is a predicate
 *           on the manifest runtime: the overlay applies only for a signed-in
 *           user it passes for. An overlay never adds a page and never
 *           removes one.
 *   nav     Merged over the manifest's `nav` (the brand block and the primary
 *           action CnAppNav draws). A string value `@theming.<key>` is read
 *           from the instance's theming capabilities (`name`, `logo`, ...),
 *           so a profile can show the school's own name and logo without
 *           naming one; a placeholder the instance cannot answer is left
 *           empty, never invented. The primary action may carry a `visibleIf`
 *           on the runtime: it is judged once at boot and the action is
 *           dropped for a reader it does not pass for (the library knows no
 *           gate on a primary action).
 *
 * Nothing here deletes anything. The pages, the routes and the fragments are
 * the same in both profiles, which is what keeps every deep link working.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md
 */

/** The profile a fresh instance gets. */
export const STRUCTURE_SIMPLE = 'simple'

/** The profile that keeps the navigation and pages as they were. */
export const STRUCTURE_FULL = 'full'

/** The app setting, and the initial-state key the page controller provides. */
export const STRUCTURE_SETTING = 'menu_structure'

/** The layout keys `buildManifest` reads. Everything else stays out of its way. */
const LAYOUT_KEYS = [
	'relocations',
	'removals',
	'settingsSection',
	'integrationsSection',
]

/**
 * The keys of a page itself an overlay may replace. The id and the route are
 * not among them: an overlay never moves a page and never renames one.
 */
const PAGE_KEYS = ['type', 'title', 'component']

/**
 * The profile a stored value stands for.
 *
 * Only the exact word `full` selects the full structure. Anything else, an
 * unset key and a typing mistake included, is the simple one: the default has
 * to be the answer whenever the setting does not clearly say otherwise.
 *
 * @param {unknown} raw The stored setting, as initial state hands it over.
 * @return {string} `simple` or `full`.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
 */
export function resolveStructureProfile(raw) {
	return raw === STRUCTURE_FULL ? STRUCTURE_FULL : STRUCTURE_SIMPLE
}

/**
 * Apply one page overlay to one built page, without touching the original.
 *
 * @param {object} page The built page.
 * @param {object} overlay `{ id, when?, page?, config?, configPatch?, configAppend?, configOrder?, slots? }`.
 *   The order is fixed: replace keys, patch items by name, append, then order.
 * @return {object} A new page object.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
export function applyPageOverlay(page, overlay) {
	const config = { ...(page.config || {}), ...(overlay.config || {}) }
	const patch = overlay.configPatch || {}
	for (const key of Object.keys(patch)) {
		const byName = patch[key] || {}
		const current = Array.isArray(config[key]) ? config[key] : []
		config[key] = current
			.filter((item) => byName[overlayItemName(item)] !== null)
			.map((item) => {
				const change = byName[overlayItemName(item)]
				return change === undefined || typeof item !== 'object'
					? item
					: { ...item, ...change }
			})
	}
	const append = overlay.configAppend || {}
	for (const key of Object.keys(append)) {
		const current = Array.isArray(config[key]) ? config[key] : []
		const extra = Array.isArray(append[key]) ? append[key] : []
		config[key] = [...current, ...extra]
	}
	const order = overlay.configOrder || {}
	for (const key of Object.keys(order)) {
		const current = Array.isArray(config[key]) ? config[key] : []
		const first = Array.isArray(order[key]) ? order[key] : []
		const lead = first
			.map((name) => current.find((item) => overlayItemName(item) === name))
			.filter((item) => item !== undefined)
		config[key] = [...lead, ...current.filter((item) => !lead.includes(item))]
	}
	const out = { ...page, config }
	const own = overlay.page || {}
	for (const key of PAGE_KEYS) {
		if (own[key] === null) {
			delete out[key]
		} else if (own[key] !== undefined) {
			out[key] = own[key]
		}
	}
	if (overlay.slots && typeof overlay.slots === 'object') {
		// A `custom` widget resolves through the page's own top-level `slots`
		// map, so a page that gains one needs its slot beside it.
		out.slots = { ...(page.slots || {}), ...overlay.slots }
	}
	return out
}

/**
 * The name an overlay addresses a list item by.
 *
 * Header actions and widgets carry an `id`, columns a `key`, quick filters
 * only a `label`, and a column may be a bare string. The first of those that
 * exists is the name.
 *
 * @param {unknown} item A list item from a page config.
 * @return {string|undefined} Its name, or undefined when it has none.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
export function overlayItemName(item) {
	if (typeof item === 'string') {
		return item
	}
	return item?.id ?? item?.key ?? item?.label
}

/**
 * The theming values the simple profile's nav placeholders read.
 *
 * The brand block wants the emblem (the shield of the workplace boards), not
 * the whole wordmark: thematiq exposes the active set's emblem as
 * `nldesign.logos.emblem`. Without one, `@theming.emblem|@theming.logo` falls
 * back to Nextcloud's own logo.
 *
 * @param {object|null} capabilities `getCapabilities()`.
 * @return {object} Nextcloud's theming block plus `emblem`, '' when the set
 *   ships none.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-006-the-simple-navigation-carries-the-brand-of-the-instance-and-one-primary-action
 */
export function navTheming(capabilities) {
	const theming = capabilities?.theming ?? {}
	const emblem = capabilities?.nldesign?.logos?.emblem
	return { ...theming, emblem: typeof emblem === 'string' ? emblem : '' }
}

/** The prefix of a `nav` value the instance's theming capabilities answer. */
const THEMING_PLACEHOLDER = '@theming.'

/**
 * Resolve the `nav` block of a profile: `@theming.<key>` strings become the
 * instance's own theming values, one level deep (`brand.caption`,
 * `primaryAction.label`), so no school is written into the app.
 *
 * A value may list fallbacks with `|` (`@theming.emblem|@theming.logo`): the
 * first one the instance answers wins.
 *
 * A placeholder the capabilities do not answer resolves to an empty string,
 * which CnAppNav reads as "nothing to draw" for that field. The profile is
 * not the place to guess an instance's name.
 *
 * @param {object} nav The profile's `nav` block.
 * @param {object|null} theming The theming capabilities (`name`, `logo`, ...).
 * @return {object} A new nav block with every placeholder resolved.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-006-the-simple-navigation-carries-the-brand-of-the-instance-and-one-primary-action
 */
export function resolveNavPlaceholders(nav, theming) {
	const resolveOne = (placeholder) => {
		const key = placeholder.slice(THEMING_PLACEHOLDER.length)
		const answer =
			theming && typeof theming === 'object' ? theming[key] : undefined
		return typeof answer === 'string' ? answer : ''
	}
	const resolveValue = (value) => {
		if (typeof value !== 'string' || !value.startsWith(THEMING_PLACEHOLDER)) {
			return value
		}
		// `@theming.emblem|@theming.logo`: the first placeholder the instance
		// answers wins, so a set with an emblem shows it and one without falls
		// back to its wordmark. None answered: empty, never a guess.
		return (
			value
				.split('|')
				.map((part) => part.trim())
				.filter((part) => part.startsWith(THEMING_PLACEHOLDER))
				.map(resolveOne)
				.find((answer) => answer !== '') ?? ''
		)
	}
	const out = {}
	for (const [key, value] of Object.entries(nav || {})) {
		if (key.startsWith('_')) {
			// A note in the profile file is for its reader, not for the
			// manifest schema (`nav` takes no extra keys).
			continue
		}
		out[key] =
			value && typeof value === 'object' && !Array.isArray(value)
				? Object.fromEntries(
						Object.entries(value).map(([inner, innerValue]) => [
							inner,
							resolveValue(innerValue),
						]),
					)
				: resolveValue(value)
	}
	return out
}

/**
 * The `nav` block a reader gets: placeholders resolved, and the primary
 * action kept only for a reader its `visibleIf` passes for. The gate itself
 * never reaches the library (the manifest schema has no word for it).
 *
 * Without an evaluator a gated action is dropped: a pupil shown a teacher's
 * button is the mistake this guards against, so unknown means no.
 *
 * @param {object} nav The profile's `nav` block.
 * @param {object} runtime The manifest runtime (`user`, `workspace`).
 * @param {(predicate: object, runtime: object) => boolean} [passes] The
 *   library's `passesContextPredicates`.
 * @param {object|null} theming The theming capabilities.
 * @return {object} The nav block for this reader.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-006-the-simple-navigation-carries-the-brand-of-the-instance-and-one-primary-action
 */
export function resolveProfileNav(nav, runtime, passes, theming) {
	const resolved = resolveNavPlaceholders(nav, theming)
	const action = resolved.primaryAction
	if (action && typeof action === 'object' && 'visibleIf' in action) {
		const { visibleIf, ...rest } = action
		const shown =
			typeof passes === 'function' && passes(visibleIf, runtime || {})
		if (shown) {
			resolved.primaryAction = rest
		} else {
			delete resolved.primaryAction
		}
	}
	return resolved
}

/**
 * Build the manifest for one structure profile.
 *
 * `buildManifest` is passed in rather than imported, so this module stays free
 * of the library barrel and a spec can hand it the real implementation.
 *
 * A profile file without `menu` and `pages` (the full one) goes through
 * unchanged: the result is exactly `buildManifest(base, fragments, layout)`.
 *
 * An overlay that names a page the manifest does not have is skipped and
 * reported. It is a mistake in the profile file, and inventing the page here
 * would hide it.
 *
 * @param {(base: object, fragments: Array<object>, layout: object) => object} buildManifest
 *   The library's `buildManifest`.
 * @param {object} base The bundled manifest.
 * @param {Array<object>} fragments The `manifest.d` fragments, in order.
 * @param {object} profileFile The profile's layout file.
 * @param {(predicate: object, runtime: object) => boolean} [passes] The
 *   library's `passesContextPredicates`. An overlay with a `when` is applied
 *   only when it passes for `base.runtime`. Without an evaluator such an
 *   overlay is skipped: the page then stays what it was, which is the safe side.
 * @param {object} [context] `{ theming }`: the instance's theming
 *   capabilities, for the placeholders a profile's `nav` block may carry.
 * @return {object} The built manifest.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
export function buildProfiledManifest(
	buildManifest,
	base,
	fragments,
	profileFile,
	passes,
	context = {},
) {
	const file = profileFile || {}
	const layout = {}
	for (const key of LAYOUT_KEYS) {
		if (file[key] !== undefined) {
			layout[key] = file[key]
		}
	}

	const profileMenu = Array.isArray(file.menu) ? file.menu : []
	const profiledBase =
		profileMenu.length > 0
			? {
					...base,
					// Copies, because buildManifest merges into the entries it is
					// given and the profile file is a shared module object.
					menu: [
						...profileMenu.map((entry) => ({ ...entry })),
						...(base.menu || []),
					],
				}
			: base

	const builtPages = buildManifest(profiledBase, fragments, layout)
	const built =
		file.nav && typeof file.nav === 'object'
			? {
					...builtPages,
					nav: {
						...(builtPages.nav || {}),
						...resolveProfileNav(
							file.nav,
							base.runtime,
							passes,
							context.theming ?? null,
						),
					},
				}
			: builtPages

	const withDefaults = applyPageDefaults(built, file.pageDefaults)
	const overlays = Array.isArray(file.pages) ? file.pages : []
	if (overlays.length === 0) {
		return withDefaults
	}
	const pages = [...(withDefaults.pages || [])]
	for (const overlay of overlays) {
		const at = pages.findIndex((page) => page.id === overlay?.id)
		if (at === -1) {
			// eslint-disable-next-line no-console
			console.warn(
				'[learniq] structureProfile: page overlay names a page the manifest does not have; skipped.',
				{ page: overlay?.id },
			)
			continue
		}
		if (
			overlay.when !== undefined
			&& !(typeof passes === 'function' && passes(overlay.when, base.runtime))
		) {
			continue
		}
		pages[at] = applyPageOverlay(pages[at], overlay)
	}
	return { ...withDefaults, pages }
}

/**
 * Config defaults per page type, from the profile file's `pageDefaults`
 * (`{ "<page type>": { "<config key>": value } }`). A page of that type gets
 * each key it does not set itself; a page that sets the key keeps its own
 * value. The full profile uses this to hold back a look a newer library
 * turns on by default (nextcloud-vue 2.62.0 gave every index table header a
 * sort and filter control, `config.headerFilters`), so that profile renders
 * as it did before. A file without `pageDefaults` returns `built` itself.
 *
 * @param {object} built The built manifest.
 * @param {object|undefined} defaults The profile file's `pageDefaults`.
 * @return {object} The manifest with the defaults filled in.
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
export function applyPageDefaults(built, defaults) {
	if (!defaults || typeof defaults !== 'object' || Object.keys(defaults).length === 0) {
		return built
	}
	const pages = (built.pages || []).map((page) => {
		const forType = defaults[page.type]
		if (!forType || typeof forType !== 'object') {
			return page
		}
		const config = { ...(page.config || {}) }
		for (const [key, value] of Object.entries(forType)) {
			if (config[key] === undefined) {
				config[key] = value
			}
		}
		return { ...page, config }
	})
	return { ...built, pages }
}
