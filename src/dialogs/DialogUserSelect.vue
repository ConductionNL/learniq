<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 DialogUserSelect: pick one or several Nextcloud accounts by name (teachers,
 pupils, colleagues) in a board dialog. Searches the core autocomplete and
 hands back user ids, which is what learniq stores.
 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<div class="dialog-user-select">
		<NcSelect
			:modelValue="selected"
			:options="options"
			:multiple="multiple"
			:filterable="false"
			:loading="searching"
			:disabled="disabled"
			:getOptionLabel="(user) => user.label || user.id"
			:inputLabel="inputLabel"
			:placeholder="placeholder || t('learniq', 'Type a name')"
			@search="search"
			@update:modelValue="pick" />
		<span v-if="helperText" class="dialog-user-select__help">{{ helperText }}</span>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'

export default {
	name: 'DialogUserSelect',

	components: { NcSelect },

	props: {
		/** The chosen user id, or ids with `multiple`. */
		modelValue: { type: [String, Array], default: '' },
		/** Pick several accounts. */
		multiple: { type: Boolean, default: false },
		/** The field's label. */
		inputLabel: { type: String, required: true },
		/** The muted line under the field. */
		helperText: { type: String, default: '' },
		/** The text in the empty field. */
		placeholder: { type: String, default: '' },
		/** Block the field while the dialog saves. */
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	data() {
		return { options: [], known: {}, searching: false }
	},

	computed: {
		/**
		 * @return {object|object[]|null} The chosen accounts as options.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		selected() {
			const option = (id) => ({ id, label: this.known[id] || id })
			if (this.multiple) {
				return (Array.isArray(this.modelValue) ? this.modelValue : []).map(option)
			}
			return this.modelValue ? option(this.modelValue) : null
		},
	},

	/**
	 * Offer a first page of accounts before anything is typed.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
	 */
	created() {
		this.search('')
	},

	methods: {
		/**
		 * Search accounts through the core autocomplete.
		 *
		 * @param {string} query The typed text.
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async search(query) {
			this.searching = true
			try {
				const { data } = await axios.get(generateOcsUrl('core/autocomplete/get'), {
					params: { search: query || '', itemType: ' ', itemId: ' ', 'shareTypes[]': 0, limit: 20 },
				})
				this.options = (data?.ocs?.data ?? []).map((user) => ({ id: user.id, label: user.label || user.id }))
				for (const user of this.options) {
					this.known[user.id] = user.label
				}
			} catch {
				this.options = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * Hand back the chosen ids.
		 *
		 * @param {object|object[]|null} value The chosen option or options.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		pick(value) {
			if (this.multiple) {
				this.$emit('update:modelValue', (value ?? []).map((user) => user.id))
				return
			}
			this.$emit('update:modelValue', value?.id ?? '')
		},
	},
}
</script>

<style scoped>
.dialog-user-select {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.dialog-user-select__help {
	color: var(--color-text-maxcontrast);
}
</style>
