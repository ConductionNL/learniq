// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Open learning material: the search panel's calls to learniq's API and the
 * pure helpers that turn a source record or a Material into what the cards
 * show (licence badge, tone, collection name, add button). Translation stays
 * in the components; these helpers return codes and names only.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */

/** learniq's open material API, relative to generateUrl. */
export const OPEN_MATERIAL_API = '/apps/learniq/api/open-material'

/** The facets the source returns, by the filter they feed. */
export const FACETS = {
	levels: 'lom.educational.context',
	licenses: 'lom.rights.copyrightandotherrestrictions',
	collections: 'meta.repository.id',
	formats: 'lom.technical.format',
}

/** The search state list each filter group keeps: ticked levels, unticked everything else. */
export const FILTER_KEYS = {
	levels: 'levels',
	licenses: 'excludeLicenses',
	collections: 'excludeCollections',
	formats: 'excludeFormats',
}

/** Readable collection names; anything else shows as the source names it. */
const COLLECTION_NAMES = {
	wikiwijsmaken: 'Wikiwijs Maken',
	WikiwijsDelen: 'Wikiwijs Delen',
	WikiwijsToetsen: 'Wikiwijs Toetsen',
	Schooltv: 'Schooltv',
	surfsharekit: 'SURFsharekit',
	KlasCement: 'KlasCement',
	artik: 'Artik',
	metaplus: 'Metaplus',
	wur_staff: 'WUR',
	krantindeklas: 'Nieuws in de klas',
	webkwestie: 'WebKwestie',
}

/** Readable file types by MIME type or prefix. */
const FORMAT_NAMES = [
	['application/x-wikiwijs-arrangement', 'Wikiwijs-arrangement'],
	['text/html', 'webpage'],
	['application/pdf', 'pdf'],
	['application/msword', 'Word'],
	['application/vnd.openxmlformats-officedocument.wordprocessingml', 'Word'],
	['application/vnd.ms-powerpoint', 'PowerPoint'],
	['application/vnd.openxmlformats-officedocument.presentationml', 'PowerPoint'],
	['application/zip', 'Zip'],
	['video/', 'video'],
]

/**
 * The badge text of a normalised licence code, or '' when it is not open.
 *
 * @param {string} code The code, such as CC-BY-NC-ND-3.0.
 * @return {string} Such as "CC BY-NC-ND 3.0", "CC0 1.0" or "PDM".
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
export function licenceLabel(code) {
	const text = String(code || '')
	const match = /^CC-BY((?:-[A-Z]+)*)-(\d\.\d)$/.exec(text)
	if (match) {
		return 'CC BY' + match[1] + ' ' + match[2]
	}
	if (text === 'CC0-1.0') {
		return 'CC0 1.0'
	}
	return text === 'PDM' ? 'PDM' : ''
}

/**
 * The full licence text on creativecommons.org, or '' when it is not open.
 *
 * @param {string} code The normalised licence code.
 * @return {string} The address.
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
export function licenceUrl(code) {
	const match = /^CC-BY((?:-[A-Z]+)*)-(\d\.\d)$/.exec(String(code || ''))
	if (match) {
		return 'https://creativecommons.org/licenses/by' + match[1].toLowerCase() + '/' + match[2] + '/'
	}
	return {
		'CC0-1.0': 'https://creativecommons.org/publicdomain/zero/1.0/',
		PDM: 'https://creativecommons.org/publicdomain/mark/1.0/',
	}[code] || ''
}

/**
 * Which terms a licence carries: by, sa, nc, nd, or free for CC0 and PDM.
 *
 * @param {string} code The normalised licence code.
 * @return {{open: boolean, free: boolean, sa: boolean, nc: boolean, nd: boolean}} The terms.
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
export function licenceTerms(code) {
	const label = licenceLabel(code)
	return {
		open: label !== '',
		free: code === 'CC0-1.0' || code === 'PDM',
		sa: label.includes('-SA'),
		nc: label.includes('-NC'),
		nd: label.includes('-ND'),
	}
}

/**
 * The badge tone: open, nc (non-commercial), nd (no derivatives) or none.
 *
 * @param {string} code The normalised licence code.
 * @return {string} The tone.
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
export function licenceTone(code) {
	const label = licenceLabel(code)
	if (label === '') {
		return 'none'
	}
	if (label.includes('-ND')) {
		return 'nd'
	}
	return label.includes('-NC') ? 'nc' : 'open'
}

/**
 * The licence code a facet value stands for, or '' for rights without a licence.
 *
 * @param {string} value The facet value, such as cc-by-nc-nd-30.
 * @return {string} Such as CC-BY-NC-ND-3.0.
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
export function licenceFromFacet(value) {
	const match = /^cc-by((?:-[a-z]+)*)-(\d)(\d)$/.exec(String(value || ''))
	if (match) {
		return 'CC-BY' + match[1].toUpperCase() + '-' + match[2] + '.' + match[3]
	}
	return { 'cc0-10': 'CC0-1.0', 'pdm-10': 'PDM' }[value] || ''
}

/**
 * A collection's readable name.
 *
 * @param {string} id The collection id, such as wikiwijsmaken.
 * @return {string} The name.
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
export function collectionName(id) {
	return COLLECTION_NAMES[id] || String(id || '')
}

/**
 * A file type's readable name, or the MIME type itself.
 *
 * @param {string} mime The MIME type.
 * @return {string} The name; "webpage" and "video" are translated by the caller.
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
export function formatName(mime) {
	const text = String(mime || '').toLowerCase()
	const found = FORMAT_NAMES.find(([prefix]) => text.startsWith(prefix))
	return found ? found[1] : text
}

/** Short level names as Dutch schools write them, by NL-LOM context code. */
const LEVEL_NAMES = { PO: 'po', VO: 'vo', SO: 'so', BVE: 'mbo', HBO: 'hbo', WO: 'wo', HO: 'ho' }

/**
 * A level's short name.
 *
 * @param {string} code The NL-LOM context code, such as BVE.
 * @return {string} Such as mbo.
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
export function levelName(code) {
	return LEVEL_NAMES[code] || String(code || '').toLowerCase()
}

/**
 * What the add button does for a record: link, copy, or nothing.
 *
 * @param {object} record A search result with its server decision.
 * @return {string} link, copy or none.
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */
export function addMode(record) {
	const mode = record?.decision?.mode
	return mode === 'link' || mode === 'copy' ? mode : 'none'
}

/**
 * The note beside a result's buttons, as a code the panel translates.
 *
 * @param {object} record A search result with its server decision.
 * @return {string} schooltv, nd-nc, nd, nc, source-only or ''.
 * @spec openspec/specs/open-learning-material/spec.md#requirement-schooltv-is-link-only
 */
export function resultNote(record) {
	if (record?.collection === 'Schooltv') {
		return 'schooltv'
	}
	if (addMode(record) === 'none') {
		return 'source-only'
	}
	return licenceNote(record)
}

/**
 * The licence part of a result's note: no derivatives and/or non-commercial.
 *
 * @param {object} record A search result with its server decision.
 * @return {string} nd-nc, nd, nc or ''.
 */
function licenceNote(record) {
	const tone = licenceTone(record?.license)
	const nc = record?.decision?.nonCommercial === true
	if (tone === 'nd') {
		return nc ? 'nd-nc' : 'nd'
	}
	return nc ? 'nc' : ''
}

/**
 * Merge the facet entries that read the same, keeping every source value behind the row.
 *
 * @param {Array<{value: string, count: number}>} entries The facet entries.
 * @param {function(string): string} nameOf The row a value belongs to.
 * @return {Array<{value: string, values: Array<string>, count: number, filterable: boolean}>} The rows.
 */
function mergedRows(entries, nameOf) {
	const rows = new Map()
	entries.forEach(({ value, count }) => {
		const name = nameOf(value)
		const row = rows.get(name) || { value, values: [], count: 0, filterable: value !== '' }
		row.count += count
		row.values = row.values.includes(value) ? row.values : [...row.values, value]
		rows.set(name, row)
	})
	return [...rows.values()]
}

/**
 * Facet rows for the filter panel: value, the source values behind it, count and whether it can filter.
 * A chosen value the source no longer counts keeps a row, so it can be changed back.
 *
 * @param {object} facets The search result's facets.
 * @param {string} filter levels, licenses, collections or formats.
 * @param {Array<string>} chosen The group's list in the search state.
 * @return {Array<{value: string, values: Array<string>, count: number, filterable: boolean}>} The rows.
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
export function facetRows(facets, filter, chosen = []) {
	const counted = (facets?.[FACETS[filter]] || []).map((entry) => ({
		value: filter === 'licenses' ? licenceFromFacet(entry.value) : String(entry.value),
		count: Number(entry.count) || 0,
	}))
	const missing = chosen.filter((value) => !counted.some((entry) => entry.value === value))
	const entries = [...counted, ...missing.map((value) => ({ value, count: 0 }))]
	const rows = mergedRows(entries, filter === 'formats' ? formatName : (value) => value)
	return [...rows.filter((row) => row.filterable), ...rows.filter((row) => !row.filterable)]
}

/**
 * A group's list after clicking a row: its values leave the list, or join it when none is in.
 *
 * @param {Array<string>} list The group's list in the search state.
 * @param {Array<string>} values The row's source values.
 * @return {Array<string>} The new list.
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
export function toggledValues(list, values) {
	if (values.some((value) => list.includes(value))) {
		return list.filter((value) => !values.includes(value))
	}
	return [...list, ...values]
}

/**
 * The query parameters of one search.
 *
 * @param {object} state query, page, the chosen filters and facets, false to leave the counts out.
 * @return {object} The parameters for GET /search.
 * @spec openspec/specs/open-learning-material/spec.md#scenario-a-teacher-searches-from-the-lesson-editor
 */
export function searchParams(state) {
	return {
		query: String(state.query || '').trim(),
		page: state.page || 1,
		...chosenFilterLists(state),
		includeCollectionsOff: 'true',
		facets: state.facets !== false,
	}
}

/** The filter lists a search sends, in parameter order. */
const FILTER_LISTS = ['levels', 'excludeLicenses', 'excludeFormats', 'excludeCollections']

/**
 * Each filter list of the search state, an empty list when it is unset.
 *
 * @param {object} state The search state.
 * @return {object} The lists by parameter name.
 */
function chosenFilterLists(state) {
	return Object.fromEntries(FILTER_LISTS.map((key) => [key, state[key] || []]))
}

/**
 * The attribution of a Material: makers, licence label and collection name.
 *
 * @param {object} material The Material.
 * @return {{makers: string, licence: string, collection: string, fromSource: boolean}} The parts.
 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
 */
export function attributionParts(material) {
	return {
		makers: (material?.authors || []).join(', '),
		licence: licenceLabel(material?.license),
		collection: collectionName(material?.sourceCollection),
		fromSource: Boolean(material?.source) && material.source !== 'own',
	}
}

/**
 * Ask learniq for the panel settings.
 *
 * @param {{get: function(string, object=): Promise<object>}} http An axios-like client.
 * @param {function(string): string} url generateUrl.
 * @return {Promise<object>} enabled, available, defaultLevels, collectionsOff, linkCollections.
 * @spec openspec/specs/open-learning-material/spec.md#scenario-edurep-is-switched-off
 */
export async function loadPanel(http, url) {
	const { data } = await http.get(url(OPEN_MATERIAL_API + '/panel'))
	return data || {}
}

/**
 * Search the source through learniq.
 *
 * @param {{get: function(string, object=): Promise<object>}} http An axios-like client.
 * @param {function(string): string} url generateUrl.
 * @param {object} state The search state.
 * @return {Promise<object>} total, page, pageSize, records, facets.
 * @spec openspec/specs/open-learning-material/spec.md#scenario-a-teacher-searches-from-the-lesson-editor
 */
export async function searchMaterial(http, url, state) {
	const { data } = await http.get(url(OPEN_MATERIAL_API + '/search'), { params: searchParams(state) })
	return data || {}
}

/**
 * Add one record to a lesson or course; the server decides link or copy.
 *
 * @param {{post: function(string, object): Promise<object>}} http An axios-like client.
 * @param {function(string): string} url generateUrl.
 * @param {object} request sourceId, lessonId or courseId, and mode.
 * @return {Promise<object>} The new Material.
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */
export async function addMaterial(http, url, request) {
	const { data } = await http.post(url(OPEN_MATERIAL_API + '/materials'), request)
	return data
}
