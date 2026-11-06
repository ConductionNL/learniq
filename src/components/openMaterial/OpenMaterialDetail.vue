<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 OpenMaterialDetail: one result on LqOpenLesmateriaalDetail. The facts of the
 record, the licence in plain words, and what adding does (a link or a copy in
 Nextcloud) with the attribution the card will show, before the teacher
 confirms.

 @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
-->
<template>
	<div class="open-material-detail" data-testid="open-material-detail">
		<div class="open-material-detail__head">
			<a href="#" class="open-material-detail__back" @click.prevent="$emit('back')">
				{{ t('learniq', 'Back to the results') }}
			</a>
			<h3 class="open-material-detail__title">
				{{ record.title }}
			</h3>
			<span class="open-material-detail__meta">{{ via }}</span>
		</div>
		<div class="open-material-detail__columns">
			<div class="open-material-detail__about">
				<p v-if="record.description">
					{{ record.description }}
				</p>
				<dl class="open-material-detail__facts">
					<template v-for="fact in facts" :key="fact.label">
						<dt>{{ fact.label }}</dt>
						<dd>{{ fact.value }}</dd>
					</template>
				</dl>
				<a :href="record.url" target="_blank" rel="noopener noreferrer">{{ t('learniq', 'View at the source') }}</a>
			</div>
			<div class="open-material-detail__decide">
				<section class="open-material-detail__box" :class="{ 'open-material-detail__box--caution': caution }">
					<h4>{{ licenceTitle }}</h4>
					<ul>
						<li v-for="rule in rules" :key="rule">
							{{ rule }}
						</li>
					</ul>
					<a v-if="fullTextUrl" :href="fullTextUrl" target="_blank" rel="noopener noreferrer">
						{{ t('learniq', 'The full licence text on creativecommons.org') }}
					</a>
				</section>
				<section class="open-material-detail__box">
					<h4>{{ addTitle }}</h4>
					<p>{{ addText }}</p>
					<p v-if="mode !== 'none'">
						{{ t('learniq', 'Attribution on the card: {attribution}', { attribution }) }}
					</p>
					<NcNoteCard v-if="record.decision && record.decision.nonCommercial" type="warning">
						{{ t('learniq', 'Learniq marks this material as not allowed when it is used in a paid course.') }}
					</NcNoteCard>
					<div v-if="mode !== 'none'" class="open-material-detail__add">
						<NcButton variant="primary" :disabled="busy" data-testid="open-material-detail-add" @click="$emit('add', record)">
							{{ mode === 'copy' ? t('learniq', 'Add as a copy') : t('learniq', 'Add as a link') }}
						</NcButton>
						<span v-if="targetLabel">{{ t('learniq', 'To {target}', { target: targetLabel }) }}</span>
					</div>
				</section>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { addMode, collectionName, formatName, levelName, licenceLabel, licenceTerms, licenceUrl } from '../../services/openMaterial.js'

export default {
	name: 'OpenMaterialDetail',

	components: { NcButton, NcNoteCard },

	props: {
		/** The record with its server decision. */
		record: { type: Object, required: true },
		/** The lesson or course it is added to, as words. */
		targetLabel: { type: String, default: '' },
		/** Whether an add is in progress. */
		busy: { type: Boolean, default: false },
	},

	emits: ['add', 'back'],

	computed: {
		/**
		 * @return {string} link, copy or none.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
		 */
		mode() {
			return addMode(this.record)
		},

		/**
		 * @return {string} The collection, via the source.
		 * @spec exclude presentation only
		 */
		via() {
			return this.t('learniq', '{collection}, via Edurep', { collection: collectionName(this.record.collection) })
		},

		/**
		 * @return {Array<{label: string, value: string}>} The record's facts.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		facts() {
			return [
				{ label: this.t('learniq', 'Makers'), value: (this.record.authors || []).join(', ') },
				{ label: this.t('learniq', 'Collection'), value: collectionName(this.record.collection) },
				{ label: this.t('learniq', 'Level'), value: (this.record.levels || []).map(levelName).join(', ') },
				{ label: this.t('learniq', 'Material type'), value: formatName(this.record.materialType) },
				{ label: this.t('learniq', 'Language'), value: this.record.language || '' },
				{ label: this.t('learniq', 'Published'), value: this.record.published || '' },
				{ label: this.t('learniq', 'Edurep id'), value: this.record.sourceId },
			].filter((fact) => fact.value)
		},

		/**
		 * @return {object} The licence's terms.
		 * @spec exclude presentation only
		 */
		terms() {
			return licenceTerms(this.record.license)
		},

		/**
		 * @return {boolean} Whether the licence box needs care (NC, ND or no licence).
		 * @spec exclude presentation only
		 */
		caution() {
			return !this.terms.open || this.terms.nc || this.terms.nd
		},

		/**
		 * @return {string} The licence box heading.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		licenceTitle() {
			const label = licenceLabel(this.record.license)
			return label ? this.t('learniq', 'What {licence} means', { licence: label }) : this.t('learniq', 'No open licence')
		},

		/**
		 * The licence in plain words, one line per term.
		 *
		 * @return {Array<string>} The lines.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		rules() {
			if (!this.terms.open) {
				return this.closedRules()
			}
			if (this.terms.free) {
				return [this.t('learniq', 'Free of rights: use, copy and change it without conditions.'), this.t('learniq', 'Naming the maker is still good practice. Learniq does that for you.')]
			}
			return this.openRules()
		},

		/**
		 * @return {string} The licence's full text address.
		 * @spec exclude presentation only
		 */
		fullTextUrl() {
			return licenceUrl(this.record.license)
		},

		/**
		 * @return {string} What adding does, as a heading.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		addTitle() {
			return {
				copy: this.t('learniq', 'Adding makes a copy in Nextcloud'),
				link: this.t('learniq', 'Adding makes a link'),
			}[this.mode] || this.t('learniq', 'This material cannot be added')
		},

		/**
		 * @return {string} What adding does, in a sentence, by the server's reason.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
		 */
		addText() {
			return {
				'open-licence': this.t('learniq', 'The file is open access and the licence allows copying. Learniq puts a copy in your Learniq folder. You may change it; the source and makers stay with the material.'),
				'no-derivatives': this.t('learniq', 'The licence forbids changes, so learniq copies nothing. The learner opens the material at the source.'),
				'restricted-access': this.t('learniq', 'The file is only available at the source, so learniq links to it.'),
				'link-allowed-collection': this.t('learniq', 'The material plays at the source. Learniq does not play it itself.'),
				'copy-not-offered': this.t('learniq', 'The licence allows a copy, but learniq cannot fetch files from Edurep yet, so it adds a link. The learner opens the material at the source.'),
			}[this.record.decision?.reason] || this.defaultAddText()
		},

		/**
		 * @return {string} The attribution sentence the card will show.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
		 */
		attribution() {
			const licence = licenceLabel(this.record.license) || this.t('learniq', 'own terms')
			return [(this.record.authors || []).join(', '), licence, this.t('learniq', 'via {collection}', { collection: collectionName(this.record.collection) })].filter(Boolean).join(', ') + '.'
		},
	},

	methods: {
		/**
		 * @return {Array<string>} The lines for a CC BY licence.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		openRules() {
			const lines = [this.terms.nc ? this.t('learniq', 'Not in a paid course or training. NC stands for non-commercial.') : this.t('learniq', 'You may use this in any lesson, also in a paid course.')]
			lines.push(this.terms.nd ? this.t('learniq', 'Do not change it. Share it only as it is.') : this.t('learniq', 'You may copy, shorten and change it.'))
			if (this.terms.sa) {
				lines.push(this.t('learniq', 'Share a changed version under the same licence.'))
			}
			lines.push(this.t('learniq', 'Always name the maker and the licence. Learniq does that for you.'))
			return lines
		},

		/**
		 * @return {Array<string>} The lines for material without an open licence.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-schooltv-is-link-only
		 */
		closedRules() {
			const lines = [this.t('learniq', 'This material has no open licence.'), this.t('learniq', 'You can open it at the source.')]
			if (this.record.collection === 'Schooltv') {
				lines.push(this.t('learniq', 'Schooltv allows its player only for private use, so learniq links to schooltv.nl.'))
			}
			return lines
		},

		/**
		 * @return {string} The sentence for a web page, or for a refusal.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
		 */
		defaultAddText() {
			if (this.mode === 'none') {
				return this.t('learniq', 'It has no open licence. Open it at the source.')
			}
			return this.t('learniq', 'This is a web page. Learniq copies nothing: the learner opens the material at the source. If the maker changes it, you see the new version.')
		},
	},
}
</script>

<style scoped>
.open-material-detail {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.open-material-detail__head {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.open-material-detail__title {
	margin: 0;
	font-size: 20px;
	font-weight: 700;
}

.open-material-detail__meta {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.open-material-detail__columns {
	display: flex;
	flex-wrap: wrap;
	gap: 20px;
}

.open-material-detail__about {
	display: flex;
	flex: 1 1 420px;
	flex-direction: column;
	gap: 12px;
}

.open-material-detail__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 6px 16px;
	margin: 0;
}

.open-material-detail__facts dt {
	color: var(--color-text-maxcontrast);
}

.open-material-detail__facts dd {
	margin: 0;
}

.open-material-detail__decide {
	display: flex;
	flex: 1 1 380px;
	flex-direction: column;
	gap: 14px;
}

.open-material-detail__box {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.open-material-detail__box--caution {
	border-color: var(--color-warning);
}

.open-material-detail__box h4 {
	margin: 0;
	font-size: 16px;
	font-weight: 700;
}

.open-material-detail__box ul {
	margin: 0;
	padding-inline-start: 20px;
}

.open-material-detail__add {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	align-items: center;
}
</style>
