// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Open learning material: the licence badge, tone and note a result card
// shows, the facet rows, and the requests the panel sends.
//
// @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	addMaterial,
	addMode,
	attributionParts,
	collectionName,
	facetRows,
	formatName,
	levelName,
	licenceFromFacet,
	licenceLabel,
	licenceTerms,
	licenceTone,
	licenceUrl,
	resultNote,
	searchMaterial,
	searchParams,
	toggledValues,
} from '../../src/services/openMaterial.js'

test('licence codes read as their badge text', () => {
	assert.equal(licenceLabel('CC-BY-4.0'), 'CC BY 4.0')
	assert.equal(licenceLabel('CC-BY-NC-ND-3.0'), 'CC BY-NC-ND 3.0')
	assert.equal(licenceLabel('CC0-1.0'), 'CC0 1.0')
	assert.equal(licenceLabel('rights-reserved'), '')
	assert.equal(licenceLabel('other'), '')
})

test('the tone separates open, NC, ND and no licence', () => {
	assert.equal(licenceTone('CC-BY-SA-4.0'), 'open')
	assert.equal(licenceTone('CC-BY-NC-4.0'), 'nc')
	assert.equal(licenceTone('CC-BY-NC-ND-3.0'), 'nd')
	assert.equal(licenceTone('unknown'), 'none')
})

test('facet values become licence codes, rights without a licence become one row', () => {
	assert.equal(licenceFromFacet('cc-by-40'), 'CC-BY-4.0')
	assert.equal(licenceFromFacet('cc-by-nc-nd-30'), 'CC-BY-NC-ND-3.0')
	assert.equal(licenceFromFacet('yes'), '')
	const rows = facetRows({ 'lom.rights.copyrightandotherrestrictions': [{ value: 'yes', count: 138 }, { value: 'cc-by-40', count: 36 }, { value: 'no', count: 2 }] }, 'licenses')
	assert.deepEqual(rows, [{ value: 'CC-BY-4.0', values: ['CC-BY-4.0'], count: 36, filterable: true }, { value: '', values: [''], count: 140, filterable: false }])
})

test('CC0 and the public domain mark are open licences, not rights without one', () => {
	assert.equal(licenceFromFacet('cc0-10'), 'CC0-1.0')
	assert.equal(licenceFromFacet('pdm-10'), 'PDM')
})

test('file types that read the same share one row and keep their source values', () => {
	const rows = facetRows({
		'lom.technical.format': [
			{ value: 'application/msword', count: 12 },
			{ value: 'application/pdf', count: 3 },
			{ value: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', count: 2 },
		],
	}, 'formats')
	assert.deepEqual(rows, [
		{ value: 'application/msword', values: ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], count: 14, filterable: true },
		{ value: 'application/pdf', values: ['application/pdf'], count: 3, filterable: true },
	])
})

test('a chosen value the source does not count keeps a row, so it can be changed back', () => {
	const facets = { 'meta.repository.id': [{ value: 'artik', count: 86 }], 'lom.educational.context': [] }
	assert.deepEqual(facetRows(facets, 'collections', ['KlasCement']).map((row) => [row.value, row.count]), [['artik', 86], ['KlasCement', 0]])
	assert.deepEqual(facetRows(facets, 'collections', ['artik']).map((row) => row.count), [86])
	assert.deepEqual(facetRows(facets, 'levels', ['BVE']), [{ value: 'BVE', values: ['BVE'], count: 0, filterable: true }])
	const word = facetRows({ 'lom.technical.format': [{ value: 'application/msword', count: 12 }] }, 'formats', ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
	assert.equal(word.length, 1)
	assert.equal(word[0].values.length, 2)
})

test('clicking a row takes all its values out of the list, or puts them in', () => {
	assert.deepEqual(toggledValues(['VO'], ['HBO']), ['VO', 'HBO'])
	assert.deepEqual(toggledValues(['VO', 'HBO'], ['VO']), ['HBO'])
	assert.deepEqual(toggledValues(['a', 'b', 'c'], ['a', 'b']), ['c'])
	assert.deepEqual(toggledValues(['a'], ['a', 'b']), [])
})

test('collections, levels and file types read as names', () => {
	assert.equal(levelName('BVE'), 'mbo')
	assert.equal(collectionName('wikiwijsmaken'), 'Wikiwijs Maken')
	assert.equal(collectionName('aeres'), 'aeres')
	assert.equal(collectionName('krantindeklas'), 'Nieuws in de klas')
	assert.equal(levelName('HO'), 'ho')
	assert.equal(formatName('application/x-Wikiwijs-Arrangement'), 'Wikiwijs-arrangement')
	assert.equal(formatName('video/H264'), 'video')
})

test('the add button and the note follow the server decision', () => {
	const nd = { license: 'CC-BY-NC-ND-3.0', decision: { mode: 'link', nonCommercial: true } }
	assert.equal(addMode(nd), 'link')
	assert.equal(resultNote(nd), 'nd-nc')
	assert.equal(resultNote({ license: 'CC-BY-NC-4.0', decision: { mode: 'copy', nonCommercial: true } }), 'nc')
	assert.equal(resultNote({ collection: 'Schooltv', license: 'rights-reserved', decision: { mode: 'link' } }), 'schooltv')
	const refused = { license: 'rights-reserved', decision: { mode: 'refused' } }
	assert.equal(addMode(refused), 'none')
	assert.equal(resultNote(refused), 'source-only')
})

test('a search sends the chosen filters and manages the excluded collections itself', async () => {
	assert.deepEqual(searchParams({ query: ' betoog ', levels: ['VO'], excludeCollections: ['KlasCement'] }), {
		query: 'betoog', page: 1, levels: ['VO'], excludeLicenses: [], excludeFormats: [], excludeCollections: ['KlasCement'], includeCollectionsOff: 'true', facets: true,
	})
	assert.equal(searchParams({ query: 'betoog', page: 2, facets: false }).facets, false)
	const calls = []
	const http = { get: async (path, options) => { calls.push([path, options]); return { data: { total: 0 } } } }
	await searchMaterial(http, (path) => '/index.php' + path, { query: 'betoog' })
	assert.equal(calls[0][0], '/index.php/apps/learniq/api/open-material/search')
	assert.equal(calls[0][1].params.query, 'betoog')
})

test('adding sends only the record id, the target and the mode', async () => {
	const sent = []
	const http = { post: async (path, body) => { sent.push([path, body]); return { data: { id: 'm-1' } } } }
	const material = await addMaterial(http, (path) => path, { sourceId: 'urn:wikiwijsmaken:194413', lessonId: 'les-7', mode: 'link' })
	assert.equal(material.id, 'm-1')
	assert.deepEqual(sent[0], ['/apps/learniq/api/open-material/materials', { sourceId: 'urn:wikiwijsmaken:194413', lessonId: 'les-7', mode: 'link' }])
})

test('the attribution names makers, licence and collection', () => {
	assert.deepEqual(attributionParts({ source: 'edurep', authors: ['Kirstin Beekman'], license: 'CC-BY-4.0', sourceCollection: 'wikiwijsmaken' }), {
		makers: 'Kirstin Beekman', licence: 'CC BY 4.0', collection: 'Wikiwijs Maken', fromSource: true,
	})
	assert.equal(attributionParts({ title: 'Eigen pdf' }).fromSource, false)
})

test('a licence links to its full text and names its terms', () => {
	assert.equal(licenceUrl('CC-BY-NC-ND-3.0'), 'https://creativecommons.org/licenses/by-nc-nd/3.0/')
	assert.equal(licenceUrl('CC0-1.0'), 'https://creativecommons.org/publicdomain/zero/1.0/')
	assert.equal(licenceUrl('rights-reserved'), '')
	assert.deepEqual(licenceTerms('CC-BY-SA-4.0'), { open: true, free: false, sa: true, nc: false, nd: false })
	assert.deepEqual(licenceTerms('PDM'), { open: true, free: true, sa: false, nc: false, nd: false })
})
