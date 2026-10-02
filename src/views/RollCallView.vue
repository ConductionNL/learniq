<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RollCallView: the day's register of one group (route /attendance/roll-call,
 attendance-roll-call).

 A group teacher lands on their own group and today; coordinators and
 administration-managers pick any group. Every pupil starts as present (or
 as the saved mark, or as an approved absence report says). One tap, or one
 key while a pupil's row has focus, per exception: P present, L late,
 T absent with permission, O absent without permission; arrow keys move
 between pupils. One save writes the day through /api/attendance/roll-call;
 the server decides who may open and save which group and day.

 @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
-->
<template>
	<div class="roll-call">
		<h2 class="roll-call__title">
			{{ t('learniq', 'Register') }}
			<span v-if="register.cohortName">· {{ register.cohortName }}</span>
		</h2>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<NcEmptyContent
			v-else-if="register.cohorts.length === 0"
			:name="t('learniq', 'No group to take the register for')"
			:description="t('learniq', 'You are not the teacher of a group yet.')" />

		<template v-else>
			<div class="roll-call__controls">
				<NcSelect
					v-if="register.cohorts.length > 1"
					class="roll-call__group"
					:inputLabel="t('learniq', 'Group')"
					:options="register.cohorts"
					label="name"
					:clearable="false"
					:modelValue="currentCohort"
					@update:modelValue="chooseCohort" />
				<div class="roll-call__date">
					<label for="roll-call-date">{{ t('learniq', 'Day') }}</label>
					<input
						id="roll-call-date"
						type="date"
						:value="register.date"
						:max="register.today"
						@change="chooseDate($event.target.value)" />
					<NcButton
						v-if="register.date !== register.today"
						variant="tertiary"
						@click="chooseDate(register.today)">
						{{ t('learniq', 'Today') }}
					</NcButton>
				</div>
				<NcSelect
					v-if="register.sessions.length > 1"
					class="roll-call__lesson"
					:inputLabel="t('learniq', 'Lesson')"
					:options="register.sessions"
					label="title"
					:clearable="false"
					:modelValue="currentSession"
					@update:modelValue="chooseSession" />
			</div>

			<NcNoteCard v-if="register.locked === 'past-saved'" type="info">
				{{
					t(
						'learniq',
						"This day's register is saved. Ask a coordinator to change it.",
					)
				}}
			</NcNoteCard>
			<NcNoteCard v-else-if="register.locked === 'future'" type="info">
				{{
					t(
						'learniq',
						'You can only take the register for today or an earlier day.',
					)
				}}
			</NcNoteCard>
			<p v-else-if="!register.sessionId" class="roll-call__hint">
				{{
					t(
						'learniq',
						'There is no lesson on this day yet. Saving the register adds one.',
					)
				}}
			</p>

			<p class="roll-call__counts" aria-live="polite">
				{{ countsText }}
			</p>
			<p v-if="register.editable" :id="hintId" class="roll-call__hint">
				{{
					t(
						'learniq',
						'Everyone starts as present. Keys on a pupil: P present, L late, T absent with permission, O absent without permission. Arrow keys go to the next pupil.',
					)
				}}
			</p>

			<NcEmptyContent
				v-if="pupils.length === 0"
				:name="t('learniq', 'No learners in this group')"
				:description="
					t('learniq', 'Add learners to the group of this lesson first.')
				" />

			<form v-else @submit.prevent="save">
				<ul class="roll-call__list">
					<li
						v-for="(pupil, index) in pupils"
						:key="pupil.learnerId"
						ref="rows"
						class="roll-call__pupil"
						:class="'roll-call__pupil--' + pupil.status"
						tabindex="0"
						:aria-label="pupilLabel(pupil)"
						:aria-describedby="register.editable ? hintId : null"
						@keydown="onKey($event, index)">
						<div class="roll-call__who">
							<span class="roll-call__name">{{ pupil.name }}</span>
							<span
								v-if="pupil.markedVia === 'self-check-in'"
								class="roll-call__badge">
								{{ t('learniq', 'checked in') }}
							</span>
							<span v-if="pupil.report" class="roll-call__report">
								{{ reportText(pupil.report) }}
							</span>
						</div>

						<div
							class="roll-call__statuses"
							role="group"
							:aria-label="
								t('learniq', 'Mark for {name}', { name: pupil.name })
							">
							<button
								v-for="status in statuses"
								:key="status"
								type="button"
								class="roll-call__status"
								:class="{
									'roll-call__status--on': pupil.status === status,
								}"
								:aria-pressed="
									pupil.status === status ? 'true' : 'false'
								"
								:disabled="!register.editable"
								@click="mark(index, status)">
								{{ statusLabel(status) }}
							</button>
						</div>

						<div
							v-if="pupil.status === 'late'"
							class="roll-call__detail">
							<span class="roll-call__detail-label">{{
								t('learniq', 'Minutes late')
							}}</span>
							<button
								v-for="minutes in quickMinutes"
								:key="minutes"
								type="button"
								class="roll-call__chip"
								:class="{
									'roll-call__chip--on':
										Number(pupil.lateMinutes) === minutes,
								}"
								:aria-pressed="
									Number(pupil.lateMinutes) === minutes
										? 'true'
										: 'false'
								"
								:disabled="!register.editable"
								@click="setField(index, 'lateMinutes', minutes)">
								{{ minutes }}
							</button>
							<label class="roll-call__other">
								<span>{{
									t('learniq', 'Other number of minutes')
								}}</span>
								<input
									type="number"
									min="1"
									max="600"
									step="1"
									inputmode="numeric"
									:value="pupil.lateMinutes"
									:disabled="!register.editable"
									@input="
										setField(
											index,
											'lateMinutes',
											$event.target.value === ''
												? ''
												: Number($event.target.value),
										)
									" />
							</label>
						</div>

						<div
							v-if="pupil.status === 'absent-excused'"
							class="roll-call__detail">
							<label class="roll-call__reason">
								<span>{{ t('learniq', 'Reason') }}</span>
								<select
									:value="pupil.absenceReasonKind"
									:disabled="!register.editable"
									@change="
										setField(
											index,
											'absenceReasonKind',
											$event.target.value,
										)
									">
									<option
										v-for="kind in reasonKinds"
										:key="kind"
										:value="kind">
										{{ reasonLabel(kind) }}
									</option>
								</select>
							</label>
						</div>

						<div
							v-if="pupil.status !== 'present'"
							class="roll-call__detail">
							<label class="roll-call__note">
								<span>{{ t('learniq', 'Note (optional)') }}</span>
								<input
									type="text"
									maxlength="500"
									:value="pupil.reason"
									:disabled="!register.editable"
									@input="
										setField(
											index,
											'reason',
											$event.target.value,
										)
									" />
							</label>
						</div>
					</li>
				</ul>

				<div class="roll-call__save">
					<NcNoteCard v-if="saveError" type="error">
						{{ saveError }}
					</NcNoteCard>
					<NcNoteCard v-if="saved" type="success">
						{{ t('learniq', 'The register is saved.') }}
					</NcNoteCard>
					<NcButton
						type="submit"
						variant="primary"
						:disabled="!register.editable || saving">
						{{
							saving
								? t('learniq', 'Saving…')
								: t('learniq', 'Save the register')
						}}
					</NcButton>
				</div>
			</form>
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
import {
	ABSENCE_REASON_KINDS,
	incompleteMarks,
	marksToSave,
	QUICK_LATE_MINUTES,
	ROLL_CALL_STATUSES,
	rollCallCounts,
	withStatus,
} from '../utils/rollCall.js'

const API = '/apps/learniq/api/attendance/roll-call'

/** Keys that mark a pupil while their row has focus. */
const KEYS = {
	p: 'present',
	l: 'late',
	t: 'absent-excused',
	o: 'absent-unexcused',
}

export default {
	name: 'RollCallView',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			loadError: '',
			register: {
				cohorts: [],
				sessions: [],
				pupils: [],
				date: '',
				today: '',
			},

			pupils: [],
			saving: false,
			saveError: '',
			saved: false,
			statuses: ROLL_CALL_STATUSES,
			quickMinutes: QUICK_LATE_MINUTES,
			reasonKinds: ABSENCE_REASON_KINDS,
			hintId: 'roll-call-keys',
		}
	},

	computed: {
		/**
		 * @return {object|null} The open group, as an NcSelect option.
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
		 */
		currentCohort() {
			return (
				this.register.cohorts.find((c) => c.id === this.register.cohortId)
				?? null
			)
		},

		/**
		 * @return {object|null} The open lesson, as an NcSelect option.
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		currentSession() {
			return (
				this.register.sessions.find((s) => s.id === this.register.sessionId)
				?? null
			)
		},

		/**
		 * @return {string} The counts line above the list.
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		countsText() {
			const c = rollCallCounts(this.pupils)
			return this.t(
				'learniq',
				'{present} present, {late} late, {authorised} absent with permission, {unauthorised} absent without permission',
				{
					present: c.present,
					late: c.late,
					authorised: c.absentAuthorised,
					unauthorised: c.absentUnauthorised,
				},
			)
		},
	},

	watch: {
		/**
		 * Load again when the query changes (group, day or lesson chosen).
		 *
		 * @return {void}
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		'$route.query': function () {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the register for the group, day and lesson in the query.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		async load() {
			this.loading = true
			this.loadError = ''
			this.saved = false
			this.saveError = ''
			const query = this.$route?.query ?? {}
			try {
				const response = await axios.get(generateUrl(API), {
					params: {
						cohortId: query.cohortId || undefined,
						date: query.date || undefined,
						sessionId: query.sessionId || undefined,
					},
				})
				this.apply(response.data)
			} catch (error) {
				this.loadError =
					error?.response?.data?.error
					|| this.t('learniq', 'The register could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show a register the server returned.
		 *
		 * @param {object} data The register.
		 * @return {void}
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		apply(data) {
			this.register = {
				cohorts: [],
				sessions: [],
				pupils: [],
				...(data ?? {}),
			}
			this.pupils = (this.register.pupils ?? []).map((p) => ({ ...p }))
		},

		/**
		 * Open another group, day or lesson through the query.
		 *
		 * @param {object} change Query values to change.
		 * @return {void}
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		go(change) {
			const query = {
				cohortId: this.register.cohortId || undefined,
				date: this.register.date || undefined,
				...change,
			}
			Object.keys(query).forEach(
				(k) => query[k] === undefined && delete query[k],
			)
			if (this.$router) {
				this.$router
					.replace({ path: this.$route.path, query })
					.catch(() => {})
			}
		},

		/**
		 * @param {object} cohort The chosen group option.
		 * @return {void}
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
		 */
		chooseCohort(cohort) {
			if (cohort?.id) this.go({ cohortId: cohort.id, sessionId: undefined })
		},

		/**
		 * @param {string} date The chosen day.
		 * @return {void}
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-the-register-can-be-changed-the-same-day
		 */
		chooseDate(date) {
			if (date) this.go({ date, sessionId: undefined })
		},

		/**
		 * @param {object} session The chosen lesson option.
		 * @return {void}
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		chooseSession(session) {
			if (session?.id) this.go({ sessionId: session.id })
		},

		/**
		 * Give a pupil a mark.
		 *
		 * @param {number} index The pupil's row.
		 * @param {string} status The mark.
		 * @return {void}
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		mark(index, status) {
			if (!this.register.editable) return
			this.pupils.splice(index, 1, withStatus(this.pupils[index], status))
			this.saved = false
		},

		/**
		 * Change one field of a pupil's mark.
		 *
		 * @param {number} index The pupil's row.
		 * @param {string} field The field.
		 * @param {string|number} value The value.
		 * @return {void}
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		setField(index, field, value) {
			this.pupils.splice(index, 1, { ...this.pupils[index], [field]: value })
			this.saved = false
		},

		/**
		 * The keyboard on a pupil's row: a letter marks, the arrows move.
		 *
		 * @param {KeyboardEvent} event The key event.
		 * @param {number} index The pupil's row.
		 * @return {void}
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		onKey(event, index) {
			if (
				event.target !== event.currentTarget
				|| event.ctrlKey
				|| event.metaKey
				|| event.altKey
			)
				return
			const rows = this.$refs.rows ?? []
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault()
				const next = index + (event.key === 'ArrowDown' ? 1 : -1)
				rows[next]?.focus()
				return
			}
			const status = KEYS[event.key.toLowerCase()]
			if (status) {
				event.preventDefault()
				this.mark(index, status)
			}
		},

		/**
		 * Save the register.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		async save() {
			this.saveError = ''
			this.saved = false
			const incomplete = incompleteMarks(this.pupils)
			if (incomplete.length > 0) {
				this.saveError = this.t(
					'learniq',
					'Check the marks of: {names}. A late mark needs its minutes and an absence with permission needs a reason.',
					{
						names: incomplete.map((p) => p.name).join(', '),
					},
				)
				return
			}
			this.saving = true
			try {
				const response = await axios.post(generateUrl(API), {
					cohortId: this.register.cohortId,
					date: this.register.date,
					sessionId: this.register.sessionId || null,
					marks: marksToSave(this.pupils),
				})
				this.apply(response.data)
				this.saved = true
			} catch (error) {
				this.saveError =
					error?.response?.data?.error
					|| this.t(
						'learniq',
						'The register could not be saved. Try again.',
					)
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param {string} status A mark.
		 * @return {string} Its label.
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		statusLabel(status) {
			switch (status) {
				case 'present':
					return this.t('learniq', 'Present')
				case 'late':
					return this.t('learniq', 'Late')
				case 'absent-excused':
					return this.t('learniq', 'Absent with permission')
				case 'absent-unexcused':
					return this.t('learniq', 'Absent without permission')
				case 'left-early':
					return this.t('learniq', 'Left early')
				default:
					return status
			}
		},

		/**
		 * @param {string} kind A reason for absence.
		 * @return {string} Its label.
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		reasonLabel(kind) {
			return (
				{
					illness: this.t('learniq', 'Ill'),
					appointment: this.t('learniq', 'Appointment'),
					other: this.t('learniq', 'Other'),
				}[kind] ?? kind
			)
		},

		/**
		 * @param {object} pupil A pupil row.
		 * @return {string} What a screen reader hears on the row.
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
		 */
		pupilLabel(pupil) {
			let label = this.t('learniq', '{name}: {mark}', {
				name: pupil.name,
				mark: this.statusLabel(pupil.status),
			})
			if (pupil.status === 'late' && pupil.lateMinutes) {
				label +=
					', '
					+ this.t('learniq', '{minutes} minutes', {
						minutes: pupil.lateMinutes,
					})
			}
			return label
		},

		/**
		 * @param {object} report The absence report covering the day.
		 * @return {string} The line shown under the pupil's name.
		 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-an-approved-absence-report-fills-in-the-register
		 */
		reportText(report) {
			if (report.lifecycle === 'approved') {
				return this.t('learniq', 'Absence report approved: {reason}', {
					reason: report.reason,
				})
			}
			return this.t(
				'learniq',
				'Absence report waiting for a decision: {reason}',
				{ reason: report.reason },
			)
		},
	},
}
</script>

<style scoped>
.roll-call {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-inline-size: 64rem;
}

.roll-call__controls {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 3);
}

.roll-call__group,
.roll-call__lesson {
	min-inline-size: 14rem;
}

.roll-call__date {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.roll-call__counts {
	font-weight: bold;
}

.roll-call__hint {
	color: var(--color-text-maxcontrast);
}

.roll-call__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.roll-call__pupil {
	display: grid;
	grid-template-columns: minmax(10rem, 1fr) auto;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	align-items: center;
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	border-block-end: 1px solid var(--color-border);
	border-inline-start: 4px solid transparent;
}

.roll-call__pupil:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.roll-call__pupil--late {
	border-inline-start-color: var(--color-warning);
}

.roll-call__pupil--absent-excused {
	border-inline-start-color: var(--color-info, var(--color-primary-element));
}

.roll-call__pupil--absent-unexcused {
	border-inline-start-color: var(--color-error);
}

.roll-call__who {
	display: flex;
	flex-direction: column;
}

.roll-call__name {
	font-weight: bold;
}

.roll-call__report {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.roll-call__badge {
	align-self: flex-start;
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 12px);
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 0.85em;
}

.roll-call__statuses {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 4px);
}

.roll-call__status,
.roll-call__chip {
	min-block-size: 44px;
	padding-inline: calc(var(--default-grid-baseline, 4px) * 3);
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.roll-call__status--on,
.roll-call__chip--on {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.roll-call__status:disabled,
.roll-call__chip:disabled {
	cursor: default;
	opacity: 0.7;
}

.roll-call__detail {
	grid-column: 1 / -1;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.roll-call__other,
.roll-call__reason,
.roll-call__note {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline, 4px);
}

.roll-call__other input {
	inline-size: 6rem;
}

.roll-call__note input {
	min-inline-size: 14rem;
}

.roll-call__save {
	position: sticky;
	inset-block-end: 0;
	padding-block: calc(var(--default-grid-baseline, 4px) * 3);
	background-color: var(--color-main-background);
}

@media (max-width: 720px) {
	.roll-call__pupil {
		grid-template-columns: 1fr;
	}

	.roll-call__status {
		flex: 1 1 45%;
	}

	.roll-call__note input {
		min-inline-size: 0;
		inline-size: 100%;
	}
}
</style>
