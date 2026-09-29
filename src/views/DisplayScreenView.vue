<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 DisplayScreenView: the public hall-screen page (timetabling-display-screens).
 Large type, lessons sorted by time and group, changes highlighted. Refreshes
 every minute; when a refresh fails it keeps the last good data and says when
 it was last updated.
 @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
-->
<template>
	<main class="display-screen">
		<header class="display-screen__header">
			<h1>{{ name || t('learniq', 'Timetable') }}</h1>
			<p class="display-screen__clock">
				{{ updatedLabel }}
			</p>
		</header>
		<p v-if="stale" class="display-screen__stale" role="status">
			{{
				t(
					'learniq',
					'Cannot reach the server. Showing the timetable as it was at {time}.',
					{ time: updatedLabel },
				)
			}}
		</p>
		<p v-if="loaded && lessons.length === 0" class="display-screen__empty">
			{{ t('learniq', 'No lessons to show.') }}
		</p>
		<table v-if="lessons.length > 0" class="display-screen__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('learniq', 'Time') }}
					</th>
					<th scope="col">
						{{ t('learniq', 'Group') }}
					</th>
					<th scope="col">
						{{ t('learniq', 'Subject') }}
					</th>
					<th scope="col">
						{{ t('learniq', 'Room') }}
					</th>
					<th scope="col">
						{{ t('learniq', 'Teacher') }}
					</th>
					<th scope="col">
						{{ t('learniq', 'Change') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="(lesson, index) in lessons"
					:key="index"
					:class="{ 'display-screen__row--changed': lesson.change }">
					<td>{{ time(lesson.startsAt) }}–{{ time(lesson.endsAt) }}</td>
					<td>{{ lesson.group }}</td>
					<td>{{ lesson.subject }}</td>
					<td>{{ lesson.room }}</td>
					<td>{{ lesson.teacherCode || '' }}</td>
					<td>
						<strong v-if="lesson.change">{{
							changeLabel(lesson.change)
						}}</strong>
					</td>
				</tr>
			</tbody>
		</table>
	</main>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const REFRESH_MS = 60_000

export default {
	name: 'DisplayScreenView',

	props: {
		token: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			name: '',
			lessons: [],
			updatedAt: '',
			loaded: false,
			stale: false,
			timer: null,
		}
	},

	computed: {
		/**
		 * When the data was last updated, as a clock time.
		 *
		 * @return {string}
		 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
		 */
		updatedLabel() {
			return this.updatedAt ? this.time(this.updatedAt) : ''
		},
	},

	mounted() {
		this.refresh()
		this.timer = setInterval(this.refresh, REFRESH_MS)
	},

	beforeUnmount() {
		clearInterval(this.timer)
	},

	methods: {
		t,

		/**
		 * Load the screen's lessons; keep the last good data on a failure.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
		 */
		async refresh() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/learniq/api/public/display/{token}', {
						token: this.token,
					}),
				)
				this.name = data.name || ''
				this.lessons = data.lessons || []
				this.updatedAt = data.updatedAt || new Date().toISOString()
				this.stale = false
			} catch {
				this.stale = this.loaded
			} finally {
				this.loaded = true
			}
		},

		/**
		 * A clock time such as 10:15.
		 *
		 * @param {string} iso A date-time.
		 * @return {string}
		 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
		 */
		time(iso) {
			const date = new Date(iso)
			if (Number.isNaN(date.getTime())) return ''
			return date.toLocaleTimeString(undefined, {
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		/**
		 * The label of a change.
		 *
		 * @param {string} change `cancelled`, `other-teacher` or `other-room`.
		 * @return {string}
		 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
		 */
		changeLabel(change) {
			const labels = {
				cancelled: t('learniq', 'Cancelled'),
				'other-teacher': t('learniq', 'Other teacher'),
				'other-room': t('learniq', 'Other room'),
			}
			return labels[change] || ''
		},
	},
}
</script>

<style scoped>
.display-screen {
	min-height: 100vh;
	padding: 24px 32px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 1.6rem;
}

.display-screen__header {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
}

.display-screen__stale {
	padding: 8px 12px;
	border-inline-start: 6px solid var(--color-warning);
	background: var(--color-background-dark);
}

.display-screen__table {
	width: 100%;
	border-collapse: collapse;
}

.display-screen__table th,
.display-screen__table td {
	padding: 8px 12px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.display-screen__row--changed {
	background: var(--color-primary-element-light);
	font-weight: bold;
}
</style>
