<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 MaterialAttribution: the source line and the attribution sentence of a
 Material from an open source, as LqLesmateriaal draws them. Shown on every
 Material card and in the learner view; renders nothing for an own file.

 @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
-->
<template>
	<div v-if="parts.fromSource" class="material-attribution" data-testid="material-attribution">
		<span class="material-attribution__source">{{ sourceLine }}</span>
		<span class="material-attribution__sentence">{{ sentence }}</span>
		<span v-if="notAllowed" class="material-attribution__blocked" role="note">
			{{ t('learniq', 'Not allowed in this course: the licence forbids commercial use.') }}
		</span>
	</div>
</template>

<script>
import { attributionParts, licenceTerms } from '../../services/openMaterial.js'

export default {
	name: 'MaterialAttribution',

	props: {
		/** The Material. */
		material: { type: Object, required: true },
		/** Whether the course is commercial and the Material is NC. */
		notAllowed: { type: Boolean, default: false },
	},

	computed: {
		/**
		 * @return {object} makers, licence, collection, fromSource.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
		 */
		parts() {
			return attributionParts(this.material)
		},

		/**
		 * @return {string} Such as "From Edurep · Wikiwijs Maken".
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
		 */
		sourceLine() {
			return [this.t('learniq', 'From Edurep'), this.parts.collection].filter(Boolean).join(' · ')
		},

		/**
		 * @return {string} The attribution sentence.
		 * @spec openspec/specs/open-learning-material/spec.md#scenario-a-linked-wikiwijs-arrangement-stores-its-attribution
		 */
		sentence() {
			if (this.parts.licence === '') {
				return this.t('learniq', '{makers}, own terms. Link only: learniq does not copy or play it.', { makers: this.parts.makers || this.parts.collection })
			}
			const base = this.t('learniq', 'Attribution: {makers}, {licence}, via {collection}.', this.parts)
			return [base, ...this.licenceNotes()].join(' ')
		},
	},

	methods: {
		/**
		 * @return {Array<string>} What the licence asks beyond attribution.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
		 */
		licenceNotes() {
			const terms = licenceTerms(this.material.license)
			const notes = []
			if (terms.sa) {
				notes.push(this.t('learniq', 'Share a changed version under the same licence.'))
			}
			if (terms.nc) {
				notes.push(this.t('learniq', 'Not for paid courses.'))
			}
			return notes
		},
	},
}
</script>

<style scoped>
.material-attribution {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.material-attribution__source {
	font-weight: 600;
}

.material-attribution__sentence {
	color: var(--color-text-maxcontrast);
}

.material-attribution__blocked {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}
</style>
