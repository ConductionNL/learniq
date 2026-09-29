<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 DisplayScreenAddressView: create, renew or revoke a display screen's secret
 address (timetabling-display-screens). A new address is shown once; the
 server keeps only its hash.
 @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
-->
<template>
	<div class="screen-address">
		<h2>{{ t('learniq', 'Screen address') }}</h2>
		<p>
			{{
				t(
					'learniq',
					"Open this address in the browser of the screen. It needs no account and shows today's lessons and changes without names of learners.",
				)
			}}
		</p>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="revoked" type="success">
			{{
				t(
					'learniq',
					'The address was revoked. The screen shows nothing until you create a new address.',
				)
			}}
		</NcNoteCard>
		<div
			v-if="address"
			class="screen-address__result"
			data-testid="screen-address">
			<NcNoteCard type="warning">
				{{
					t(
						'learniq',
						"This address is shown once. Copy it into the screen's browser now.",
					)
				}}
			</NcNoteCard>
			<code class="screen-address__value">{{ address }}</code>
		</div>
		<div class="screen-address__actions">
			<NcButton variant="primary" :disabled="busy" @click="create">
				{{ t('learniq', 'Create address') }}
			</NcButton>
			<NcButton :disabled="busy" @click="revoke">
				{{ t('learniq', 'Revoke address') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'DisplayScreenAddressView',

	components: {
		NcButton,
		NcNoteCard,
	},

	data() {
		return {
			address: '',
			revoked: false,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The screen's uuid from the route.
		 *
		 * @return {string}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
		 */
		screenId() {
			return String(this.$route?.params?.id || '')
		},
	},

	methods: {
		t,

		/**
		 * Create or renew the address and show it once.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
		 */
		async create() {
			await this.post('token', (data) => {
				this.address = data.address || ''
				this.revoked = false
			})
		},

		/**
		 * Revoke the address.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
		 */
		async revoke() {
			await this.post('revoke', () => {
				this.address = ''
				this.revoked = true
			})
		},

		/**
		 * Post to the screen's token or revoke route.
		 *
		 * @param {string} verb `token` or `revoke`.
		 * @param {function(object): void} onDone Called with the answer.
		 * @return {Promise<void>}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
		 */
		async post(verb, onDone) {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl('/apps/learniq/api/display-screens/{id}/' + verb, {
						id: this.screenId,
					}),
				)
				onDone(data || {})
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| t('learniq', 'The address could not be changed.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.screen-address {
	max-width: 720px;
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.screen-address__value {
	display: block;
	padding: 8px;
	word-break: break-all;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.screen-address__actions {
	display: flex;
	gap: 8px;
}
</style>
