<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CheckInPage: a learner checks in to a lesson with the code on the board
 (route /check-in, attendance-self-check-in).

 Lists the open check-ins of the learner's own lessons (GET /api/check-in,
 never the code) and takes the code. A link from the board carries
 ?window=<id>&code=<code> and fills both in. Every rule is checked by learniq;
 the page shows the answer in plain words.

 @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
-->
<template>
	<div class="check-in-page">
		<h2>{{ t('learniq', 'Check in to a lesson') }}</h2>
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else>
			<ul v-if="open.length > 0" class="check-in-page__lessons">
				<li v-for="item in open" :key="item.windowId">
					{{ item.title }} · {{ formatTime(item.startsAt) }}
				</li>
			</ul>
			<p v-else class="check-in-page__none">
				{{
					t(
						'learniq',
						'None of your lessons has an open check-in right now.',
					)
				}}
			</p>
			<form class="check-in-page__form" @submit.prevent="checkIn">
				<label for="check-in-code">{{
					t('learniq', 'Code on the board')
				}}</label>
				<input
					id="check-in-code"
					v-model="code"
					type="text"
					autocomplete="off"
					maxlength="8"
					class="check-in-page__code" />
				<NcButton
					type="submit"
					variant="primary"
					:disabled="busy || code.trim() === ''">
					{{ t('learniq', 'Check in') }}
				</NcButton>
			</form>
			<NcNoteCard v-if="result" :type="result.ok ? 'success' : 'error'">
				{{ result.message }}
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'CheckInPage',

	components: { NcButton, NcLoadingIcon, NcNoteCard },

	data() {
		return {
			loading: true,
			open: [],
			code: String(this.$route?.query?.code ?? ''),
			windowId: String(this.$route?.query?.window ?? ''),
			busy: false,
			result: null,
		}
	},

	/**
	 * Load the open check-ins of the learner's lessons.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
	 */
	async mounted() {
		try {
			this.open =
				(await axios.get(generateUrl('/apps/learniq/api/check-in'))).data
					.checkIns ?? []
		} catch {
			this.open = []
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * @param {string} value ISO date-time.
		 * @return {string} The local time.
		 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
		 */
		formatTime(value) {
			return value
				? new Date(value).toLocaleTimeString([], {
						hour: '2-digit',
						minute: '2-digit',
					})
				: ''
		},

		/**
		 * Send the code; learniq answers present, late or a plain reason.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/attendance/spec.md#scenario-a-learner-scans-the-code-at-the-start-of-the-lesson
		 */
		async checkIn() {
			this.busy = true
			this.result = null
			const url = this.windowId
				? `/apps/learniq/api/check-in/${encodeURIComponent(this.windowId)}`
				: '/apps/learniq/api/check-in'
			try {
				const data = (
					await axios.post(generateUrl(url), { code: this.code.trim() })
				).data
				this.result = {
					ok: true,
					message:
						data.status === 'late'
							? this.t('learniq', 'You are checked in. You were late.')
							: this.t('learniq', 'You are checked in.'),
				}
			} catch (error) {
				this.result = {
					ok: false,
					message:
						error?.response?.data?.message
						?? this.t('learniq', 'Checking in did not work. Try again.'),
				}
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.check-in-page {
	max-width: 480px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

.check-in-page__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-block: calc(var(--default-grid-baseline, 4px) * 4);
}

.check-in-page__code {
	font-size: 1.6em;
	letter-spacing: 0.2em;
	text-transform: uppercase;
	font-family: var(--font-face-monospace, monospace);
}

.check-in-page__none {
	color: var(--color-text-maxcontrast);
}
</style>
