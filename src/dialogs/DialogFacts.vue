<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 DialogFacts: the label and value lines a board dialog opens with (who, what,
 since when), so the reader knows what they decide on before the fields.
 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<dl class="dialog-facts">
		<div v-for="fact in shown" :key="fact.label" class="dialog-facts__row">
			<dt class="dialog-facts__label">
				{{ fact.label }}
			</dt>
			<dd class="dialog-facts__value">
				{{ fact.value }}
			</dd>
		</div>
	</dl>
</template>

<script>
export default {
	name: 'DialogFacts',

	props: {
		/** The lines, `{ label, value }`; a line without a value is left out. */
		facts: { type: Array, required: true },
	},

	computed: {
		/**
		 * @return {object[]} The lines that have a value.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		shown() {
			return this.facts.filter((fact) => fact.value !== '' && fact.value !== null && fact.value !== undefined)
		},
	},
}
</script>

<style scoped>
.dialog-facts {
	display: grid;
	gap: 6px;
	margin: 0 0 16px;
	padding: 12px 14px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.dialog-facts__row {
	display: grid;
	grid-template-columns: minmax(120px, 1fr) 3fr;
	gap: 12px;
}

.dialog-facts__label {
	color: var(--color-text-maxcontrast);
}

.dialog-facts__value {
	margin: 0;
}
</style>
