<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SelfCheckInPanel: the teacher opens self check-in for a lesson
 (attendance-self-check-in), in SelfCheckInDialog from the lesson's page.

 Creates a CheckInWindow (staff only). In the room the code goes full screen
 on the board at once (CheckInBoard); here it shows as a QR code with the
 code to type in, the check-in link for an online lesson and the count of
 check-ins so far. Close ends the window at once. The dialog holds the open
 window, so it survives the switch to the board and back.

 @spec openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
-->
<template>
	<section class="self-check-in" :aria-label="t('learniq', 'Self check-in')">
		<div v-if="!checkInWindow" class="self-check-in__start">
			<NcButton
				variant="secondary"
				:disabled="busy"
				@click="open('rotating-qr')">
				{{ t('learniq', 'Open self check-in') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="busy" @click="open('link')">
				{{ t('learniq', 'Open check-in for an online lesson') }}
			</NcButton>
		</div>
		<div v-else class="self-check-in__board">
			<p class="self-check-in__label">
				{{ t('learniq', 'Check in with this code') }}
			</p>
			<CheckInCode :checkInWindow="checkInWindow" />
			<div class="self-check-in__actions">
				<NcButton
					v-if="checkInWindow.mode !== 'link'"
					variant="secondary"
					@click="$emit('board')">
					{{ t('learniq', 'Show on the board') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy" @click="close">
					{{ t('learniq', 'Close check-in') }}
				</NcButton>
			</div>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import CheckInCode from './CheckInCode.vue'
import {
	objectId,
	objectsUrl,
	oneObject,
	transitionUrl,
} from '../utils/customPages.js'

export default {
	name: 'SelfCheckInPanel',

	components: { CheckInCode, NcButton, NcNoteCard },

	props: {
		/** The uuid of the lesson (Session) to open check-in for. */
		sessionId: {
			type: String,
			required: true,
		},

		/** The open CheckInWindow, or null before it opens. */
		checkInWindow: {
			type: Object,
			default: null,
		},
	},

	emits: ['update:checkInWindow', 'board'],

	data() {
		return {
			busy: false,
			error: '',
		}
	},

	methods: {
		/**
		 * Open a window for the lesson; in the room, go to the board. The
		 * server stamps who opened it, the tenant and the times: now until
		 * fifteen minutes from now, never past the lesson's end.
		 *
		 * @param {string} mode `rotating-qr` or `link`.
		 * @return {Promise<void>}
		 * @spec openspec/specs/attendance/spec.md#scenario-a-teacher-shows-the-check-in-code-on-the-board
		 */
		async open(mode) {
			this.busy = true
			this.error = ''
			try {
				this.$emit('update:checkInWindow', await this.createWindow(mode))
				if (mode === 'rotating-qr') this.$emit('board')
			} catch {
				this.error = this.t('learniq', 'Self check-in could not be opened.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Create the CheckInWindow.
		 *
		 * @param {string} mode `rotating-qr` or `link`.
		 * @return {Promise<object>} The window.
		 * @spec openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
		 */
		async createWindow(mode) {
			const url = generateUrl(objectsUrl('check-in-window'))
			const { data } = await axios.post(url, { sessionId: this.sessionId, mode })
			return oneObject(data)
		},

		/**
		 * Close the window at once.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
		 */
		async close() {
			this.busy = true
			try {
				const url = generateUrl(transitionUrl(objectId(this.checkInWindow)))
				await axios.post(url, { action: 'close' })
				this.$emit('update:checkInWindow', null)
			} catch {
				this.error = this.t('learniq', 'Self check-in could not be closed.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.self-check-in {
	margin-block: calc(var(--default-grid-baseline, 4px) * 3);
}

.self-check-in__start,
.self-check-in__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	flex-wrap: wrap;
	justify-content: center;
}

.self-check-in__board {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	text-align: center;
}

.self-check-in__label {
	color: var(--color-text-maxcontrast);
}
</style>
