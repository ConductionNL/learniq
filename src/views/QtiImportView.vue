<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 QtiImportView: import a QTI package into an item bank
 (route /assessments/item-banks/:itemBankId/import, learniq#947).

 Uploads the picked file to POST /api/assessment/qti-import with the item
 bank id, the only caller of QtiImportController, and shows what the import
 reported.

 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<div class="qti-import">
		<h2>{{ t('learniq', 'Import a QTI package') }}</h2>
		<form class="qti-import__form" @submit.prevent="upload">
			<label for="qti-file">{{
				t('learniq', 'QTI package (.zip or .xml)')
			}}</label>
			<input
				id="qti-file"
				type="file"
				accept=".zip,.xml"
				required
				@change="file = $event.target.files[0] || null" />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="result" type="success">
				{{ summary }}
			</NcNoteCard>
			<ul v-if="result && warnings.length > 0" class="qti-import__warnings">
				<li v-for="(warning, index) in warnings" :key="index">
					{{ warning }}
				</li>
			</ul>

			<div class="qti-import__actions">
				<NcButton
					type="submit"
					variant="primary"
					:disabled="uploading || !file">
					{{ t('learniq', 'Import') }}
				</NcButton>
				<NcButton variant="tertiary" @click="back">
					{{ t('learniq', 'Back to the item bank') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'QtiImportView',

	components: { NcButton, NcNoteCard },

	props: {
		/** ItemBank UUID from the route. */
		itemBankId: { type: String, required: true },
	},

	data() {
		return { file: null, uploading: false, error: '', result: null }
	},

	computed: {
		/**
		 * @return {string} What the import did.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		summary() {
			const count = Number(
				this.result?.imported
					?? this.result?.count
					?? this.result?.items?.length
					?? 0,
			)
			return this.n(
				'learniq',
				'%n item imported.',
				'%n items imported.',
				count,
			)
		},

		/**
		 * @return {string[]} Warnings the import reported.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		warnings() {
			const list = this.result?.warnings ?? this.result?.errors ?? []
			return Array.isArray(list)
				? list.map((w) =>
						typeof w === 'string' ? w : (w.message ?? JSON.stringify(w)),
					)
				: []
		},
	},

	methods: {
		/**
		 * Upload the package.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async upload() {
			this.error = ''
			this.result = null
			this.uploading = true
			try {
				const form = new FormData()
				form.append('file', this.file)
				form.append('itemBankId', this.itemBankId)
				const response = await axios.post(
					generateUrl('/apps/learniq/api/assessment/qti-import'),
					form,
				)
				this.result = response.data ?? {}
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('learniq', 'The package could not be imported.')
			} finally {
				this.uploading = false
			}
		},

		/**
		 * @return {void}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		back() {
			this.$router
				.push({ name: 'ItemBankDetail', params: { id: this.itemBankId } })
				.catch(() => {})
		},
	},
}
</script>

<style scoped>
.qti-import {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-inline-size: 40rem;
}

.qti-import__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.qti-import__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>
