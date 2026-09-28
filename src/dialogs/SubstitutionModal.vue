<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SubstitutionModal — timetabling-and-substitution.

 Mark a Session cancelled, or assign a substitute teacher, always requiring a
 reason (SessionChangeGuard is the actual server-side enforcement; this
 dialog only pre-empts a doomed request with the same reasoning surfaced to
 the user, mirroring ComposeReportPeriodModal's posture).

 Both actions are triggered as a plain PUT that sets `lifecycle` to the
 target value alongside the substitution fields — the SAME calling
 convention this app already uses for other self-loop transitions (e.g.
 ConferenceScheduleBoard's `regenerate`, PUT {lifecycle: 'scheduled'}):
 `cancel` sets `lifecycle: 'cancelled'`; `substitute-teacher` re-sends the
 Session's CURRENT lifecycle value unchanged (a true self-loop) — the
 register resolves this to the `substitute-teacher` transition when the
 Session is `scheduled`, or `substitute-teacher-in-progress` when
 `in-progress`, per which `from` state matches.

 Opened from MyTimetable.vue for a Session the caller may manage.

 @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-frontend-is-declarative-with-named-custom-views
 @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason
 @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-cancelling-without-a-reason-is-refused
 "Apply to more weeks" lists the lessons of the same weekly slot and sends
 one batch to POST /api/session-change-batches; the server runs each lesson
 through the same transition and guard, and the dialog shows the outcome per
 lesson.
 @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
-->
<template>
	<NcDialog
		:open="true"
		:name="dialogTitle"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<div class="substitution-modal">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div
				class="substitution-modal__mode"
				role="group"
				:aria-label="t('learniq', 'Action')">
				<NcButton
					:variant="mode === 'cancel' ? 'primary' : 'secondary'"
					:disabled="saving"
					@click="mode = 'cancel'">
					{{ t('learniq', 'Cancel session') }}
				</NcButton>
				<NcButton
					:variant="mode === 'substitute' ? 'primary' : 'secondary'"
					:disabled="saving"
					@click="mode = 'substitute'">
					{{ t('learniq', 'Assign substitute teacher') }}
				</NcButton>
				<NcButton
					:variant="mode === 'room' ? 'primary' : 'secondary'"
					:disabled="saving"
					@click="selectRoomMode">
					{{ t('learniq', 'Other room') }}
				</NcButton>
			</div>

			<div class="substitution-modal__field">
				<label for="substitution-reason-kind">{{
					t('learniq', 'Reason')
				}}</label>
				<NcSelect
					id="substitution-reason-kind"
					v-model="changeReasonKind"
					:inputLabel="t('learniq', 'Reason')"
					:options="reasonOptions"
					:reduce="(opt) => opt.value"
					:clearable="false" />
			</div>

			<div v-if="mode === 'substitute'" class="substitution-modal__field">
				<NcSelect
					v-model="substituteTeacherId"
					:options="candidateOptions"
					:reduce="(opt) => opt.value"
					:loading="loadingCandidates"
					:taggable="true"
					:inputLabel="t('learniq', 'Substitute teacher')"
					:placeholder="
						t('learniq', 'Teachers on standby are listed first')
					" />
				<p class="substitution-modal__hint">
					{{
						t(
							'learniq',
							'Teachers on standby at this time come first, then teachers who work today and are free. You can also type the user name of another colleague.',
						)
					}}
				</p>
			</div>

			<div v-if="mode === 'room'" class="substitution-modal__field">
				<NcSelect
					v-model="roomId"
					:inputLabel="t('learniq', 'Room')"
					:options="roomOptions"
					:reduce="(opt) => opt.value"
					:loading="loadingRooms"
					:clearable="false" />
			</div>
			<div class="substitution-modal__field">
				<label for="substitution-note">{{
					t('learniq', 'Note (optional)')
				}}</label>
				<textarea
					id="substitution-note"
					v-model="changeReason"
					class="substitution-modal__textarea"
					rows="3" />
			</div>
			<div class="substitution-modal__weeks">
				<NcCheckboxRadioSwitch
					:modelValue="moreWeeks"
					:disabled="saving"
					data-testid="apply-more-weeks"
					@update:modelValue="toggleMoreWeeks">
					{{ t('learniq', 'Apply to more weeks') }}
				</NcCheckboxRadioSwitch>
				<template v-if="moreWeeks">
					<div class="substitution-modal__field">
						<label for="substitution-until">{{
							t('learniq', 'Until')
						}}</label>
						<input
							id="substitution-until"
							v-model="until"
							type="date"
							class="substitution-modal__input"
							@change="loadSeries" />
					</div>
					<p v-if="loadingSeries">
						{{ t('learniq', 'Loading lessons…') }}
					</p>
					<p v-else-if="series.length === 0">
						{{ t('learniq', 'No other lessons in this weekly slot.') }}
					</p>
					<ul
						v-else
						class="substitution-modal__series"
						data-testid="series-list">
						<li v-for="lesson in series" :key="lesson.id">
							<NcCheckboxRadioSwitch
								:modelValue="selectedIds.includes(lesson.id)"
								:disabled="!lesson.changeable || saving"
								@update:modelValue="
									(on) => toggleLesson(lesson.id, on)
								">
								{{ formatDate(lesson.startsAt) }}
								<span v-if="!lesson.changeable">
									({{
										t(
											'learniq',
											'already took place or cancelled',
										)
									}})
								</span>
							</NcCheckboxRadioSwitch>
						</li>
					</ul>
				</template>
			</div>
			<div
				v-if="results"
				class="substitution-modal__results"
				data-testid="batch-results">
				<h3>{{ t('learniq', 'Result') }}</h3>
				<ul>
					<li v-for="result in results" :key="result.sessionId">
						{{ formatDate(result.startsAt) }}:
						<strong v-if="result.outcome === 'applied'">{{
							t('learniq', 'changed')
						}}</strong>
						<span v-else
							>{{ t('learniq', 'not changed') }} ({{
								result.reason
							}})</span
						>
					</li>
				</ul>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('learniq', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSubmit || saving"
				@click="submit">
				{{ saving ? t('learniq', 'Saving…') : submitLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { candidateOptions } from '../utils/standby.js'

export default {
	name: 'SubstitutionModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcSelect,
	},

	props: {
		session: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'changed'],

	data() {
		return {
			mode: 'cancel',
			changeReasonKind: 'teacher-absence',
			changeReason: '',
			substituteTeacherId: '',
			roomId: null,
			rooms: [],
			loadingRooms: false,
			moreWeeks: false,
			until: '',
			series: [],
			selectedIds: [],
			loadingSeries: false,
			results: null,
			saving: false,
			error: '',
			// timetabling-standby-slots: who can cover, standby first.
			candidates: [],
			loadingCandidates: false,
		}
	},

	computed: {
		/**
		 * Dialog title.
		 *
		 * @return {string}
		 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason
		 */
		dialogTitle() {
			return t('learniq', 'Manage "{title}"', {
				title: this.session.title || t('learniq', 'Untitled session'),
			})
		},

		/**
		 * Reason-kind options, matching Session.changeReasonKind's declared enum.
		 *
		 * @return {Array<{value:string,label:string}>}
		 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason
		 */
		reasonOptions() {
			return [
				{ value: 'teacher-absence', label: t('learniq', 'Teacher absence') },
				{
					value: 'room-unavailable',
					label: t('learniq', 'Room unavailable'),
				},
				{
					value: 'timetable-change',
					label: t('learniq', 'Timetable change'),
				},
				{ value: 'other', label: t('learniq', 'Other') },
			]
		},

		/**
		 * Submit button label for the current mode.
		 *
		 * @return {string}
		 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason
		 */
		submitLabel() {
			if (this.mode === 'cancel') return t('learniq', 'Cancel session')
			if (this.mode === 'room') return t('learniq', 'Change room')
			return t('learniq', 'Assign substitute')
		},

		/**
		 * Room options for the room mode.
		 *
		 * @return {Array<{value:string,label:string}>}
		 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
		 */
		roomOptions() {
			return this.rooms.map((room) => ({
				value: room.id,
				label: room.name || room.code || room.id,
			}))
		},

		/**
		 * Whether the form has the minimum required fields for the current mode.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason
		 */
		canSubmit() {
			if (!this.changeReasonKind) return false
			if (
				this.mode === 'substitute'
				&& !String(this.substituteTeacherId || '').trim()
			)
				return false
			if (this.mode === 'room' && !this.roomId) return false
			if (this.moreWeeks && this.selectedIds.length === 0) return false
			return true
		},

		/**
		 * The substitution candidates as select options, standby first.
		 *
		 * @return {Array<{value:string,label:string,group:string}>}
		 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
		 */
		candidateOptions() {
			return candidateOptions(this.candidates, (text, vars) =>
				t('learniq', text, vars),
			)
		},
	},

	watch: {
		/**
		 * Load the candidates the first time the substitute mode opens.
		 *
		 * @param {string} mode The new mode.
		 * @return {void}
		 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
		 */
		mode(mode) {
			if (mode === 'substitute' && this.candidates.length === 0) {
				this.loadCandidates()
			}
		},
	},

	methods: {
		t,

		/**
		 * Load who can cover this lesson. Learniq suggests; the user chooses.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
		 */
		async loadCandidates() {
			this.loadingCandidates = true
			try {
				const res = await axios.get(
					generateUrl('/apps/learniq/api/substitution/candidates'),
					{ params: { sessionId: this.session.id } },
				)
				this.candidates = res.data?.candidates || []
			} catch {
				this.candidates = []
			} finally {
				this.loadingCandidates = false
			}
		},

		/**
		 * Submit the cancel or substitute-teacher change.
		 *
		 * `lifecycle` is sent as `cancelled` for the cancel mode, or the
		 * Session's OWN current lifecycle value (a self-loop) for the
		 * substitute mode — never a client-chosen action name.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason
		 */
		async submit() {
			if (!this.canSubmit) return
			if (this.moreWeeks) {
				await this.submitBatch()
				return
			}

			this.saving = true
			this.error = ''

			const body = {
				changeReasonKind: this.changeReasonKind,
				changeReason: this.changeReason || null,
			}
			if (this.mode === 'room') {
				body.roomId = this.roomId
			} else {
				body.lifecycle =
					this.mode === 'cancel' ? 'cancelled' : this.session.lifecycle
			}
			if (this.mode === 'substitute') {
				body.substituteTeacherId = String(this.substituteTeacherId).trim()
			}

			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/learniq/session/{id}',
					{ id: this.session.id },
				)
				await axios.put(url, body)
				this.$emit('changed')
				this.$emit('close')
			} catch (e) {
				console.error('[SubstitutionModal] submit failed', e)
				this.error = t(
					'learniq',
					'Could not save this change. Please check the reason and try again.',
				)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Apply the change to every ticked lesson in one batch and show the
		 * outcome per lesson.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-every-lesson-in-a-batch-passes-the-same-checks
		 */
		async submitBatch() {
			this.saving = true
			this.error = ''
			const kinds = {
				cancel: 'cancel',
				substitute: 'substitute',
				room: 'room',
			}
			try {
				const { data } = await axios.post(
					generateUrl('/apps/learniq/api/session-change-batches'),
					{
						kind: kinds[this.mode],
						sessionIds: this.selectedIds,
						changeReasonKind: this.changeReasonKind,
						changeReason: this.changeReason || null,
						substituteTeacherId:
							this.mode === 'substitute'
								? String(this.substituteTeacherId).trim()
								: null,
						roomId: this.mode === 'room' ? this.roomId : null,
					},
				)
				this.results = data.results || []
				this.$emit('changed')
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| t(
						'learniq',
						'Could not save this change. Please check the reason and try again.',
					)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Switch to the room mode and load the rooms once.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
		 */
		async selectRoomMode() {
			this.mode = 'room'
			if (this.rooms.length > 0 || this.loadingRooms) return
			this.loadingRooms = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/objects/learniq/room'),
					{ params: { _limit: 500 } },
				)
				this.rooms = data?.results || []
			} catch {
				this.rooms = []
			} finally {
				this.loadingRooms = false
			}
		},

		/**
		 * Turn "Apply to more weeks" on or off.
		 *
		 * @param {boolean} on Whether it is on.
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
		 */
		async toggleMoreWeeks(on) {
			this.moreWeeks = on
			this.results = null
			if (on) {
				this.selectedIds = [this.session.id]
				await this.loadSeries()
			}
		},

		/**
		 * Load the lessons of the same weekly slot up to the until date.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
		 */
		async loadSeries() {
			this.loadingSeries = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/learniq/api/sessions/{id}/series', {
						id: this.session.id,
					}),
					{ params: this.until ? { until: this.until } : {} },
				)
				this.series = data?.sessions || []
				const open = this.series
					.filter((lesson) => lesson.changeable)
					.map((lesson) => lesson.id)
				this.selectedIds = this.selectedIds.filter((id) => open.includes(id))
			} catch {
				this.series = []
			} finally {
				this.loadingSeries = false
			}
		},

		/**
		 * Tick or untick one lesson.
		 *
		 * @param {string} id The lesson.
		 * @param {boolean} on Whether it is ticked.
		 * @return {void}
		 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
		 */
		toggleLesson(id, on) {
			this.selectedIds = on
				? [...new Set([...this.selectedIds, id])]
				: this.selectedIds.filter((other) => other !== id)
		},

		/**
		 * A lesson's date and time for the list.
		 *
		 * @param {string} iso The start date-time.
		 * @return {string}
		 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
		 */
		formatDate(iso) {
			if (!iso) return ''
			const date = new Date(iso)
			if (Number.isNaN(date.getTime())) return iso
			return date.toLocaleString(undefined, {
				weekday: 'short',
				day: 'numeric',
				month: 'short',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.substitution-modal {
	min-width: 380px;
	padding: 8px 4px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.substitution-modal__mode {
	display: flex;
	gap: 8px;
}

.substitution-modal__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.substitution-modal__series {
	display: flex;
	flex-direction: column;
	gap: 2px;
	max-height: 240px;
	overflow-y: auto;
}

.substitution-modal__results ul {
	padding-inline-start: 16px;
	list-style: disc;
}

.substitution-modal__field label {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.substitution-modal__hint {
	margin: 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.substitution-modal__textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
}
</style>
