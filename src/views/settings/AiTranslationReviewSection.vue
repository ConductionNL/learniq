<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AiTranslationReviewSection: the Dutch interface strings an AI wrote and no
 human translator has checked yet (ai-translated-catalogue-review, decision
 D24). Lists every key in l10n/ai-translated.json with its English source and
 Dutch value; "Reviewed" takes a key off that list. The list file lives in the
 app directory, so a translator works on a development checkout and commits
 it; on a read-only install the button explains that.

 @spec openspec/specs/ai-translated-catalogue/spec.md#requirement-the-admin-settings-list-the-keys-with-source-and-dutch-value
-->
<template>
	<NcSettingsSection
		id="section-ai-translations"
		:name="t('learniq', 'AI-translated strings')"
		:description="
			t(
				'learniq',
				'These Dutch texts in the app were written by AI and not yet checked by a translator. Check each one, fix it in the catalogue if needed, then mark it as reviewed.',
			)
		">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<p v-if="loading">
			{{ t('learniq', 'Loading…') }}
		</p>

		<template v-else>
			<p class="learniq-ai-review__count">
				{{
					t('learniq', '{shown} of {total} strings', {
						shown: filtered.length,
						total: items.length,
					})
				}}
			</p>

			<NcTextField
				v-model="query"
				:label="t('learniq', 'Filter by English or Dutch text')"
				@update:modelValue="page = 0" />

			<p v-if="items.length === 0">
				{{ t('learniq', 'Every AI-written string has been reviewed.') }}
			</p>

			<table v-else class="learniq-ai-review__table">
				<caption class="hidden-visually">
					{{
						t('learniq', 'AI-translated strings awaiting review')
					}}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('learniq', 'English source') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Dutch text') }}
						</th>
						<th scope="col">
							<span class="hidden-visually">{{
								t('learniq', 'Action')
							}}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="item in visible" :key="item.key">
						<td lang="en">
							{{ asText(item.source) }}
						</td>
						<td lang="nl">
							{{ asText(item.value) }}
						</td>
						<td>
							<NcButton
								variant="secondary"
								:disabled="busyKey === item.key"
								:aria-label="
									t('learniq', 'Mark “{text}” as reviewed', {
										text: asText(item.source),
									})
								"
								@click="markReviewed(item)">
								{{ t('learniq', 'Reviewed') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<div v-if="pageCount > 1" class="learniq-ai-review__pages">
				<NcButton variant="tertiary" :disabled="page === 0" @click="page--">
					{{ t('learniq', 'Previous') }}
				</NcButton>
				<span>{{
					t('learniq', 'Page {page} of {pages}', {
						page: page + 1,
						pages: pageCount,
					})
				}}</span>
				<NcButton
					variant="tertiary"
					:disabled="page >= pageCount - 1"
					@click="page++">
					{{ t('learniq', 'Next') }}
				</NcButton>
			</div>
		</template>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import {
	filterReviewItems,
	PAGE_SIZE,
	pageOf,
	reviewText,
} from '../../utils/aiTranslationReview.js'

export default {
	name: 'AiTranslationReviewSection',

	components: {
		NcButton,
		NcNoteCard,
		NcSettingsSection,
		NcTextField,
	},

	data() {
		return {
			items: [],
			loading: true,
			error: '',
			query: '',
			page: 0,
			busyKey: null,
		}
	},

	computed: {
		/**
		 * The items matching the filter.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/ai-translated-catalogue/spec.md#requirement-the-admin-settings-list-the-keys-with-source-and-dutch-value
		 */
		filtered() {
			return filterReviewItems(this.items, this.query)
		},

		/**
		 * How many pages the filtered list has.
		 *
		 * @return {number}
		 * @spec openspec/specs/ai-translated-catalogue/spec.md#requirement-the-admin-settings-list-the-keys-with-source-and-dutch-value
		 */
		pageCount() {
			return Math.max(1, Math.ceil(this.filtered.length / PAGE_SIZE))
		},

		/**
		 * The rows on the current page.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/ai-translated-catalogue/spec.md#requirement-the-admin-settings-list-the-keys-with-source-and-dutch-value
		 */
		visible() {
			return pageOf(this.filtered, this.page)
		},
	},

	/**
	 * Load the list once.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/ai-translated-catalogue/spec.md#requirement-the-admin-settings-list-the-keys-with-source-and-dutch-value
	 */
	async mounted() {
		try {
			const response = await axios.get(
				generateUrl('/apps/learniq/api/l10n/ai-translated'),
			)
			this.items = response.data.items || []
		} catch {
			this.error = this.t(
				'learniq',
				'The list of AI-translated strings could not be loaded.',
			)
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * A value as one line of text; a plural shows its forms joined.
		 *
		 * @param {string|Array<string>|null} value The catalogue value.
		 * @return {string}
		 * @spec openspec/specs/ai-translated-catalogue/spec.md#requirement-the-admin-settings-list-the-keys-with-source-and-dutch-value
		 */
		asText(value) {
			return reviewText(value)
		},

		/**
		 * Take one key off the list.
		 *
		 * @param {object} item The row.
		 * @return {Promise<void>}
		 * @spec openspec/specs/ai-translated-catalogue/spec.md#requirement-marking-a-key-reviewed-removes-it-from-the-sidecar
		 */
		async markReviewed(item) {
			this.busyKey = item.key
			this.error = ''
			try {
				await axios.post(
					generateUrl('/apps/learniq/api/l10n/ai-translated/reviewed'),
					{ key: item.key },
				)
				this.items = this.items.filter((row) => row.key !== item.key)
				this.page = Math.min(this.page, this.pageCount - 1)
			} catch (error) {
				this.error =
					error?.response?.status === 409
						? this.t(
								'learniq',
								'This instance cannot change the list. Review on a development checkout and commit l10n/ai-translated.json.',
							)
						: this.t(
								'learniq',
								'The string could not be marked as reviewed.',
							)
			} finally {
				this.busyKey = null
			}
		},
	},
}
</script>

<style scoped>
.learniq-ai-review__table {
	width: 100%;
	margin-block: var(--default-grid-baseline, 4px)
		calc(var(--default-grid-baseline, 4px) * 4);
	border-collapse: collapse;
}

.learniq-ai-review__table th,
.learniq-ai-review__table td {
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	border-block-end: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.learniq-ai-review__pages {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.learniq-ai-review__count {
	color: var(--color-text-maxcontrast);
}
</style>
