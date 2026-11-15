// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The open learning material components (Edurep), through each component's
// own computed properties and methods: what a search result card shows, what
// the detail view says a licence allows and how adding works, the
// attribution under an added material, and the course's material list with
// its filter chips.
//
// @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instanceOf, loadSfc } from './support/loadSfc.mjs'

const http = { calls: [], answers: [] }
const icons = {
	Video: { name: 'Video' },
	Web: { name: 'Web' },
	FileDocumentOutline: { name: 'FileDocumentOutline' },
}

const card = await loadSfc('src/components/openMaterial/OpenMaterialResultCard.vue', {
	'vue-material-design-icons/Video.vue': { default: icons.Video },
	'vue-material-design-icons/Web.vue': { default: icons.Web },
	'vue-material-design-icons/FileDocumentOutline.vue': { default: icons.FileDocumentOutline },
})
const detail = await loadSfc('src/components/openMaterial/OpenMaterialDetail.vue')
const attribution = await loadSfc('src/components/openMaterial/MaterialAttribution.vue')
const course = await loadSfc('src/components/openMaterial/CourseOpenMaterials.vue', {
	'@nextcloud/axios': {
		default: {
			get: async (url, config) => {
				http.calls.push([url, config])
				const next = http.answers.shift() ?? { data: {} }
				if (next instanceof Error) throw next
				return next
			},
		},
	},
	'@nextcloud/router': { generateUrl: (path) => `/nc${path}` },
})

const RECORD = {
	sourceId: 'edurep:123',
	collection: 'wikiwijsmaken',
	authors: ['A. Maker', 'B. Maker'],
	levels: ['VO', 'BVE'],
	materialType: 'text/html',
	language: 'nl',
	published: '2024-03-01',
	license: 'CC-BY-SA-4.0',
	decision: { mode: 'link', reason: 'copy-not-offered' },
}

// --- Result card ---------------------------------------------------------------

test('a result card shows its licence, meta line and a link button', () => {
	const vm = instanceOf(card, { record: RECORD })
	assert.equal(vm.badge, 'CC BY-SA 4.0')
	assert.equal(vm.tone, 'open')
	assert.equal(vm.meta, 'A. Maker, B. Maker · Wikiwijs Maken · vo, mbo · webpage · 2024')
	assert.equal(vm.iconKind, 'web')
	assert.equal(vm.icon, icons.Web)
	assert.equal(vm.addLabel, 'Add as a link')
	assert.equal(vm.noteText, '')
})

test('a copyable video card offers a copy and shows the video icon', () => {
	const vm = instanceOf(card, { record: { ...RECORD, materialType: 'video/mp4', decision: { mode: 'copy' }, published: '' } })
	assert.equal(vm.iconKind, 'video')
	assert.equal(vm.icon, icons.Video)
	assert.equal(vm.addLabel, 'Add as a copy')
	assert.equal(vm.meta, 'A. Maker, B. Maker · Wikiwijs Maken · vo, mbo · video')
})

test('a document card shows the document icon and its own type', () => {
	const vm = instanceOf(card, { record: { license: 'CC-BY-NC-ND-4.0', materialType: 'application/pdf', decision: { mode: 'link', nonCommercial: true } } })
	assert.equal(vm.iconKind, 'doc')
	assert.equal(vm.icon, icons.FileDocumentOutline)
	assert.equal(vm.tone, 'nd')
	assert.equal(vm.meta, 'pdf')
	assert.equal(vm.noteText, 'Do not change, not for paid courses')
})

test('a card notes each licence restriction', () => {
	const note = (record) => instanceOf(card, { record }).noteText
	assert.equal(note({ license: 'CC-BY-ND-4.0', decision: { mode: 'link' } }), 'Do not change')
	assert.equal(note({ license: 'CC-BY-NC-4.0', decision: { mode: 'copy', nonCommercial: true } }), 'Not for paid courses')
	assert.equal(note({ license: 'CC-BY-4.0', decision: {} }), 'Only at the source')
})

test('a Schooltv card speaks of Schooltv terms and plays at schooltv.nl', () => {
	const vm = instanceOf(card, { record: { collection: 'Schooltv', license: 'CC-BY-4.0', decision: { mode: 'link' } } })
	assert.equal(vm.badge, 'Schooltv terms')
	assert.equal(vm.tone, 'own')
	assert.equal(vm.noteText, 'Plays on schooltv.nl')
})

test('a card without an open licence says so', () => {
	const vm = instanceOf(card, { record: { license: 'Copyrighted', decision: { mode: 'link' } } })
	assert.equal(vm.badge, 'No open licence')
	assert.equal(vm.tone, 'none')
	assert.equal(vm.typeLabel('Word'), 'Word')
})

// --- Detail ------------------------------------------------------------------------

test('the detail lists the facts it has and says where the material comes from', () => {
	const vm = instanceOf(detail, { record: RECORD })
	assert.equal(vm.via, 'Wikiwijs Maken, via Edurep')
	assert.deepEqual(vm.facts, [
		{ label: 'Makers', value: 'A. Maker, B. Maker' },
		{ label: 'Collection', value: 'Wikiwijs Maken' },
		{ label: 'Level', value: 'vo, mbo' },
		{ label: 'Material type', value: 'webpage' },
		{ label: 'Language', value: 'nl' },
		{ label: 'Published', value: '2024-03-01' },
		{ label: 'Edurep id', value: 'edurep:123' },
	])
	const bare = instanceOf(detail, { record: { sourceId: 'x', collection: 'KlasCement' } })
	assert.deepEqual(bare.facts.map((f) => f.label), ['Collection', 'Edurep id'])
})

test('a share-alike licence lists its rules and links to the full text', () => {
	const vm = instanceOf(detail, { record: RECORD })
	assert.equal(vm.licenceTitle, 'What CC BY-SA 4.0 means')
	assert.equal(vm.fullTextUrl, 'https://creativecommons.org/licenses/by-sa/4.0/')
	assert.equal(vm.caution, false)
	assert.deepEqual(vm.rules, [
		'You may use this in any lesson, also in a paid course.',
		'You may copy, shorten and change it.',
		'Share a changed version under the same licence.',
		'Always name the maker and the licence. Learniq does that for you.',
	])
	assert.equal(vm.attribution, 'A. Maker, B. Maker, CC BY-SA 4.0, via Wikiwijs Maken.')
})

test('a non-commercial no-derivatives licence is a caution with its own rules', () => {
	const vm = instanceOf(detail, { record: { ...RECORD, license: 'CC-BY-NC-ND-4.0' } })
	assert.equal(vm.caution, true)
	assert.deepEqual(vm.rules, [
		'Not in a paid course or training. NC stands for non-commercial.',
		'Do not change it. Share it only as it is.',
		'Always name the maker and the licence. Learniq does that for you.',
	])
})

test('a free licence has no conditions', () => {
	const vm = instanceOf(detail, { record: { ...RECORD, license: 'CC0-1.0' } })
	assert.equal(vm.caution, false)
	assert.equal(vm.rules.length, 2)
	assert.match(vm.rules[0], /^Free of rights/)
})

test('a closed licence is a caution, and Schooltv says why it links', () => {
	const closed = instanceOf(detail, { record: { ...RECORD, license: '', authors: [] } })
	assert.equal(closed.caution, true)
	assert.equal(closed.licenceTitle, 'No open licence')
	assert.deepEqual(closed.rules, ['This material has no open licence.', 'You can open it at the source.'])
	assert.equal(closed.attribution, 'own terms, via Wikiwijs Maken.')
	assert.equal(closed.fullTextUrl, '')
	const tv = instanceOf(detail, { record: { ...RECORD, license: '', collection: 'Schooltv' } })
	assert.equal(tv.rules.length, 3)
	assert.match(tv.rules[2], /^Schooltv allows its player only for private use/)
})

test('the add section says what adding does for each mode and reason', () => {
	const view = (decision) => instanceOf(detail, { record: { ...RECORD, decision } })
	assert.equal(view({ mode: 'copy', reason: 'open-licence' }).addTitle, 'Adding makes a copy in Nextcloud')
	assert.match(view({ mode: 'copy', reason: 'open-licence' }).addText, /^The file is open access/)
	assert.equal(view({ mode: 'link', reason: 'no-derivatives' }).addTitle, 'Adding makes a link')
	assert.match(view({ mode: 'link', reason: 'no-derivatives' }).addText, /^The licence forbids changes/)
	assert.match(view({ mode: 'link', reason: 'restricted-access' }).addText, /^The file is only available at the source/)
	assert.match(view({ mode: 'link', reason: 'link-allowed-collection' }).addText, /^The material plays at the source/)
	assert.match(view({ mode: 'link', reason: 'copy-not-offered' }).addText, /cannot fetch files from Edurep yet/)
	assert.match(view({ mode: 'link' }).addText, /^This is a web page\./)
	const none = view({})
	assert.equal(none.mode, 'none')
	assert.equal(none.addTitle, 'This material cannot be added')
	assert.equal(none.addText, 'It has no open licence. Open it at the source.')
})

// --- Attribution -------------------------------------------------------------------

test('an attribution names the makers, licence and collection, with its conditions', () => {
	const vm = instanceOf(attribution, { material: { authors: ['A. Maker'], license: 'CC-BY-NC-SA-4.0', sourceCollection: 'KlasCement', source: 'edurep' } })
	assert.equal(vm.sourceLine, 'From Edurep · KlasCement')
	assert.equal(
		vm.sentence,
		'Attribution: A. Maker, CC BY-NC-SA 4.0, via KlasCement. Share a changed version under the same licence. Not for paid courses.',
	)
	const plain = instanceOf(attribution, { material: { authors: ['A. Maker'], license: 'CC-BY-4.0', sourceCollection: 'artik' } })
	assert.equal(plain.sentence, 'Attribution: A. Maker, CC BY 4.0, via Artik.')
})

test('material on its own terms is a link only, named by its maker or collection', () => {
	const vm = instanceOf(attribution, { material: { authors: ['A. Maker'], license: '', sourceCollection: 'Schooltv' } })
	assert.equal(vm.sentence, 'A. Maker, own terms. Link only: learniq does not copy or play it.')
	const anonymous = instanceOf(attribution, { material: { license: 'Copyrighted', sourceCollection: 'Schooltv' } })
	assert.equal(anonymous.sentence, 'Schooltv, own terms. Link only: learniq does not copy or play it.')
	assert.equal(instanceOf(attribution, { material: {} }).sourceLine, 'From Edurep')
})

// --- Course materials ---------------------------------------------------------------

const MATERIALS = [
	{ id: 'm1', source: 'own' },
	{ id: 'm2' },
	{ id: 'm3', source: 'edurep', fileRef: 'files/m3.pdf' },
	{ id: 'm4', source: 'edurep', nonCommercial: true },
]

test('the course list loads its panel and materials, and counts each chip', async () => {
	http.calls = []
	http.answers = [{ data: { enabled: true } }, { data: { results: structuredClone(MATERIALS) } }]
	const vm = instanceOf(course, { objectId: 'c1' })
	await course.mounted.call(vm)
	assert.deepEqual(http.calls, [
		['/nc/apps/learniq/api/open-material/panel', { params: { courseId: 'c1' } }],
		['/nc/apps/openregister/api/objects/learniq/material', { params: { courseId: 'c1', _limit: 200 } }],
	])
	assert.deepEqual(vm.panel, { enabled: true })
	assert.equal(vm.loading, false)
	assert.deepEqual(vm.chips.map((c) => [c.id, c.count]), [['all', 4], ['own', 2], ['source', 2], ['nc', 1]])
	vm.filter = 'source'
	assert.deepEqual(vm.shown.map((m) => m.id), ['m3', 'm4'])
	vm.filter = 'nc'
	assert.deepEqual(vm.shown.map((m) => m.id), ['m4'])
})

test('the course list reads objects, and a failure as no materials', async () => {
	http.answers = [{ data: null }, { data: { objects: [{ id: 'x' }] } }]
	const vm = instanceOf(course, { objectId: 'c1' })
	await course.mounted.call(vm)
	assert.deepEqual(vm.panel, {})
	assert.deepEqual(vm.materials, [{ id: 'x' }])

	http.answers = [new Error('500')]
	const failed = instanceOf(course, { objectId: 'c1' })
	await course.mounted.call(failed)
	assert.deepEqual(failed.materials, [])
	assert.equal(failed.loading, false)
})

test('a material is badged as own file, copy or link, and an added one closes the search', () => {
	const vm = instanceOf(course, { objectId: 'c1' })
	assert.equal(vm.badge(MATERIALS[0]), 'Own file')
	assert.equal(vm.badge(MATERIALS[1]), 'Own file')
	assert.equal(vm.badge(MATERIALS[2]), 'Copy in Nextcloud')
	assert.equal(vm.badge(MATERIALS[3]), 'Linked')
	vm.searching = true
	vm.onAdded({ id: 'm5', source: 'edurep' })
	assert.deepEqual(vm.materials, [{ id: 'm5', source: 'edurep' }])
	assert.equal(vm.searching, false)
})
