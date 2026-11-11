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
 * A list in a config-like object, or an empty list when the key holds none.
 *
 * @param {object} source The object.
 * @param {string} key The key.
 * @return {Array} The list.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function listAt(source, key) {
	return Array.isArray(source[key]) ? source[key] : []
}

/**
 * One list item with the change an overlay names it for. A bare string, or
 * an item the overlay does not name, stays as it is.
 *
 * @param {unknown} item A list item.
 * @param {object} byName The changes by item name.
 * @return {unknown} The item, changed or not.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function patchedItem(item, byName) {
	const change = byName[overlayItemName(item)]
	return change === undefined || typeof item !== 'object'
		? item
		: { ...item, ...change }
}

/**
 * Replace the named config lists by their patched items: a `null` change
 * takes the item out, an object change is merged into it.
 *
 * @param {object} config The page config being built.
 * @param {object|undefined} patch The overlay's `configPatch`.
 * @return {void}
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function patchConfig(config, patch) {
	for (const key of Object.keys(patch || {})) {
		const byName = patch[key] || {}
		config[key] = listAt(config, key)
			.filter((item) => byName[overlayItemName(item)] !== null)
			.map((item) => patchedItem(item, byName))
	}
}

/**
 * Append the overlay's items to the named config lists.
 *
 * @param {object} config The page config being built.
 * @param {object|undefined} append The overlay's `configAppend`.
 * @return {void}
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function appendConfig(config, append) {
	for (const key of Object.keys(append || {})) {
		config[key] = [...listAt(config, key), ...listAt(append, key)]
	}
}

/**
 * A list with the named items moved to the front, in the order named.
 *
 * @param {Array} current The list.
 * @param {Array<string>} names The names that lead.
 * @return {Array} The reordered list.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function leadWith(current, names) {
	const lead = names
		.map((name) => current.find((item) => overlayItemName(item) === name))
		.filter((item) => item !== undefined)
	return [...lead, ...current.filter((item) => !lead.includes(item))]
}

/**
 * Move the named items of the named config lists to the front.
 *
 * @param {object} config The page config being built.
 * @param {object|undefined} order The overlay's `configOrder`.
 * @return {void}
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function orderConfig(config, order) {
	for (const key of Object.keys(order || {})) {
		config[key] = leadWith(listAt(config, key), listAt(order, key))
	}
}

/**
 * The page config after an overlay: replace keys, patch items by name,
 * append, then order.
 *
 * @param {object} page The built page.
 * @param {object} overlay The page overlay.
 * @return {object} A new config.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function overlayConfig(page, overlay) {
	const config = { ...(page.config || {}), ...(overlay.config || {}) }
	patchConfig(config, overlay.configPatch)
	appendConfig(config, overlay.configAppend)
	orderConfig(config, overlay.configOrder)
	return config
}

/**
 * Replace the page's own keys an overlay names; a `null` takes the key out.
 *
 * @param {object} out The new page.
 * @param {object} own The overlay's `page` block.
 * @return {void}
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function applyPageKeys(out, own) {
	for (const key of PAGE_KEYS) {
		if (own[key] === null) {
			delete out[key]
		} else if (own[key] !== undefined) {
			out[key] = own[key]
		}
	}
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
	const out = { ...page, config: overlayConfig(page, overlay) }
	applyPageKeys(out, overlay.page || {})
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
 * The instance's answer to one `@theming.<key>` placeholder, '' when it has
 * none.
 *
 * @param {string} placeholder The placeholder.
 * @param {object|null} theming The theming capabilities.
 * @return {string} The answer.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-006-the-simple-navigation-carries-the-brand-of-the-instance-and-one-primary-action
 */
function themingAnswer(placeholder, theming) {
	const key = placeholder.slice(THEMING_PLACEHOLDER.length)
	const answer =
		theming && typeof theming === 'object' ? theming[key] : undefined
	return typeof answer === 'string' ? answer : ''
}

/**
 * One `nav` value with its placeholder resolved; any other value as it is.
 *
 * @param {unknown} value The value.
 * @param {object|null} theming The theming capabilities.
 * @return {unknown} The resolved value.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-006-the-simple-navigation-carries-the-brand-of-the-instance-and-one-primary-action
 */
function resolveNavValue(value, theming) {
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
			.map((part) => themingAnswer(part, theming))
			.find((answer) => answer !== '') ?? ''
	)
}

/**
 * One `nav` entry resolved: a block (`brand`, `primaryAction`) one level
 * deep, any other value as a value.
 *
 * @param {unknown} value The entry.
 * @param {object|null} theming The theming capabilities.
 * @return {unknown} The resolved entry.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-006-the-simple-navigation-carries-the-brand-of-the-instance-and-one-primary-action
 */
function resolveNavEntry(value, theming) {
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		return resolveNavValue(value, theming)
	}
	return Object.fromEntries(
		Object.entries(value).map(([inner, innerValue]) => [
			inner,
			resolveNavValue(innerValue, theming),
		]),
	)
}

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
	const out = {}
	for (const [key, value] of Object.entries(nav || {})) {
		if (key.startsWith('_')) {
			// A note in the profile file is for its reader, not for the
			// manifest schema (`nav` takes no extra keys).
			continue
		}
		out[key] = resolveNavEntry(value, theming)
	}
	return out
}

/**
 * Keep the gated primary action, without its gate, for a reader it passes
 * for; drop it for anyone else, and when there is no evaluator.
 *
 * @param {object} resolved The resolved nav block, changed in place.
 * @param {{runtime: object, passes: (((predicate: object, runtime: object) => boolean)|undefined)}} reader The reader.
 * @return {void}
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-006-the-simple-navigation-carries-the-brand-of-the-instance-and-one-primary-action
 */
function gatePrimaryAction(resolved, reader) {
	const { visibleIf, ...rest } = resolved.primaryAction
	const passes = reader.passes
	const shown =
		typeof passes === 'function' && passes(visibleIf, reader.runtime || {})
	if (shown) {
		resolved.primaryAction = rest
	} else {
		delete resolved.primaryAction
	}
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
 * @param {{runtime: object, passes: ((predicate: object, runtime: object) => boolean|undefined), theming: (object|null)}} reader
 *   The manifest runtime (`user`, `workspace`), the library's
 *   `passesContextPredicates` and the theming capabilities.
 * @return {object} The nav block for this reader.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-006-the-simple-navigation-carries-the-brand-of-the-instance-and-one-primary-action
 */
export function resolveProfileNav(nav, reader) {
	const resolved = resolveNavPlaceholders(nav, reader.theming)
	const action = resolved.primaryAction
	if (action && typeof action === 'object' && 'visibleIf' in action) {
		gatePrimaryAction(resolved, reader)
	}
	return resolved
}

/**
 * The layout keys of a profile file that `buildManifest` reads.
 *
 * @param {object} file The profile's layout file.
 * @return {object} The layout.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function profileLayout(file) {
	const layout = {}
	for (const key of LAYOUT_KEYS) {
		if (file[key] !== undefined) {
			layout[key] = file[key]
		}
	}
	return layout
}

/**
 * The base manifest with the profile's menu entries before its own. Without
 * profile entries the base itself.
 *
 * @param {object} base The bundled manifest.
 * @param {object} file The profile's layout file.
 * @return {object} The base to build.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function baseWithProfileMenu(base, file) {
	const profileMenu = listAt(file, 'menu')
	if (profileMenu.length === 0) {
		return base
	}
	return {
		...base,
		// Copies, because buildManifest merges into the entries it is
		// given and the profile file is a shared module object.
		menu: [
			...profileMenu.map((entry) => ({ ...entry })),
			...(base.menu || []),
		],
	}
}

/**
 * The reader the profile's `nav` is resolved for.
 *
 * @param {{base: object, passes: (((predicate: object, runtime: object) => boolean)|undefined), theming: (object|null)}} run The build.
 * @return {{runtime: object, passes: (((predicate: object, runtime: object) => boolean)|undefined), theming: (object|null)}} The reader.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-006-the-simple-navigation-carries-the-brand-of-the-instance-and-one-primary-action
 */
function navReader(run) {
	return {
		runtime: run.base.runtime,
		passes: run.passes,
		theming: run.theming,
	}
}

/**
 * The built manifest with the profile's `nav` merged over its own.
 *
 * @param {object} builtPages The built manifest.
 * @param {{file: object, base: object, passes: (((predicate: object, runtime: object) => boolean)|undefined), theming: (object|null)}} run The build.
 * @return {object} The manifest, or `builtPages` when the profile has no nav.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-006-the-simple-navigation-carries-the-brand-of-the-instance-and-one-primary-action
 */
function withProfileNav(builtPages, run) {
	if (!run.file.nav || typeof run.file.nav !== 'object') {
		return builtPages
	}
	return {
		...builtPages,
		nav: {
			...(builtPages.nav || {}),
			...resolveProfileNav(run.file.nav, navReader(run)),
		},
	}
}

/**
 * Where the page an overlay names sits. An overlay that names a page the
 * manifest does not have is a mistake in the profile file: it is reported.
 *
 * @param {Array<object>} pages The pages.
 * @param {object} overlay The page overlay.
 * @return {number} The index, -1 when there is no such page.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function overlaidPageIndex(pages, overlay) {
	const at = pages.findIndex((page) => page.id === overlay?.id)
	if (at === -1) {
		// eslint-disable-next-line no-console
		console.warn(
			'[learniq] structureProfile: page overlay names a page the manifest does not have; skipped.',
			{ page: overlay?.id },
		)
	}
	return at
}

/**
 * Whether an overlay applies to this reader: always without a `when`, else
 * only when the evaluator passes it for the runtime.
 *
 * @param {object} overlay The page overlay.
 * @param {{base: object, passes: (((predicate: object, runtime: object) => boolean)|undefined)}} run The build.
 * @return {boolean} True when it applies.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function overlayApplies(overlay, run) {
	const passes = run.passes
	return (
		overlay.when === undefined
		|| (typeof passes === 'function' && passes(overlay.when, run.base.runtime))
	)
}

/**
 * The manifest's pages with the profile's overlays applied, in order.
 *
 * @param {object} manifest The built manifest.
 * @param {{file: object, base: object, passes: (((predicate: object, runtime: object) => boolean)|undefined)}} run The build.
 * @return {Array<object>} The pages.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function overlaidPages(manifest, run) {
	const pages = [...(manifest.pages || [])]
	for (const overlay of listAt(run.file, 'pages')) {
		const at = overlaidPageIndex(pages, overlay)
		if (at !== -1 && overlayApplies(overlay, run)) {
			pages[at] = applyPageOverlay(pages[at], overlay)
		}
	}
	return pages
}

/**
 * The manifest with the profile's page overlays; the manifest itself when
 * the profile has none.
 *
 * @param {object} manifest The built manifest.
 * @param {{file: object, base: object, passes: (((predicate: object, runtime: object) => boolean)|undefined)}} run The build.
 * @return {object} The manifest.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function applyPageOverlays(manifest, run) {
	if (listAt(run.file, 'pages').length === 0) {
		return manifest
	}
	return { ...manifest, pages: overlaidPages(manifest, run) }
}

/**
 * The values the steps of one profiled build share.
 *
 * @param {object} build What to build from (see buildProfiledManifest).
 * @return {{file: object, base: object, passes: (((predicate: object, runtime: object) => boolean)|undefined), theming: (object|null)}} The run.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
function profileRun(build) {
	return {
		file: build.profileFile || {},
		base: build.base,
		passes: build.passes,
		theming: build.theming ?? null,
	}
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
 * @param {object} build What to build from:
 *   - `buildManifest` `(base, fragments, layout) => object`: the library's `buildManifest`;
 *   - `base`: the bundled manifest;
 *   - `fragments`: the `manifest.d` fragments, in order;
 *   - `profileFile`: the profile's layout file;
 *   - `passes` (optional) `(predicate, runtime) => boolean`: the library's
 *     `passesContextPredicates`. An overlay with a `when` is applied only when
 *     it passes for `base.runtime`. Without an evaluator such an overlay is
 *     skipped: the page then stays what it was, which is the safe side;
 *   - `theming` (optional): the instance's theming capabilities, for the
 *     placeholders a profile's `nav` block may carry.
 * @return {object} The built manifest.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-005-a-profile-may-change-a-page-and-never-add-or-remove-one
 */
export function buildProfiledManifest(build) {
	const run = profileRun(build)
	const file = run.file
	const layout = profileLayout(file)
	const profiledBase = baseWithProfileMenu(build.base, file)
	const buildManifest = build.buildManifest
	const builtPages = buildManifest(profiledBase, build.fragments, layout)
	const withDefaults = applyPageDefaults(
		withProfileNav(builtPages, run),
		file.pageDefaults,
	)
	return applyPageOverlays(withDefaults, run)
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
	if (
		!defaults
		|| typeof defaults !== 'object'
		|| Object.keys(defaults).length === 0
	) {
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
