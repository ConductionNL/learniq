<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 CourseOpenMaterials: the Lesmateriaal tab of a course (learniq/LqLesmateriaal).
 Every Material of the course as a card with its badge (link, copy in
 Nextcloud, own file), its source line and attribution, and the search for
 open learning material. In a paid or corporate course an NC Material is
 marked as not allowed.

 @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
-->
<template>
	<section class="course-open-materials" data-testid="course-open-materials">
		<div class="course-open-materials__head">
			<p class="course-open-materials__intro">
				{{ t('learniq', 'Everything the lessons of this course use. Own files live in Nextcloud. Open learning material comes from Edurep and always carries its licence and its makers.') }}
			</p>
			<NcButton v-if="panel.enabled" variant="primary" data-testid="course-open-materials-search" @click="searching = true">
				{{ t('learniq', 'Search open learning material') }}
			</NcButton>
		</div>
		<div class="course-open-materials__chips" role="group" :aria-label="t('learniq', 'Show')">
			<NcButton
v-for="chip in chips"
				:key="chip.id"
				:variant="filter === chip.id ? 'primary' : 'tertiary'"
				:aria-pressed="filter === chip.id ? 'true' : 'false'"
				@click="filter = chip.id">
				{{ chip.label }} ({{ chip.count }})
			</NcButton>
		</div>
		<p v-if="loading">
			{{ t('learniq', 'Loading the materials…') }}
		</p>
		<div v-else class="course-open-materials__grid">
			<article v-for="material in shown" :key="material.id" class="course-open-materials__card">
				<span class="course-open-materials__badge">{{ badge(material) }}</span>
				<h3 class="course-open-materials__title">
					{{ material.title }}
				</h3>
				<MaterialAttribution :material="material" :notAllowed="panel.commercialCourse === true && material.nonCommercial === true" />
				<a v-if="material.sourceUrl" :href="material.sourceUrl" target="_blank" rel="noopener noreferrer">
					{{ t('learniq', 'Open at the source') }}
				</a>
			</article>
		</div>
		<p class="course-open-materials__foot">
			{{ t('learniq', 'The licence and the makers belong to the material and stay, also when you change the title. A link opens the source. A copy lives in Nextcloud and you may change it when the licence allows it.') }}
		</p>
		<OpenMaterialSearchModal
v-if="searching"
			:target="{ courseId: objectId }"
			@added="onAdded"
			@ownFiles="searching = false"
			@close="searching = false" />
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import OpenMaterialSearchModal from '../../modals/OpenMaterialSearchModal.vue'
import MaterialAttribution from './MaterialAttribution.vue'
import { OPEN_MATERIAL_API } from '../../services/openMaterial.js'

export default {
	name: 'CourseOpenMaterials',

	components: { MaterialAttribution, NcButton, OpenMaterialSearchModal },

	props: {
		/** The course UUID, from the detail page. */
		objectId: { type: String, required: true },
	},

	data() {
		return {
			materials: [],
			panel: {},
			filter: 'all',
			loading: true,
			searching: false,
		}
	},

	computed: {
		/**
		 * @return {Array<{id: string, label: string, count: number}>} The filter chips.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
		 */
		chips() {
			const count = (id) => this.materials.filter((m) => this.matches(m, id)).length
			return [
				{ id: 'all', label: this.t('learniq', 'Everything') },
				{ id: 'own', label: this.t('learniq', 'Own files') },
				{ id: 'source', label: this.t('learniq', 'From Edurep') },
				{ id: 'nc', label: this.t('learniq', 'Non-commercial') },
			].map((chip) => ({ ...chip, count: count(chip.id) }))
		},

		/**
		 * @return {Array<object>} The Materials the chosen chip shows.
		 * @spec exclude presentation only
		 */
		shown() {
			return this.materials.filter((m) => this.matches(m, this.filter))
		},
	},

	/**
	 * @return {Promise<void>}
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
	 */
	async mounted() {
		try {
			const [panel, list] = await Promise.all([
				axios.get(generateUrl(OPEN_MATERIAL_API + '/panel'), { params: { courseId: this.objectId } }),
				axios.get(generateUrl('/apps/openregister/api/objects/learniq/material'), { params: { 'filters[courseId]': this.objectId, _limit: 200 } }),
			])
			this.panel = panel.data || {}
			this.materials = list.data?.results ?? list.data?.objects ?? []
		} catch {
			this.materials = []
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * @param {object} material A Material.
		 * @param {string} chip all, own, source or nc.
		 * @return {boolean} Whether the chip shows it.
		 * @spec exclude presentation only
		 */
		matches(material, chip) {
			const fromSource = Boolean(material.source) && material.source !== 'own'
			return { all: true, own: !fromSource, source: fromSource, nc: material.nonCommercial === true }[chip]
		},

		/**
		 * @param {object} material A Material.
		 * @return {string} Link, copy in Nextcloud, or own file.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
		 */
		badge(material) {
			if (!material.source || material.source === 'own') {
				return this.t('learniq', 'Own file')
			}
			return material.fileRef ? this.t('learniq', 'Copy in Nextcloud') : this.t('learniq', 'Linked')
		},

		/**
		 * @param {object} material The new Material.
		 * @return {void}
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
		 */
		onAdded(material) {
			this.materials.push(material)
			this.searching = false
		},
	},
}
</script>

<style scoped>
.course-open-materials {
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.course-open-materials__head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: flex-start;
	gap: 12px;
}

.course-open-materials__intro,
.course-open-materials__foot {
	margin: 0;
	max-width: 720px;
	color: var(--color-text-maxcontrast);
}

.course-open-materials__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.course-open-materials__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 12px;
}

.course-open-materials__card {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.course-open-materials__badge {
	align-self: flex-start;
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.course-open-materials__title {
	margin: 0;
	font-size: 16px;
	font-weight: 700;
}
</style>
