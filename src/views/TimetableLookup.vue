<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 TimetableLookup (route /timetables, timetabling-visibility-rules).

 Look up the timetable of a group, a teacher or a room. The picker lists only
 what the school's visibility policy lets the caller open
 (GET /api/timetable/of/options), and the week comes from
 GET /api/timetable/of, which refuses anything else with a reason.

 @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
-->
<template>
	<div class="timetables">
		<header>
			<h2 class="timetables__title">
				{{ t('learniq', 'Timetables') }}
			</h2>
			<p class="timetables__intro">
				{{
					t(
						'learniq',
						'Look up the timetable of a group, a teacher or a room. Your school decides which ones you can open.',
					)
				}}
			</p>
		</header>

		<div class="timetables__controls">
			<div
				class="timetables__kind"
				role="group"
				:aria-label="t('learniq', 'Kind of timetable')">
				<NcButton
					v-for="option in kinds"
					:key="option.value"
					:variant="kind === option.value ? 'primary' : 'secondary'"
					@click="setKind(option.value)">
					{{ option.label }}
				</NcButton>
			</div>
			<NcSelect
				v-model="selectedId"
				:options="options"
				:reduce="(o) => o.id"
				label="label"
				:inputLabel="pickerLabel"
				:loading="loadingOptions" />
			<div class="timetables__week">
				<NcButton
					variant="tertiary"
					:aria-label="t('learniq', 'Previous week')"
					@click="shiftWeek(-1)">
					‹
				</NcButton>
				<span>{{ rangeLabel }}</span>
				<NcButton
					variant="tertiary"
					:aria-label="t('learniq', 'Next week')"
					@click="shiftWeek(1)">
					›
				</NcButton>
			</div>
		</div>

		<NcNoteCard v-if="!loadingOptions && options.length === 0" type="info">
			{{
				t(
					'learniq',
					'Your school does not let you open any of these timetables.',
				)
			}}
		</NcNoteCard>
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template v-else-if="selectedId">
			<NcEmptyContent
				v-if="sessions.length === 0"
				:name="t('learniq', 'No lessons this week')" />
			<section v-for="day in days" :key="day.iso" class="timetables__day">
				<h3>{{ day.label }}</h3>
				<ul class="timetables__lessons">
					<li
						v-for="session in day.sessions"
						:key="session.id + session.startsAt"
						class="timetables__lesson"
						:class="{
							'timetables__lesson--cancelled':
								session.lifecycle === 'cancelled',
						}">
						<span class="timetables__time">{{
							timeRange(session)
						}}</span>
						<span class="timetables__name">{{
							session.title || t('learniq', 'Untitled session')
						}}</span>
						<span v-if="session.location" class="timetables__loc">{{
							session.location
						}}</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'

/**
 * Monday 00:00 (local) of the week containing a date.
 *
 * @param {Date} date Any date.
 * @return {Date}
 */
function mondayOf(date) {
	const d = new Date(date.getFullYear(), date.getMonth(), date.getDate())
	d.setDate(d.getDate() - ((d.getDay() + 6) % 7))
	return d
}

export default {
	name: 'TimetableLookup',

	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect },

	data() {
		return {
			kind: 'cohort',
			options: [],
			selectedId: null,
			loadingOptions: false,
			loading: false,
			error: '',
			sessions: [],
			weekStart: mondayOf(new Date()),
		}
	},

	computed: {
		/**
		 * The three kinds of timetable.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
		 */
		kinds() {
			return [
				{ value: 'cohort', label: t('learniq', 'Groups') },
				{ value: 'teacher', label: t('learniq', 'Teachers') },
				{ value: 'room', label: t('learniq', 'Rooms') },
			]
		},

		/**
		 * The picker's label for the chosen kind.
		 *
		 * @return {string}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
		 */
		pickerLabel() {
			return {
				cohort: t('learniq', 'Group'),
				teacher: t('learniq', 'Teacher'),
				room: t('learniq', 'Room'),
			}[this.kind]
		},

		/**
		 * The viewed week as a label.
		 *
		 * @return {string}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
		 */
		rangeLabel() {
			const last = new Date(this.weekStart)
			last.setDate(last.getDate() + 6)
			const opts = { day: 'numeric', month: 'short' }
			return (
				this.weekStart.toLocaleDateString(undefined, opts)
				+ ' – '
				+ last.toLocaleDateString(undefined, opts)
			)
		},

		/**
		 * The days of the week that have lessons, each with its lessons.
		 *
		 * @return {Array<{iso: string, label: string, sessions: Array<object>}>}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
		 */
		days() {
			const out = []
			for (let i = 0; i < 7; i++) {
				const day = new Date(this.weekStart)
				day.setDate(day.getDate() + i)
				const next = new Date(day)
				next.setDate(next.getDate() + 1)
				const sessions = this.sessions.filter((s) => {
					const ts = Date.parse(s.startsAt)
					return ts >= day.getTime() && ts < next.getTime()
				})
				if (sessions.length > 0) {
					out.push({
						iso: day.toISOString().slice(0, 10),
						label: day.toLocaleDateString(undefined, {
							weekday: 'long',
							day: 'numeric',
							month: 'long',
						}),
						sessions,
					})
				}
			}
			return out
		},
	},

	watch: {
		/**
		 * Load the chosen timetable.
		 *
		 * @return {void}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
		 */
		selectedId() {
			this.load()
		},
	},

	/**
	 * Load the options of the first kind.
	 *
	 * @return {void}
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	mounted() {
		this.loadOptions()
	},

	methods: {
		t,

		/**
		 * Switch the kind and load its options.
		 *
		 * @param {string} kind `cohort`, `teacher` or `room`.
		 * @return {void}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
		 */
		setKind(kind) {
			this.kind = kind
			this.selectedId = null
			this.sessions = []
			this.loadOptions()
		},

		/**
		 * Load what the caller may open for the kind.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
		 */
		async loadOptions() {
			this.loadingOptions = true
			try {
				const res = await axios.get(
					generateUrl('/apps/learniq/api/timetable/of/options'),
					{ params: { kind: this.kind } },
				)
				this.options = res.data?.options || []
			} catch {
				this.options = []
			} finally {
				this.loadingOptions = false
			}
		},

		/**
		 * Load the chosen timetable for the viewed week.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
		 */
		async load() {
			if (!this.selectedId) {
				return
			}
			this.loading = true
			this.error = ''
			const end = new Date(this.weekStart)
			end.setDate(end.getDate() + 7)
			try {
				const res = await axios.get(
					generateUrl('/apps/learniq/api/timetable/of'),
					{
						params: {
							kind: this.kind,
							id: this.selectedId,
							from: this.weekStart.toISOString(),
							to: end.toISOString(),
						},
					},
				)
				this.sessions = res.data?.sessions || []
			} catch (e) {
				this.sessions = []
				this.error =
					e?.response?.status === 403
						? t(
								'learniq',
								'Your school does not let you see this timetable.',
							)
						: t(
								'learniq',
								'The timetable service is unavailable. Please try again later.',
							)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Move the viewed week.
		 *
		 * @param {number} delta Weeks to move.
		 * @return {void}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
		 */
		shiftWeek(delta) {
			const next = new Date(this.weekStart)
			next.setDate(next.getDate() + delta * 7)
			this.weekStart = next
			this.load()
		},

		/**
		 * A lesson's time range.
		 *
		 * @param {object} session The lesson.
		 * @return {string}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
		 */
		timeRange(session) {
			const fmt = (iso) => {
				const ts = Date.parse(iso || '')
				return Number.isNaN(ts)
					? ''
					: new Date(ts).toLocaleTimeString(undefined, {
							hour: '2-digit',
							minute: '2-digit',
						})
			}
			return [fmt(session.startsAt), fmt(session.endsAt)]
				.filter(Boolean)
				.join('–')
		},
	},
}
</script>

<style scoped>
.timetables {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.timetables__title {
	margin: 0;
}

.timetables__intro {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.timetables__controls,
.timetables__kind,
.timetables__week {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.timetables__controls {
	gap: 16px;
	align-items: flex-end;
}

.timetables__lessons {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.timetables__lesson {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	padding: 6px 8px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-primary-element-light);
}

.timetables__lesson--cancelled {
	opacity: 0.7;
	text-decoration: line-through;
}

.timetables__time,
.timetables__loc {
	color: var(--color-text-maxcontrast);
}

.timetables__name {
	font-weight: 600;
}
</style>
