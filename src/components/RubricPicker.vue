<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RubricPicker: a Rubric to fill in, one level per criterion, with the running
 score (boards LqInleveringNakijken, LqLlInleveren). Drawn by CnChoiceMatrix
 with one pick per row; the host keeps the selected levels with their points.

 @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
-->
<template>
	<CnChoiceMatrix
		pickPerRow
		:rows="rows"
		:modelValue="picks"
		:readOnly="disabled"
		:scoreLabel="t('learniq', 'Score: {score} / {max}', { score: '{score}', max: '{max}' })"
		:scoreMax="maxPoints || null"
		:weightTemplate="t('learniq', '(weight: {w})', { w: '{weight}' })"
		@update:modelValue="onPicks">
		<template v-if="$slots.scoreNote" #scoreNote>
			<!-- @slot scoreNote A remark beside the score. -->
			<slot name="scoreNote" />
		</template>
	</CnChoiceMatrix>
</template>

<script>
import { CnChoiceMatrix } from '@conduction/nextcloud-vue'
import { levelsFromPicks, rubricPicks, rubricRows } from '../utils/rubricMatrix.js'

export default {
	name: 'RubricPicker',

	components: { CnChoiceMatrix },

	props: {
		/** The Rubric, with `criteria`. */
		rubric: {
			type: Object,
			required: true,
		},

		/** criterionId to `{ levelId, points }`. */
		selectedLevels: {
			type: Object,
			default: () => ({}),
		},

		/** The most points; the sum of each criterion's highest level when 0. */
		maxPoints: {
			type: Number,
			default: 0,
		},

		/** Show the picks without letting them change. */
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:selectedLevels'],

	computed: {
		/** @return {Array<object>} The criteria as rows. */
		rows() {
			return rubricRows(this.rubric)
		},

		/** @return {Record<string, string>} The picked level per criterion. */
		picks() {
			return rubricPicks(this.selectedLevels)
		},
	},

	methods: {
		/**
		 * Hand the picked levels, with their points, to the host.
		 *
		 * @param {Record<string, string>} picks criterionId to levelId.
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
		 */
		onPicks(picks) {
			this.$emit('update:selectedLevels', levelsFromPicks(this.rubric, picks))
		},
	},
}
</script>
