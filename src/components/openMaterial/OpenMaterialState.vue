<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 OpenMaterialState: the three states of the search panel on
 LqOpenLesmateriaalStaten. Not searched yet (suggestions from the course),
 nothing found (fewer words, filters off, switched-off collections on), and
 the source not answering (retry, own files keep working).

 @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
-->
<template>
	<CnEmptyContent :name="heading" :description="text" :error="state === 'down'" role="status" data-testid="open-material-state">
		<template #icon>
			<component :is="icon" :size="24" />
		</template>
		<template #action>
			<div class="open-material-state__actions">
				<NcButton
v-for="action in actions"
					:key="action.id + action.value"
					:variant="action.variant"
					@click="$emit('action', action)">
					{{ action.label }}
				</NcButton>
			</div>
			<p class="open-material-state__foot">
				{{ foot }}
			</p>
		</template>
	</CnEmptyContent>
</template>

<script>
import { CnEmptyContent } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'

export default {
	name: 'OpenMaterialState',

	components: { CnEmptyContent, NcButton },

	props: {
		/** idle, empty or down. */
		state: { type: String, required: true },
		/** The words searched for. */
		query: { type: String, default: '' },
		/** Search words suggested from the course. */
		suggestions: { type: Array, default: () => [] },
	},

	emits: ['action'],

	computed: {
		/**
		 * @return {object} The icon.
		 * @spec exclude presentation only
		 */
		icon() {
			return this.state === 'down' ? AlertOutline : Magnify
		},

		/**
		 * @return {string} The heading.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		heading() {
			return {
				idle: this.t('learniq', 'Search for a topic from your lesson'),
				empty: this.t('learniq', 'Nothing found for "{query}"', { query: this.query }),
				down: this.t('learniq', 'Edurep does not answer'),
			}[this.state]
		},

		/**
		 * @return {string} The explanation.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		text() {
			return {
				idle: this.t('learniq', 'You search Edurep from Kennisnet: Wikiwijs, Schooltv, SURFsharekit and more. Your school\'s level is already on. Start with a suggestion from this course.'),
				empty: this.t('learniq', 'Try fewer words, or switch a filter off.'),
				down: this.t('learniq', 'We got no answer within 10 seconds. That lies with Edurep or the connection, not with your search. Material you already added keeps working.'),
			}[this.state]
		},

		/**
		 * @return {Array<{id: string, value: string, label: string, variant: string}>} The buttons.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		actions() {
			if (this.state === 'idle') {
				return this.suggestions.map((word) => ({ id: 'search', value: word, label: word, variant: 'secondary' }))
			}
			if (this.state === 'down') {
				return [{ id: 'retry', value: '', label: this.t('learniq', 'Try again'), variant: 'primary' }, { id: 'own-files', value: '', label: this.t('learniq', 'Search your own files'), variant: 'secondary' }]
			}
			return this.emptyActions()
		},

		/**
		 * @return {string} The line under the buttons.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		foot() {
			return {
				idle: this.t('learniq', 'You can only add material with an open licence. You do see other material, with a link to the source.'),
				empty: this.t('learniq', 'Edurep searches the title, description and keywords of the material, not the text itself.'),
				down: this.t('learniq', 'Does this keep happening? Your administrator sees the status of Edurep under Connections.'),
			}[this.state]
		},
	},

	methods: {
		/**
		 * @return {Array<object>} The buttons when nothing was found.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
		 */
		emptyActions() {
			const first = this.query.trim().split(/\s+/)[0] || ''
			const actions = [
				{ id: 'filters-off', value: '', label: this.t('learniq', 'All filters off'), variant: 'secondary' },
				{ id: 'collections-on', value: '', label: this.t('learniq', 'Include KlasCement'), variant: 'secondary' },
			]
			if (first !== '' && first !== this.query.trim()) {
				actions.unshift({ id: 'search', value: first, label: this.t('learniq', 'Search for "{query}"', { query: first }), variant: 'primary' })
			}
			return actions
		},
	},
}
</script>

<style scoped>
.open-material-state__actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: center;
	gap: 8px;
}

.open-material-state__foot {
	margin: 12px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}
</style>
