<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 TimetableImportDialog: ask integriq to deliver a rostering system's
 timetable into planninq, through POST /api/timetable/imports (learniq #1157).

 The group code map comes from the admin settings (the server fills it in for
 the chosen system); this dialog only asks which system to read. Opened from
 the timetable conflict queue by someone the server says holds
 exchange.request. The endpoint checks the same right.

 @spec openspec/specs/timetabling/spec.md#requirement-the-timetable-page-offers-the-import-to-whoever-may-request-an-exchange
-->
<template>
	<NcDialog
		:open="true"
		:name="t('learniq', 'Import a timetable')"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<div class="timetable-import">
			<p>
				{{
					t(
						'learniq',
						'Integriq reads the timetable from your rostering system and delivers it to planninq. Learniq then checks the lessons for conflicts.',
					)
				}}
			</p>
			<NcSelect
				v-model="source"
				:options="sourceOptions"
				:inputLabel="t('learniq', 'Rostering system')"
				label="label"
				data-testid="timetable-import-source" />
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="done" type="success">
				{{ done }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('learniq', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!source || busy"
				data-testid="timetable-import-submit"
				@click="submit">
				{{ t('learniq', 'Import') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { IMPORT_URL, SOURCE_LABELS } from '../utils/timetableExchangeSettings.js'

export default {
	name: 'TimetableImportDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
	},

	emits: ['close', 'imported'],

	data() {
		return {
			source: null,
			busy: false,
			error: '',
			done: '',
		}
	},

	computed: {
		/**
		 * @return {Array<{id: string, label: string}>} The rostering systems.
		 * @spec openspec/specs/timetabling/spec.md#requirement-the-timetable-page-offers-the-import-to-whoever-may-request-an-exchange
		 */
		sourceOptions() {
			return Object.entries(SOURCE_LABELS).map(([id, label]) => ({
				id,
				label,
			}))
		},
	},

	methods: {
		/**
		 * Ask for the delivery and show what came back.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/timetabling/spec.md#requirement-the-timetable-page-offers-the-import-to-whoever-may-request-an-exchange
		 */
		async submit() {
			this.busy = true
			this.error = ''
			this.done = ''
			try {
				const { data } = await axios.post(generateUrl(IMPORT_URL), {
					rosterSource: this.source.id,
				})
				this.done = this.t(
					'learniq',
					'The timetable was delivered to planninq ({state}).',
					{ state: data.state },
				)
				this.$emit('imported', data)
			} catch (e) {
				this.error =
					e?.response?.data?.reason
					|| e?.response?.data?.error
					|| this.t('learniq', 'The timetable could not be imported.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.timetable-import {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-bottom: 8px;
}
</style>
