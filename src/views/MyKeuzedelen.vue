<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 MyKeuzedelen: an mbo student's keuzedelen (exam-committee-today-and-keuzedelen).
 The offers open to her with their study load and the window's end, the
 line of her choice, and Choose. The server decides; a refusal is shown in
 its own words.
 @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/enrolment/spec.md#requirement-an-mbo-student-chooses-a-keuzedeel-inside-its-window
-->
<template>
	<div class="my-keuzedelen lq-board-page">
		<CnPageHeader :title="t('learniq', 'Keuzedelen')" />
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-if="line" class="my-keuzedelen__line" data-testid="keuzedeel-line">
			{{ line }}
		</p>
		<p v-if="loading">
			{{ t('learniq', 'Loading…') }}
		</p>
		<p v-else-if="offers.length === 0">
			{{ t('learniq', 'There are no keuzedelen to choose right now.') }}
		</p>
		<CnDetailCard
			v-for="offer in offers"
			:key="offer.id"
			:title="offer.code + ' ' + offer.name"
			class="my-keuzedelen__offer"
			data-testid="keuzedeel-offer">
			<p>
				{{ t('learniq', 'Period {period}', { period: offer.period }) }}
				<span v-if="offer.studyLoadHours">
					· {{ t('learniq', '{hours} SBU', { hours: offer.studyLoadHours }) }}
				</span>
			</p>
			<p v-if="offer.chosen" class="my-keuzedelen__mine">
				{{ t('learniq', 'You chose this keuzedeel') }}
			</p>
			<p v-else-if="offer.refusal" class="my-keuzedelen__closed">
				{{ offer.refusal }}
			</p>
			<NcButton
				v-else
				variant="primary"
				:disabled="busy"
				@click="choose(offer)">
				{{ t('learniq', 'Choose') }}
			</NcButton>
		</CnDetailCard>
	</div>
</template>

<script>
import { CnDetailCard, CnPageHeader } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { refusalReason } from '../utils/electiveLessons.js'

export default {
	name: 'MyKeuzedelen',

	components: {
		CnDetailCard,
		CnPageHeader,
		NcButton,
		NcNoteCard,
	},

	data() {
		return {
			offers: [],
			line: '',
			loading: true,
			busy: false,
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Load the offers open to the student and her line.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/enrolment/spec.md#requirement-an-mbo-student-chooses-a-keuzedeel-inside-its-window
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/learniq/api/keuzedelen'),
				)
				this.offers = data?.offers || []
				this.line = data?.line || ''
			} catch (e) {
				this.error = refusalReason(e, t)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Choose a keuzedeel, then reload so the line and buttons are current.
		 *
		 * @param {object} offer The offer.
		 * @return {Promise<void>}
		 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/enrolment/spec.md#requirement-an-mbo-student-chooses-a-keuzedeel-inside-its-window
		 */
		async choose(offer) {
			this.busy = true
			this.error = ''
			try {
				await axios.post(
					generateUrl('/apps/learniq/api/keuzedelen/{id}/choose', {
						id: offer.id,
					}),
				)
				await this.load()
			} catch (e) {
				this.error = refusalReason(e, t)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.my-keuzedelen__line {
	font-weight: bold;
	margin-bottom: calc(var(--default-grid-baseline) * 3);
}

.my-keuzedelen__mine {
	color: var(--color-success-text);
}

.my-keuzedelen__closed {
	color: var(--color-text-maxcontrast);
}
</style>
