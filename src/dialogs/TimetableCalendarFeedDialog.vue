<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 TimetableCalendarFeedDialog: a user makes a personal calendar address for
 their timetable, copies it into the calendar app they already use, and
 resets it when it leaks (attendance-timetable-calendar-feed).

 Only a hash of the address's token is stored, so the address is shown once,
 right after it is made. Reset link makes a new address and the old one
 answers 404 at once.

 @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
-->
<template>
	<NcDialog
		:open="true"
		:name="t('learniq', 'Subscribe in your calendar')"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<div class="calendar-feed" data-testid="calendar-feed-dialog">
			<p>
				{{
					t(
						'learniq',
						'Add this address to your calendar app to see your lessons there. Your calendar app refreshes it, so cancelled lessons and substitutes show up by themselves.',
					)
				}}
			</p>
			<NcLoadingIcon v-if="loading" :size="32" />
			<template v-else-if="url">
				<NcTextField
					:modelValue="url"
					:label="t('learniq', 'Calendar address')"
					readonly
					data-testid="calendar-feed-url" />
				<p class="calendar-feed__hint">
					{{
						t(
							'learniq',
							'Anyone with this address can read your timetable. Keep it to yourself.',
						)
					}}
				</p>
				<a
					:href="webcalUrl"
					class="calendar-feed__link"
					data-testid="calendar-feed-webcal">
					{{ t('learniq', 'Open in your calendar app') }}
				</a>
			</template>
			<p v-else-if="exists" data-testid="calendar-feed-exists">
				{{
					t(
						'learniq',
						'You already have a calendar address. If you lost it or shared it by mistake, reset it: the old address stops working.',
					)
				}}
			</p>
			<NcNoteCard v-if="copied" type="success">
				{{ t('learniq', 'The address is copied.') }}
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton
				v-if="exists && !url"
				variant="tertiary"
				:disabled="busy"
				data-testid="calendar-feed-remove"
				@click="remove">
				{{ t('learniq', 'Remove address') }}
			</NcButton>
			<NcButton
				v-if="url"
				variant="secondary"
				data-testid="calendar-feed-copy"
				@click="copy">
				{{ t('learniq', 'Copy address') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || loading"
				data-testid="calendar-feed-create"
				@click="create">
				{{
					exists
						? t('learniq', 'Reset link')
						: t('learniq', 'Make an address')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import {
	createCalendarFeed,
	fetchCalendarFeedStatus,
	removeCalendarFeed,
} from '../api/timetable.js'

export default {
	name: 'TimetableCalendarFeedDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			busy: false,
			exists: false,
			url: '',
			webcalUrl: '',
			copied: false,
			error: '',
		}
	},

	async mounted() {
		try {
			this.exists = await fetchCalendarFeedStatus()
		} catch {
			this.error = this.t(
				'learniq',
				'Your calendar address could not be read.',
			)
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Make a new address; an earlier one stops working.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
		 */
		async create() {
			this.busy = true
			this.error = ''
			this.copied = false
			try {
				const feed = await createCalendarFeed()
				this.url = feed.url
				this.webcalUrl = feed.webcalUrl
				this.exists = true
			} catch {
				this.error = this.t(
					'learniq',
					'The calendar address could not be made.',
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Remove the address, so it answers 404.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
		 */
		async remove() {
			this.busy = true
			this.error = ''
			try {
				await removeCalendarFeed()
				this.exists = false
				this.url = ''
				this.webcalUrl = ''
			} catch {
				this.error = this.t(
					'learniq',
					'The calendar address could not be removed.',
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Copy the address to the clipboard.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
		 */
		async copy() {
			this.error = ''
			try {
				await navigator.clipboard.writeText(this.url)
				this.copied = true
			} catch {
				this.error = this.t(
					'learniq',
					'Copying did not work. Select the address and copy it yourself.',
				)
			}
		},
	},
}
</script>

<style scoped>
.calendar-feed {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-bottom: 8px;
}

.calendar-feed__hint {
	color: var(--color-text-maxcontrast);
}

.calendar-feed__link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
