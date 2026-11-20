<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 ExcuseRequestSeen: the seen line on ExcuseRequestDetail. Opening a report on
 its own page is a teacher's look at it, the way opening the register that
 lists it is: the block posts to /api/attendance/excuse-requests/{id}/seen,
 which stamps seenBy / seenAt on the first look of a group teacher and never
 changes the lifecycle, then shows who saw it and when ("Gezien door juf Esra
 om 8.12 uur"). Nothing shows while nobody has seen it, or for a reader the
 server refuses.
 @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#requirement-a-teachers-first-look-at-an-absence-report-is-recorded
-->
<template>
	<p v-if="seenAt" class="excuse-seen" data-testid="excuse-request-seen">
		{{ t('learniq', 'Seen by {name} at {time}', { name: seenByName || seenBy, time: formatTime(seenAt) }) }}
	</p>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ExcuseRequestSeen',

	props: {
		/** ExcuseRequest UUID, from the manifest's `@objectId`. */
		requestId: { type: String, default: '' },
	},

	data() {
		return {
			seenBy: '',
			seenByName: '',
			seenAt: '',
		}
	},

	watch: {
		/**
		 * Look again when the page moves to another report.
		 *
		 * @return {void}
		 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#requirement-a-teachers-first-look-at-an-absence-report-is-recorded
		 */
		requestId() {
			this.look()
		},
	},

	mounted() {
		this.look()
	},

	methods: {
		/**
		 * Record this look and read who saw the report first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#requirement-a-teachers-first-look-at-an-absence-report-is-recorded
		 */
		async look() {
			this.seenBy = ''
			this.seenByName = ''
			this.seenAt = ''
			if (this.requestId === '') {
				return
			}
			try {
				const url = generateUrl('/apps/learniq/api/attendance/excuse-requests/{id}/seen', { id: this.requestId })
				const { data } = await axios.post(url)
				this.seenBy = String(data?.seenBy ?? '')
				this.seenByName = String(data?.seenByName ?? '')
				this.seenAt = String(data?.seenAt ?? '')
			} catch {
				// A reader the server refuses sees no line; the report itself still renders.
			}
		},

		/**
		 * @param {string} value An ISO date-time.
		 * @return {string} The time in the reader's locale, with the date when it was not today.
		 * @spec exclude Presentation-only time formatting.
		 */
		formatTime(value) {
			const date = new Date(value)
			if (Number.isNaN(date.getTime())) {
				return ''
			}
			const today = new Date().toDateString() === date.toDateString()
			return today
				? date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
				: date.toLocaleString(undefined, { dateStyle: 'long', timeStyle: 'short' })
		},
	},
}
</script>

<style scoped>
.excuse-seen {
	color: var(--color-text-maxcontrast);
}
</style>
