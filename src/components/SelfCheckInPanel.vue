<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SelfCheckInPanel: the teacher opens self check-in for a lesson from the
 register (attendance-self-check-in).

 Creates a CheckInWindow (staff only in the register), then shows the code
 from learniq's GET /api/check-in/{id}/code in large letters, refreshed with
 every thirty-second step, the check-in link for an online lesson and the
 count of check-ins so far. Learners type the code on their check-in page or
 in the portal. Close ends the window at once.

 @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
-->
<template>
	<section class="self-check-in" :aria-label="t('learniq', 'Self check-in')">
		<div v-if="!window" class="self-check-in__start">
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
			<p class="self-check-in__code" aria-live="polite">
				{{ board.code }}
			</p>
			<p v-if="window.mode === 'link'" class="self-check-in__link">
				<a :href="board.url" target="_blank" rel="noopener">{{
					board.url
				}}</a>
			</p>
			<p v-else class="self-check-in__hint">
				{{ t('learniq', 'The code changes every thirty seconds.') }}
			</p>
			<p class="self-check-in__count">
				{{
					n(
						'learniq',
						'%n learner checked in',
						'%n learners checked in',
						board.checkInCount,
					)
				}}
			</p>
			<NcButton variant="primary" :disabled="busy" @click="close">
				{{ t('learniq', 'Close check-in') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</section>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import {
	objectId,
	objectsUrl,
	oneObject,
	transitionUrl,
} from '../utils/customPages.js'

export default {
	name: 'SelfCheckInPanel',

	components: { NcButton, NcNoteCard },

	props: {
		/** The Session the register is for. */
		session: {
			type: Object,
			required: true,
		},
	},

	emits: ['checkedIn'],

	data() {
		return {
			window: null,
			board: { code: '', url: '', checkInCount: 0 },
			busy: false,
			error: '',
			timer: null,
		}
	},

	beforeUnmount() {
		clearTimeout(this.timer)
	},

	methods: {
		/**
		 * Open a window for the lesson: now until fifteen minutes from now,
		 * never past the lesson's end.
		 *
		 * @param {string} mode `rotating-qr` or `link`.
		 * @return {Promise<void>}
		 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#scenario-a-teacher-shows-the-check-in-code-on-the-board
		 */
		async open(mode) {
			this.busy = true
			this.error = ''
			const now = new Date()
			let closes = new Date(now.getTime() + 15 * 60 * 1000)
			const ends = this.session.endsAt ? new Date(this.session.endsAt) : null
			if (ends && ends < closes) closes = ends
			try {
				this.window = oneObject(
					(
						await axios.post(
							generateUrl(objectsUrl('check-in-window')),
							{
								sessionId: objectId(this.session),
								openedBy: getCurrentUser()?.uid ?? '',
								opensAt: now.toISOString(),
								closesAt: closes.toISOString(),
								lateAfterMinutes: 5,
								mode,
								tenant_id: this.session.tenant_id ?? '',
							},
						)
					).data,
				)
				await this.refresh()
			} catch {
				this.window = null
				this.error = this.t('learniq', 'Self check-in could not be opened.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Read the current code and count, and schedule the next read.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-the-check-in-code-changes-every-thirty-seconds-in-the-room
		 */
		async refresh() {
			if (!this.window) return
			try {
				const previous = this.board.checkInCount
				this.board = (
					await axios.get(
						generateUrl(
							`/apps/learniq/api/check-in/${objectId(this.window)}/code`,
						),
					)
				).data
				if (this.board.checkInCount !== previous) this.$emit('checkedIn')
			} catch {
				this.error = this.t(
					'learniq',
					'The check-in code could not be read.',
				)
			}
			const wait = Math.min(Math.max(this.board.secondsLeft ?? 5, 1), 5)
			this.timer = setTimeout(() => this.refresh(), wait * 1000)
		},

		/**
		 * Close the window at once.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
		 */
		async close() {
			this.busy = true
			clearTimeout(this.timer)
			try {
				await axios.post(generateUrl(transitionUrl(objectId(this.window))), {
					action: 'close',
				})
				this.window = null
				this.$emit('checkedIn')
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

.self-check-in__start {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	flex-wrap: wrap;
}

.self-check-in__board {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	text-align: center;
}

.self-check-in__code {
	font-size: 3em;
	font-weight: bold;
	letter-spacing: 0.2em;
	font-family: var(--font-face-monospace, monospace);
}

.self-check-in__hint,
.self-check-in__label {
	color: var(--color-text-maxcontrast);
}
</style>
