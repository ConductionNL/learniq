<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 OpenMaterialResultCard: one search result on LqOpenLesmateriaal. Title,
 licence badge, description, makers and collection, and the add button the
 server's licence decision allows (link, copy or none).

 @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
-->
<template>
	<article class="open-material-result" data-testid="open-material-result">
		<span class="open-material-result__icon" :class="'open-material-result__icon--' + iconKind" aria-hidden="true">
			<component :is="icon" :size="18" />
		</span>
		<div class="open-material-result__body">
			<div class="open-material-result__head">
				<h3 class="open-material-result__title">
					<a href="#" @click.prevent="$emit('details', record)">{{ record.title }}</a>
				</h3>
				<span class="open-material-result__badge" :class="'open-material-result__badge--' + tone">{{ badge }}</span>
			</div>
			<p v-if="record.description" class="open-material-result__desc">
				{{ record.description }}
			</p>
			<span class="open-material-result__meta">{{ meta }}</span>
			<div class="open-material-result__actions">
				<NcButton
v-if="mode !== 'none'"
					variant="primary"
					:disabled="busy"
					data-testid="open-material-add"
					@click="$emit('add', record)">
					<template #icon>
						<Plus :size="16" />
					</template>
					{{ addLabel }}
				</NcButton>
				<NcButton variant="secondary" @click="$emit('details', record)">
					{{ t('learniq', 'Details and licence') }}
				</NcButton>
				<a :href="record.url" target="_blank" rel="noopener noreferrer" class="open-material-result__source">
					{{ t('learniq', 'View at the source') }}
				</a>
				<span v-if="noteText" class="open-material-result__note">{{ noteText }}</span>
			</div>
		</div>
	</article>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Video from 'vue-material-design-icons/Video.vue'
import Web from 'vue-material-design-icons/Web.vue'
import { addMode, collectionName, formatName, levelName, licenceLabel, licenceTone, resultNote } from '../../services/openMaterial.js'

export default {
	name: 'OpenMaterialResultCard',

	components: { NcButton, Plus },

	props: {
		/** A search result with its server decision. */
		record: { type: Object, required: true },
		/** Whether an add is in progress. */
		busy: { type: Boolean, default: false },
	},

	emits: ['add', 'details'],

	computed: {
		/**
		 * @return {string} link, copy or none.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
		 */
		mode() {
			return addMode(this.record)
		},

		/**
		 * @return {string} The tone class.
		 * @spec exclude presentation only
		 */
		tone() {
			return this.record.collection === 'Schooltv' ? 'own' : licenceTone(this.record.license)
		},

		/**
		 * @return {string} The badge text.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		badge() {
			if (this.record.collection === 'Schooltv') {
				return this.t('learniq', 'Schooltv terms')
			}
			return licenceLabel(this.record.license) || this.t('learniq', 'No open licence')
		},

		/**
		 * @return {string} web, video or doc.
		 * @spec exclude presentation only
		 */
		iconKind() {
			const type = formatName(this.record.materialType)
			if (type === 'video') {
				return 'video'
			}
			return ['webpage', 'Wikiwijs-arrangement'].includes(type) ? 'web' : 'doc'
		},

		/**
		 * @return {object} The icon component.
		 * @spec exclude presentation only
		 */
		icon() {
			return { video: Video, web: Web, doc: FileDocumentOutline }[this.iconKind]
		},

		/**
		 * @return {string} Makers, collection, levels, type and year.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		meta() {
			const year = String(this.record.published || '').slice(0, 4)
			const type = formatName(this.record.materialType)
			const parts = [(this.record.authors || []).join(', '), collectionName(this.record.collection), (this.record.levels || []).map(levelName).join(', '), this.typeLabel(type), year]
			return parts.filter(Boolean).join(' · ')
		},

		/**
		 * @return {string} The add button text.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
		 */
		addLabel() {
			return this.mode === 'copy' ? this.t('learniq', 'Add as a copy') : this.t('learniq', 'Add as a link')
		},

		/**
		 * @return {string} The note beside the buttons.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-schooltv-is-link-only
		 */
		noteText() {
			return {
				schooltv: this.t('learniq', 'Plays on schooltv.nl'),
				'nd-nc': this.t('learniq', 'Do not change, not for paid courses'),
				nd: this.t('learniq', 'Do not change'),
				nc: this.t('learniq', 'Not for paid courses'),
				'source-only': this.t('learniq', 'Only at the source'),
			}[resultNote(this.record)] || ''
		},
	},

	methods: {
		/**
		 * @param {string} type The readable type from formatName.
		 * @return {string} The type, translated where it is a word.
		 * @spec exclude presentation only
		 */
		typeLabel(type) {
			return { webpage: this.t('learniq', 'webpage'), video: this.t('learniq', 'video') }[type] || type
		},
	},
}
</script>

<style scoped>
.open-material-result {
	display: flex;
	gap: 14px;
	align-items: flex-start;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.open-material-result__icon {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	border-radius: 50%;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.open-material-result__body {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	gap: 5px;
	min-width: 0;
}

.open-material-result__head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: flex-start;
	gap: 6px 12px;
}

.open-material-result__title {
	flex: 1 1 320px;
	min-width: 0;
	margin: 0;
	font-size: 16px;
	font-weight: 700;
	line-height: 1.3;
}

.open-material-result__badge {
	flex: none;
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.open-material-result__badge--open {
	background: var(--color-success-hover, var(--color-background-dark));
	color: var(--color-success-text, var(--color-main-text));
}

.open-material-result__badge--nc,
.open-material-result__badge--nd {
	background: var(--color-warning-hover, var(--color-background-dark));
	color: var(--color-warning-text, var(--color-main-text));
}

.open-material-result__badge--own {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.open-material-result__desc {
	margin: 0;
	font-size: 14px;
	line-height: 1.45;
}

.open-material-result__meta,
.open-material-result__note {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.open-material-result__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
	padding-top: 4px;
}

.open-material-result__source {
	font-size: 13px;
	font-weight: 600;
}
</style>
