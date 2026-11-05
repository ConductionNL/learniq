<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 TeacherAvailabilityDialog: "Wanneer bent u vrij voor oudergesprekken?"
 (learniq/LqGesprekBeschikbaarheid). A teacher marks the free half hours of
 each evening of a conference round. Neighbouring half hours become one
 TeacherAvailability block. "Save as draft" keeps it open; "Submit" saves
 and runs `submit`, after which the planning uses it and it is fixed.
 The evenings come from the round's `evenings` ({ date, from, until }).
 @spec openspec/specs/parent-conferences/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'When are you free for parent conversations?')"
		:eyebrow="eyebrow"
		width="wizard"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="teacher-availability-dialog"
		@closing="$emit('close')">
		<div class="availability">
			<DialogFacts :facts="facts" />
			<p class="availability__lead">
				{{ t('learniq', 'Choose per evening the half hours you are free') }}
			</p>
			<p v-if="!loading && evenings.length === 0" class="availability__muted">
				{{ t('learniq', 'This round has no evenings yet.') }}
			</p>
			<div v-for="evening in evenings" :key="evening.date" class="availability__evening">
				<div class="availability__day">
					<strong>{{ evening.label }}</strong>
					<span class="availability__muted">{{ eveningLine(evening.date) }}</span>
				</div>
				<div
					role="group"
					class="availability__slots"
					:aria-label="t('learniq', 'Free half hours on {day}', { day: evening.label })">
					<NcButton
						v-for="time in evening.halfHours"
						:key="time"
						:variant="isPicked(evening.date, time) ? 'primary' : 'secondary'"
						:aria-pressed="isPicked(evening.date, time) ? 'true' : 'false'"
						:disabled="busy || locked"
						@click="toggle(evening.date, time)">
						{{ time }}
					</NcButton>
				</div>
			</div>
			<p class="availability__muted">
				{{ t('learniq', 'After submitting, learniq plans the conversations in your free time. Once the planning is made, your availability is fixed.') }}
			</p>
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<NcButton
				variant="secondary"
				:disabled="busy || locked || loading"
				data-testid="teacher-availability-draft"
				@click="save(false)">
				{{ t('learniq', 'Save as draft') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ t('learniq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || locked || loading || blocks.length === 0"
				data-testid="teacher-availability-submit"
				@click="save(true)">
				{{ t('learniq', 'Submit availability') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { getLanguage } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import DialogFacts from './DialogFacts.vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import { availabilityBlocks, dayLabel, eveningHalfHours, eveningSummary, pickedFromBlocks } from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, callerTenant, createObject, currentUserId, listObjects, readObject, runTransition, updateObject } from './dialogRequests.js'

export default {
	name: 'TeacherAvailabilityDialog',

	components: { CnDialog, DialogFacts, DialogNoticeFallback, NcButton },

	props: {
		/** The ConferenceRound's uuid. */
		conferenceRoundId: { type: String, required: true },
	},

	emits: ['close'],

	data() {
		return { round: {}, availability: {}, picked: {}, loading: true, busy: false, notice: null }
	},

	computed: {
		/**
		 * @return {string} "Beschikbaarheid, <teacher>".
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		eyebrow() {
			const name = getCurrentUser()?.displayName || currentUserId()
			return this.t('learniq', 'Availability, {name}', { name })
		},

		/**
		 * @return {object[]} The round, the length of a conversation and the booking window.
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		facts() {
			const opens = dayLabel(this.round.bookingOpensAt, getLanguage(), { weekday: 'long', year: undefined })
			const closes = dayLabel(this.round.bookingClosesAt, getLanguage(), { weekday: 'long', year: undefined })
			return [
				{ label: this.t('learniq', 'Round'), value: this.round.name ?? '' },
				{
					label: this.t('learniq', 'Conversations'),
					value: this.round.slotDurationMinutes
						? this.t('learniq', '{minutes} minutes, with {pause} minutes between two conversations', { minutes: this.round.slotDurationMinutes, pause: this.round.bufferMinutes ?? 0 })
						: '',
				},
				{ label: this.t('learniq', 'Parents book'), value: opens && closes ? this.t('learniq', 'from {from} up to and including {until}', { from: opens, until: closes }) : '' },
			]
		},

		/**
		 * @return {object[]} The round's evenings with their half hours.
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		evenings() {
			return (Array.isArray(this.round.evenings) ? this.round.evenings : [])
				.filter((evening) => evening?.date && evening.from && evening.until)
				.map((evening) => ({
					date: evening.date,
					label: dayLabel(`${evening.date}T12:00:00`, getLanguage(), { weekday: 'long', year: undefined }),
					halfHours: eveningHalfHours(evening),
				}))
		},

		/**
		 * @return {object[]} The blocks the free half hours make.
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		blocks() {
			return availabilityBlocks(this.picked)
		},

		/**
		 * @return {boolean} Whether the availability is submitted or fixed.
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		locked() {
			return Boolean(this.availability.lifecycle) && this.availability.lifecycle !== 'draft'
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	/**
	 * Read the round and the teacher's earlier availability for it.
	 *
	 * @spec openspec/specs/parent-conferences/spec.md
	 */
	async created() {
		try {
			this.round = await readObject('conference-round', this.conferenceRoundId)
			const [earlier] = await listObjects('teacher-availability', { conferenceRoundId: this.conferenceRoundId, teacherId: currentUserId(), _limit: 1 })
			this.availability = earlier ?? {}
			this.picked = pickedFromBlocks(this.availability.blocks)
		} catch (error) {
			this.notice = requestRefusal(error, this.t('learniq', 'Not found.'), this.t('learniq', 'The round could not be read.'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * @param {string} date The evening (YYYY-MM-DD).
		 * @param {string} time The half hour (HH:MM).
		 * @return {boolean} Whether the half hour is marked free.
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		isPicked(date, time) {
			return (this.picked[date] ?? []).includes(time)
		},

		/**
		 * Mark a half hour free, or not.
		 *
		 * @param {string} date The evening (YYYY-MM-DD).
		 * @param {string} time The half hour (HH:MM).
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		toggle(date, time) {
			const day = this.picked[date] ?? []
			this.picked = { ...this.picked, [date]: day.includes(time) ? day.filter((entry) => entry !== time) : [...day, time] }
		},

		/**
		 * @param {string} date The evening (YYYY-MM-DD).
		 * @return {string} "18:30 tot 20:00, ongeveer 6 gesprekken", or "niet beschikbaar".
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		eveningLine(date) {
			const summary = eveningSummary(this.picked[date] ?? [], this.round.slotDurationMinutes, this.round.bufferMinutes)
			if (!summary) {
				return this.t('learniq', 'not available')
			}
			return this.n('learniq', '{from} to {until}, about %n conversation', '{from} to {until}, about %n conversations', summary.count, { from: summary.from, until: summary.until })
		},

		/**
		 * Save the free blocks, and submit them when asked, then close.
		 *
		 * @param {boolean} submit Whether to run `submit` after saving.
		 * @return {Promise<void>}
		 * @spec openspec/specs/parent-conferences/spec.md
		 */
		async save(submit) {
			this.busy = true
			this.notice = null
			const body = {
				conferenceRoundId: this.conferenceRoundId,
				teacherId: currentUserId(),
				teacherName: getCurrentUser()?.displayName || currentUserId(),
				blocks: this.blocks,
			}
			const tenant = callerTenant()
			if (tenant) {
				body.tenant_id = tenant
			}
			try {
				this.availability = this.availability.id
					? await updateObject('teacher-availability', this.availability.id, body)
					: await createObject('teacher-availability', body)
				if (submit) {
					await runTransition(this.availability.id, 'submit')
				}
				announceSaved('learniq:teacher-availability:saved', { availabilityId: this.availability.id, submitted: submit })
				this.$emit('close')
			} catch (error) {
				this.notice = requestRefusal(error, this.t('learniq', 'Not saved.'), this.t('learniq', 'Your availability could not be saved.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.availability {
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.availability__lead {
	margin: 0;
	font-weight: bold;
}

.availability__evening {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.availability__day {
	display: flex;
	flex-direction: column;
}

.availability__slots {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.availability__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
