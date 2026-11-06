<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 OpenMaterialSearchModal: "Zoek open lesmateriaal" (learniq/LqOpenLesmateriaal,
 LqOpenLesmateriaalDetail, LqOpenLesmateriaalStaten). Opened from the media
 block in the lesson editor and from a course's Lesmateriaal tab. It searches
 Edurep through learniq and integriq, shows facets with counts and the result
 cards, a result's licence in plain words, and adds a result as a Material.
 The server decides link, copy or refused; this panel only shows it.

 @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'Search open learning material')"
		:eyebrow="t('learniq', 'Learning material')"
		:subtitle="t('learniq', 'From Edurep by Kennisnet: Wikiwijs, Schooltv, SURFsharekit and more. What you add joins the materials of this course, with its licence and makers.')"
		width="browse"
		size="large"
		data-testid="open-material-search"
		@closing="$emit('close')">
		<OpenMaterialDetail
v-if="detail"
			:record="detail"
			:targetLabel="targetLabel"
			:busy="busy"
			@back="detail = null"
			@add="add" />
		<div v-else class="open-material-search">
			<form role="search" class="open-material-search__bar" @submit.prevent="search(1)">
				<NcTextField
v-model="query"
					type="search"
					:label="t('learniq', 'Search in Edurep')"
					:placeholder="t('learniq', 'Search by topic, title or maker')"
					data-testid="open-material-query" />
				<NcButton type="submit" variant="primary" :disabled="loading">
					{{ t('learniq', 'Search') }}
				</NcButton>
				<span class="open-material-search__count">{{ countText }}</span>
			</form>
			<NcNoteCard v-if="refusal" type="error">
				{{ refusal }}
			</NcNoteCard>
			<div class="open-material-search__columns">
				<aside class="open-material-search__filters" :aria-label="t('learniq', 'Filters')">
					<fieldset v-for="group in facetGroups" :key="group.filter" class="open-material-search__facet">
						<legend>{{ group.title }}</legend>
						<NcCheckboxRadioSwitch
v-for="row in group.rows"
							:key="group.filter + row.value"
							:modelValue="isOn(group.filter, row)"
							:disabled="!row.filterable || loading"
							@update:modelValue="toggle(group.filter, row)">
							{{ row.label }} <span class="open-material-search__facet-count">{{ row.count }}</span>
						</NcCheckboxRadioSwitch>
					</fieldset>
					<p class="open-material-search__hint">
						{{ t('learniq', 'Your school profile sets the level. KlasCement (Flanders) is off by default.') }}
					</p>
				</aside>
				<div class="open-material-search__results">
					<OpenMaterialState
v-if="state !== 'results'"
						:state="state"
						:query="searched"
						:suggestions="suggestions"
						@action="onStateAction" />
					<template v-else>
						<OpenMaterialResultCard
v-for="record in records"
							:key="record.sourceId"
							:record="record"
							:busy="busy"
							@add="add"
							@details="detail = $event" />
						<CnPagination
variant="board"
							:currentPage="page"
							:totalPages="totalPages"
							:totalItems="total"
							:currentPageSize="pageSize"
							@pageChanged="search" />
					</template>
				</div>
			</div>
		</div>
	</CnDialog>
</template>

<script>
import { CnDialog, CnPagination } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcTextField } from '@nextcloud/vue'
import OpenMaterialDetail from '../components/openMaterial/OpenMaterialDetail.vue'
import OpenMaterialResultCard from '../components/openMaterial/OpenMaterialResultCard.vue'
import OpenMaterialState from '../components/openMaterial/OpenMaterialState.vue'
import { addMaterial, collectionName, facetRows, FILTER_KEYS, formatName, levelName, licenceLabel, loadPanel, searchMaterial, toggledValues } from '../services/openMaterial.js'

export default {
	name: 'OpenMaterialSearchModal',

	components: { CnDialog, CnPagination, NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcTextField, OpenMaterialDetail, OpenMaterialResultCard, OpenMaterialState },

	props: {
		/** Where a result is added: `{ lessonId }` or `{ courseId }`. */
		target: { type: Object, required: true },
		/** The target as words, such as "Les 7 · Signaalwoorden in een betoog". */
		targetLabel: { type: String, default: '' },
		/** Search words suggested from the course. */
		suggestions: { type: Array, default: () => [] },
	},

	emits: ['close', 'added', 'ownFiles'],

	data() {
		return {
			query: '',
			searched: '',
			page: 1,
			levels: [],
			excludeLicenses: [],
			excludeFormats: [],
			excludeCollections: [],
			collectionsOff: [],
			result: null,
			state: 'idle',
			loading: false,
			busy: false,
			refusal: '',
			detail: null,
		}
	},

	computed: {
		/**
		 * @return {Array<object>} The results on this page.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		records() {
			return this.result?.records || []
		},

		/**
		 * @return {number} The result count.
		 * @spec exclude presentation only
		 */
		total() {
			return Number(this.result?.total) || 0
		},

		/**
		 * @return {number} The page size the source used.
		 * @spec exclude presentation only
		 */
		pageSize() {
			return Number(this.result?.pageSize) || 10
		},

		/**
		 * @return {number} The number of pages.
		 * @spec exclude presentation only
		 */
		totalPages() {
			return Math.max(1, Math.ceil(this.total / this.pageSize))
		},

		/**
		 * @return {string} The count beside the search field.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		countText() {
			if (this.state === 'idle' || this.state === 'down') {
				return this.t('learniq', 'Edurep, about 495,000 Dutch-language materials')
			}
			return this.n('learniq', '%n result from Edurep', '%n results from Edurep', this.total)
		},

		/**
		 * @return {Array<{filter: string, title: string, rows: Array}>} The facet groups with labels.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		facetGroups() {
			const facets = this.result?.facets
			if (!facets) {
				return []
			}
			const titles = { levels: this.t('learniq', 'Level'), licenses: this.t('learniq', 'Licence'), collections: this.t('learniq', 'Collection'), formats: this.t('learniq', 'File type') }
			return Object.keys(titles).map((filter) => ({
				filter,
				title: titles[filter],
				rows: facetRows(facets, filter, this[FILTER_KEYS[filter]]).map((row) => ({ ...row, label: this.facetLabel(filter, row.value) })),
			})).filter((group) => group.rows.length > 0)
		},
	},

	/**
	 * @return {Promise<void>}
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-sources-are-connections-an-administrator-switches
	 */
	async mounted() {
		try {
			const panel = await loadPanel(axios, generateUrl)
			this.levels = [...(panel.defaultLevels || [])]
			this.collectionsOff = panel.collectionsOff || []
			this.excludeCollections = [...this.collectionsOff]
		} catch {
			this.state = 'down'
		}
	},

	methods: {
		/**
		 * Search one page. Turning a page of the same search keeps the counts it already has.
		 *
		 * @param {number} page The page.
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-learning-material/spec.md#scenario-a-teacher-searches-from-the-lesson-editor
		 */
		async search(page = 1) {
			this.loading = true
			this.refusal = ''
			this.page = page
			const keepFacets = page > 1 && this.query.trim() === this.searched
			this.searched = this.query.trim()
			try {
				const found = await searchMaterial(axios, generateUrl, { ...this.$data, facets: !keepFacets })
				this.result = keepFacets ? { ...found, facets: this.result?.facets } : found
				this.state = this.total > 0 ? 'results' : 'empty'
			} catch {
				this.state = 'down'
			} finally {
				this.loading = false
			}
		},

		/**
		 * Add a record; the server decides link or copy, and may refuse.
		 *
		 * @param {object} record The result.
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
		 */
		async add(record) {
			this.busy = true
			this.refusal = ''
			try {
				const material = await addMaterial(axios, generateUrl, { ...this.target, sourceId: record.sourceId, mode: record.decision?.mode || '' })
				showSuccess(this.t('learniq', 'Added to the materials: {title}', { title: record.title }))
				this.$emit('added', material)
			} catch (error) {
				this.refusal = error?.response?.data?.reason || this.t('learniq', 'The material could not be added.')
				this.detail = null
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {string} filter levels, licenses, collections or formats.
		 * @param {object} row The facet row.
		 * @return {boolean} Whether the box is ticked.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		isOn(filter, row) {
			const listed = row.values.some((value) => this[FILTER_KEYS[filter]].includes(value))
			return filter === 'levels' ? listed : !listed
		},

		/**
		 * Tick or untick a facet row, then search again from page 1.
		 *
		 * @param {string} filter levels, licenses, collections or formats.
		 * @param {object} row The facet row.
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		toggle(filter, row) {
			this[FILTER_KEYS[filter]] = toggledValues(this[FILTER_KEYS[filter]], row.values)
			return this.search(1)
		},

		/**
		 * @param {{id: string, value: string}} action The state button.
		 * @return {Promise<void>|void}
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		onStateAction(action) {
			if (action.id === 'own-files') {
				return this.$emit('ownFiles')
			}
			if (action.id === 'search') {
				this.query = action.value
			}
			if (action.id === 'filters-off') {
				Object.assign(this, { levels: [], excludeLicenses: [], excludeFormats: [], excludeCollections: [...this.collectionsOff] })
			}
			if (action.id === 'collections-on') {
				this.excludeCollections = []
			}
			return this.search(1)
		},

		/**
		 * @param {string} filter The facet's filter.
		 * @param {string} value The facet value.
		 * @return {string} The row's label.
		 * @spec exclude presentation only
		 */
		facetLabel(filter, value) {
			if (filter === 'levels') {
				return this.levelLabel(value)
			}
			if (filter === 'licenses') {
				return licenceLabel(value) || this.t('learniq', 'No open licence')
			}
			if (filter === 'collections') {
				return value === 'KlasCement' ? this.t('learniq', 'KlasCement (Flanders)') : collectionName(value)
			}
			return { webpage: this.t('learniq', 'Webpage'), video: this.t('learniq', 'Video') }[formatName(value)] || formatName(value)
		},

		/**
		 * @param {string} code The NL-LOM context code.
		 * @return {string} The level's name.
		 * @spec exclude presentation only
		 */
		levelLabel(code) {
			return {
				PO: this.t('learniq', 'Primary education'),
				VO: this.t('learniq', 'Secondary education'),
				SO: this.t('learniq', 'Special education'),
				BVE: 'mbo',
				HBO: 'hbo',
				WO: 'wo',
			}[code] || levelName(code)
		},
	},
}
</script>

<style scoped>
.open-material-search {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.open-material-search__bar {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
}

.open-material-search__bar > :first-child {
	flex: 1 1 320px;
	max-width: 520px;
}

.open-material-search__count {
	margin-inline-start: auto;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.open-material-search__columns {
	display: flex;
	flex-wrap: wrap;
	gap: 20px;
	align-items: flex-start;
}

.open-material-search__filters {
	display: flex;
	flex: 0 0 236px;
	flex-direction: column;
	gap: 16px;
}

.open-material-search__facet {
	margin: 0;
	padding: 0;
	border: 0;
}

.open-material-search__facet legend {
	margin: 0 0 4px;
	font-size: 13px;
	font-weight: 700;
}

.open-material-search__facet-count,
.open-material-search__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.open-material-search__hint {
	margin: 0;
	line-height: 1.45;
}

.open-material-search__results {
	display: flex;
	flex: 1 1 560px;
	flex-direction: column;
	gap: 12px;
	min-width: 0;
}
</style>
