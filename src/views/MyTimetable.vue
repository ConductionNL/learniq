<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 MyTimetable — the signed-in user's personal timetable (personal-timetable).

 A read-only week view over the caller's OWN scheduled Session objects. The
 backend (TimetableController::mine) resolves the caller's cohorts (as a teacher
 via Cohort.teacherIds, as a learner via Cohort.learnerIds / Enrolment.cohortId)
 and returns only the sessions of those cohorts within the requested window,
 RBAC-scoped through OpenRegister's ObjectService. This view never creates or
 mutates anything — it renders the returned sessions as day-column blocks
 (title / time / location) with a click-through to the session detail page and
 a today/week toggle. A caller with no cohorts sees the empty state.

 @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
-->
<template>
	<div class="my-timetable">
		<div class="my-timetable__header">
			<h2 class="my-timetable__title">
				{{ t('learniq', 'My timetable') }}
			</h2>
			<div class="my-timetable__controls">
				<NcButton
					variant="tertiary"
					:aria-label="t('learniq', 'Previous week')"
					:disabled="loading"
					@click="shiftWeek(-1)">
					‹
				</NcButton>
				<span class="my-timetable__range">{{ rangeLabel }}</span>
				<NcButton
					variant="tertiary"
					:aria-label="t('learniq', 'Next week')"
					:disabled="loading"
					@click="shiftWeek(1)">
					›
				</NcButton>
				<div
					class="my-timetable__toggle"
					role="group"
					:aria-label="t('learniq', 'View mode')">
					<NcButton
						:variant="mode === 'today' ? 'primary' : 'secondary'"
						:disabled="loading"
						@click="setMode('today')">
						{{ t('learniq', 'Today') }}
					</NcButton>
					<NcButton
						:variant="mode === 'week' ? 'primary' : 'secondary'"
						:disabled="loading"
						@click="setMode('week')">
						{{ t('learniq', 'Week') }}
					</NcButton>
				</div>
				<NcButton
					variant="secondary"
					data-testid="calendar-feed-open"
					@click="subscribing = true">
					{{ t('learniq', 'Subscribe in your calendar') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="44" class="my-timetable__loading" />

		<NcEmptyContent
			v-else-if="error"
			:name="t('learniq', 'Could not load your timetable')"
			:description="error">
			<template #icon>
				<span class="icon-error" />
			</template>
		</NcEmptyContent>

		<NcNoteCard v-if="!loading && !error && source === 'planninq'" type="info">
			{{
				t(
					'learniq',
					'These lessons come from the school timetable. Changes are made there.',
				)
			}}
		</NcNoteCard>

		<section
			v-if="!loading && !error && changes.length > 0"
			class="my-timetable__changes"
			aria-live="polite">
			<h3 class="my-timetable__changes-title">
				{{ t('learniq', "Today's changes") }}
			</h3>
			<ul class="my-timetable__changes-list">
				<li
					v-for="session in changes"
					:key="'change-' + session.id"
					class="my-timetable__change">
					<span class="my-timetable__change-name">{{
						session.title || t('learniq', 'Untitled session')
					}}</span>
					<span
						class="my-timetable__change-badge"
						:class="'my-timetable__change-badge--' + session.lifecycle">
						{{ statusLabel(session) }}
					</span>
					<span
						v-if="session.changeReason"
						class="my-timetable__change-reason"
						>{{ session.changeReason }}</span
					>
				</li>
			</ul>
		</section>

		<NcEmptyContent
			v-if="
				!loading && !error && sessions.length === 0 && standby.length === 0
			"
			:name="t('learniq', 'No sessions')"
			:description="emptyDescription">
			<template #icon>
				<span class="icon-calendar" />
			</template>
		</NcEmptyContent>

		<div
			v-if="!loading && !error && (sessions.length > 0 || standby.length > 0)"
			class="my-timetable__grid"
			:class="{ 'my-timetable__grid--single': mode === 'today' }">
			<section
				v-for="day in visibleDays"
				:key="day.iso"
				class="my-timetable__day"
				:class="{ 'my-timetable__day--today': day.isToday }">
				<header class="my-timetable__day-head">
					<span class="my-timetable__day-name">{{ day.weekday }}</span>
					<span class="my-timetable__day-date">{{ day.dateLabel }}</span>
				</header>
				<ul class="my-timetable__sessions">
					<li
						v-for="block in day.standby"
						:key="'standby-' + block.slotId + block.date"
						class="my-timetable__standby">
						<span class="my-timetable__session-time"
							>{{ block.startsAt }}–{{ block.endsAt }}</span
						>
						<span class="my-timetable__session-name">{{
							t('learniq', 'Standby')
						}}</span>
					</li>
					<li
						v-if="day.sessions.length === 0 && day.standby.length === 0"
						class="my-timetable__none">
						{{ t('learniq', 'No sessions') }}
					</li>
					<li
						v-for="session in day.sessions"
						:key="session.id"
						class="my-timetable__session"
						:class="{
							'my-timetable__session--cancelled':
								session.lifecycle === 'cancelled',
						}">
						<div
							:tabindex="isLearniqSession(session) ? 0 : undefined"
							:role="isLearniqSession(session) ? 'button' : undefined"
							class="my-timetable__session-main"
							:aria-label="sessionAria(session)"
							@click="openSession(session)"
							@keyup.enter="openSession(session)">
							<span class="my-timetable__session-time">{{
								timeRange(session)
							}}</span>
							<span class="my-timetable__session-name">{{
								session.title || t('learniq', 'Untitled session')
							}}</span>
							<span
								v-if="session.room"
								class="my-timetable__session-loc"
								>{{ session.room.name }}</span
							>
							<span
								v-else-if="session.location"
								class="my-timetable__session-loc"
								>{{ session.location }}</span
							>
							<span
								v-if="
									session.lifecycle
									&& session.lifecycle !== 'scheduled'
								"
								class="my-timetable__session-badge"
								:class="
									'my-timetable__session-badge--'
									+ session.lifecycle
								">
								{{ statusLabel(session) }}
							</span>
							<span
								v-if="session.cover"
								class="my-timetable__session-badge my-timetable__session-badge--cover">
								{{ t('learniq', 'Cover') }}
							</span>
							<span
								v-if="noteTopic(session)"
								class="my-timetable__session-topic">
								{{ noteTopic(session) }}
							</span>
						</div>
						<details
							v-if="session.notes && session.notes.length > 0"
							class="my-timetable__notes">
							<summary>
								<NoteTextOutline :size="14" />
								{{ notesSummary(session) }}
							</summary>
							<ul class="my-timetable__notes-list">
								<li
									v-for="note in session.notes"
									:key="note.id"
									class="my-timetable__note">
									<strong v-if="note.topic">{{
										note.topic
									}}</strong>
									<span>{{ note.text }}</span>
									<em
										v-if="note.audience === 'cover'"
										class="my-timetable__note-audience">
										{{
											t('learniq', 'For the covering teacher')
										}}
									</em>
								</li>
							</ul>
						</details>
						<NcButton
							v-if="session.canAddNote"
							class="my-timetable__session-manage"
							variant="tertiary"
							:aria-label="t('learniq', 'Add a note to this lesson')"
							@click="notingSession = session">
							{{ t('learniq', 'Add note') }}
						</NcButton>
						<NcButton
							v-if="isLearniqSession(session)"
							class="my-timetable__session-manage"
							variant="tertiary"
							:aria-label="t('learniq', 'Manage this session')"
							@click="manage(session)">
							{{ t('learniq', 'Manage') }}
						</NcButton>
					</li>
				</ul>
			</section>
		</div>

		<SubstitutionModal
			v-if="managingSession"
			:session="managingSession"
			@close="managingSession = null"
			@changed="onChanged" />

		<LessonNoteDialog
			v-if="notingSession"
			:session="notingSession"
			@close="notingSession = null"
			@saved="onChanged" />

		<TimetableCalendarFeedDialog
			v-if="subscribing"
			@close="subscribing = false" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import LessonNoteDialog from '../dialogs/LessonNoteDialog.vue'
import SubstitutionModal from '../dialogs/SubstitutionModal.vue'
import TimetableCalendarFeedDialog from '../dialogs/TimetableCalendarFeedDialog.vue'
import {
	fetchMyStandby,
	fetchMyTimetable,
	isLearniqSession,
} from '../api/timetable.js'

/**
 * Compute the Monday (00:00, local) of the week containing `date`.
 *
 * @param {Date} date Any date within the target week.
 *
 * @return {Date} Monday 00:00 of that week.
 */
function mondayOf(date) {
	const d = new Date(date.getFullYear(), date.getMonth(), date.getDate())
	const dow = (d.getDay() + 6) % 7 // 0 = Monday
	d.setDate(d.getDate() - dow)
	d.setHours(0, 0, 0, 0)
	return d
}

export default {
	name: 'MyTimetable',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		LessonNoteDialog,
		NoteTextOutline,
		SubstitutionModal,
		TimetableCalendarFeedDialog,
	},

	data() {
		return {
			loading: true,
			error: '',
			sessions: [],
			// Same-day cancel/substitute-teacher changes (dagrooster), regardless
			// of the currently viewed window — timetabling-and-substitution.
			changes: [],
			// Monday of the currently viewed week.
			weekStart: mondayOf(new Date()),
			mode: 'week',
			// The Session currently open in SubstitutionModal, or null.
			managingSession: null,
			// The lesson currently open in LessonNoteDialog, or null.
			notingSession: null,
			// Whether the calendar feed dialog is open (attendance-timetable-calendar-feed).
			subscribing: false,
			// Where the lessons come from: `learniq` Sessions, or planninq's
			// school timetable (sessions-from-planninq).
			source: 'learniq',
			// The caller's standby blocks this week (timetabling-standby-slots).
			standby: [],
		}
	},

	computed: {
		/**
		 * Exclusive end of the current week (next Monday 00:00).
		 *
		 * @return {Date} The window end.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		weekEnd() {
			const end = new Date(this.weekStart)
			end.setDate(end.getDate() + 7)
			return end
		},

		/**
		 * The seven day-buckets of the current week, each with its sessions.
		 *
		 * @return {Array<object>} Day descriptors.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		days() {
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			const out = []
			for (let i = 0; i < 7; i++) {
				const day = new Date(this.weekStart)
				day.setDate(day.getDate() + i)
				const next = new Date(day)
				next.setDate(next.getDate() + 1)
				const daySessions = this.sessions.filter((s) => {
					const ts = Date.parse(s.startsAt)
					return (
						!Number.isNaN(ts)
						&& ts >= day.getTime()
						&& ts < next.getTime()
					)
				})
				const dayIso = [
					day.getFullYear(),
					String(day.getMonth() + 1).padStart(2, '0'),
					String(day.getDate()).padStart(2, '0'),
				].join('-')
				out.push({
					standby: this.standby.filter((b) => b.date === dayIso),
					iso: day.toISOString().slice(0, 10),
					weekday: day.toLocaleDateString(undefined, { weekday: 'short' }),
					dateLabel: day.toLocaleDateString(undefined, {
						day: 'numeric',
						month: 'short',
					}),
					isToday: day.getTime() === today.getTime(),
					sessions: daySessions,
				})
			}
			return out
		},

		/**
		 * Days rendered given the today/week toggle.
		 *
		 * @return {Array<object>} The visible day descriptors.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		visibleDays() {
			if (this.mode === 'today') {
				const todayCol = this.days.find((d) => d.isToday)
				return todayCol ? [todayCol] : []
			}
			return this.days
		},

		/**
		 * Human-readable label for the viewed range.
		 *
		 * @return {string} The range label.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		rangeLabel() {
			const opts = { day: 'numeric', month: 'short' }
			const last = new Date(this.weekEnd)
			last.setDate(last.getDate() - 1)
			return (
				this.weekStart.toLocaleDateString(undefined, opts)
				+ ' – '
				+ last.toLocaleDateString(undefined, opts)
			)
		},

		/**
		 * Empty-state description — distinguishes "no cohorts" from "no sessions this week".
		 *
		 * @return {string} The description.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		emptyDescription() {
			return this.mode === 'today'
				? t('learniq', 'You have no sessions scheduled for today.')
				: t(
						'learniq',
						'You have no sessions scheduled for this week. If you are not enrolled in or teaching any cohort, your timetable stays empty.',
					)
		},
	},

	watch: {
		/**
		 * Reload the timetable whenever the viewed week changes.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		weekStart() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Whether a session is a learniq Session (opens and can be managed).
		 *
		 * @param {object} session A session from the timetable endpoint.
		 *
		 * @return {boolean} True for a learniq Session.
		 * @spec openspec/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
		 */
		isLearniqSession(session) {
			return isLearniqSession(session)
		},

		t,
		/**
		 * Load the caller's sessions for the current week window.
		 *
		 * @return {Promise<void>} Resolves once the sessions are loaded.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const result = await fetchMyTimetable(
					this.weekStart.toISOString(),
					this.weekEnd.toISOString(),
				)
				this.sessions = result.sessions
				this.changes = result.changes
				this.source = result.source
				this.standby = await fetchMyStandby(
					this.weekStart.toISOString(),
					this.weekEnd.toISOString(),
				)
			} catch (e) {
				this.error = t(
					'learniq',
					'The timetable service is unavailable. Please try again later.',
				)
				this.sessions = []
				this.changes = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Move the viewed week by `delta` weeks.
		 *
		 * @param {number} delta Number of weeks to shift (±).
		 *
		 * @return {void}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		shiftWeek(delta) {
			const next = new Date(this.weekStart)
			next.setDate(next.getDate() + delta * 7)
			this.weekStart = next
		},

		/**
		 * Switch the today/week toggle.
		 *
		 * @param {string} mode Either 'today' or 'week'.
		 *
		 * @return {void}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		setMode(mode) {
			this.mode = mode
			if (mode === 'today') {
				// Snap the viewed week to the week containing today.
				this.weekStart = mondayOf(new Date())
			}
		},

		/**
		 * Deep-link to the session detail page.
		 *
		 * @param {object} session The clicked session.
		 *
		 * @return {void}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		openSession(session) {
			// A planninq lesson is not a learniq Session: it is changed in the
			// timetable system, so it does not open here.
			if (!isLearniqSession(session)) {
				return
			}
			if (this.$router) {
				this.$router
					.push({ name: 'SessionDetail', params: { id: session.id } })
					.catch(() => {})
			}
		},

		/**
		 * Format a session's time range for display.
		 *
		 * @param {object} session The session.
		 *
		 * @return {string} A `HH:MM–HH:MM` label (or just the start).
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		timeRange(session) {
			const start = this.formatTime(session.startsAt)
			const end = this.formatTime(session.endsAt)
			if (start && end) {
				return start + '–' + end
			}
			return start
		},

		/**
		 * Format an ISO timestamp as a local `HH:MM` time.
		 *
		 * @param {string} iso The ISO 8601 timestamp.
		 *
		 * @return {string} The formatted time, or '' when unparseable.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		formatTime(iso) {
			if (!iso) {
				return ''
			}
			const ts = Date.parse(iso)
			if (Number.isNaN(ts)) {
				return ''
			}
			return new Date(ts).toLocaleTimeString(undefined, {
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		/**
		 * Accessible label for a session block.
		 *
		 * @param {object} session The session.
		 *
		 * @return {string} The aria-label.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		sessionAria(session) {
			const parts = [
				session.title || t('learniq', 'Untitled session'),
				this.timeRange(session),
			]
			if (session.location) {
				parts.push(session.location)
			}
			return parts.filter(Boolean).join(', ')
		},

		/**
		 * Human-readable lifecycle/substitution status label for a session.
		 *
		 * @param {object} session The session.
		 *
		 * @return {string} The status label.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		statusLabel(session) {
			if (session.lifecycle === 'cancelled') {
				return t('learniq', 'Cancelled')
			}
			if (session.substituteTeacherId) {
				return t('learniq', 'Substitute: {id}', {
					id: session.substituteTeacherId,
				})
			}
			return session.lifecycle || ''
		},

		/**
		 * The topic shown on a lesson: the first note that has one.
		 *
		 * @param {object} session The session.
		 *
		 * @return {string} The topic, or ''.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
		 */
		noteTopic(session) {
			const note = (session.notes || []).find((n) => n.topic)
			return note ? note.topic : ''
		},

		/**
		 * The summary line of a lesson's notes.
		 *
		 * @param {object} session The session.
		 *
		 * @return {string} The label.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
		 */
		notesSummary(session) {
			const count = (session.notes || []).length
			return count === 1
				? t('learniq', '1 note')
				: t('learniq', '{count} notes', { count })
		},

		/**
		 * Open SubstitutionModal for a session (cancel / assign substitute).
		 *
		 * @param {object} session The session to manage.
		 *
		 * @return {void}
		 * @spec openspec/specs/timetabling/spec.md#requirement-frontend-is-declarative-with-named-custom-views
		 */
		manage(session) {
			this.managingSession = session
		},

		/**
		 * Reload the timetable after a substitution/cancellation change.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/timetabling/spec.md#requirement-frontend-is-declarative-with-named-custom-views
		 */
		async onChanged() {
			await this.load()
		},
	},
}
</script>

<style scoped lang="scss">
.my-timetable {
	padding: 16px;

	&__header {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		margin-bottom: 16px;
	}

	&__title {
		margin: 0;
	}

	&__controls {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__range {
		min-width: 140px;
		text-align: center;
		font-weight: 600;
	}

	&__toggle {
		display: flex;
		gap: 4px;
		margin-inline-start: 12px;
	}

	&__loading {
		margin: 48px auto;
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(7, minmax(0, 1fr));
		gap: 8px;

		&--single {
			grid-template-columns: minmax(0, 480px);
		}
	}

	&__day {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 8px);
		background: var(--color-main-background);
		min-height: 120px;
		display: flex;
		flex-direction: column;

		&--today {
			border-color: var(--color-primary-element);
		}
	}

	&__day-head {
		display: flex;
		flex-direction: column;
		padding: 8px;
		border-bottom: 1px solid var(--color-border);
	}

	&__day-name {
		font-weight: 600;
		text-transform: capitalize;
	}

	&__day-date {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}

	&__sessions {
		list-style: none;
		margin: 0;
		padding: 6px;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__standby {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 8px;
		border-radius: var(--border-radius, 4px);
		border: 1px dashed var(--color-primary-element);
	}

	&__none {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
		padding: 4px;
	}

	&__session {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-start;
		gap: 4px;
		padding: 8px;
		border-radius: var(--border-radius, 4px);
		background: var(--color-primary-element-light);

		&--cancelled {
			opacity: 0.7;
			text-decoration: line-through;
		}
	}

	&__session-main {
		flex: 1;
		display: flex;
		flex-direction: column;
		gap: 2px;
		cursor: pointer;

		&:hover,
		&:focus {
			outline: 2px solid var(--color-primary-element);
		}
	}

	&__session-manage {
		flex-shrink: 0;
	}

	&__session-time {
		font-size: 0.8em;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	&__session-name {
		font-weight: 600;
	}

	&__session-topic {
		font-size: 0.8em;
		font-style: italic;
	}

	&__notes {
		flex-basis: 100%;
		font-size: 0.85em;

		summary {
			cursor: pointer;
		}
	}

	&__notes-list {
		list-style: none;
		margin: 4px 0 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__note {
		display: flex;
		flex-direction: column;
	}

	&__note-audience {
		color: var(--color-text-maxcontrast);
	}

	&__session-loc {
		font-size: 0.8em;
		color: var(--color-text-maxcontrast);
	}

	&__session-badge {
		align-self: flex-start;
		font-size: 0.75em;
		font-weight: 600;
		padding: 1px 6px;
		border-radius: var(--border-radius-pill, 12px);
		background: var(--color-warning);
		color: var(--color-main-text);

		&--cancelled {
			background: var(--color-error);
			color: white;
		}

		&--cover {
			background: var(--color-primary-element);
			color: var(--color-primary-element-text);
		}
	}

	&__changes {
		margin-bottom: 16px;
		padding: 12px;
		border: 1px solid var(--color-warning);
		border-radius: var(--border-radius-large, 8px);
		background: var(--color-background-hover);
	}

	&__changes-title {
		margin: 0 0 8px;
		font-size: 0.95em;
		font-weight: 600;
	}

	&__changes-list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__change {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		font-size: 0.9em;
	}

	&__change-name {
		font-weight: 600;
	}

	&__change-badge {
		font-size: 0.75em;
		font-weight: 600;
		padding: 1px 6px;
		border-radius: var(--border-radius-pill, 12px);
		background: var(--color-error);
		color: white;
	}

	&__change-reason {
		color: var(--color-text-maxcontrast);
	}
}
</style>
