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
import { NcButton, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { candidateOptions } from '../utils/standby.js'

export default {
	name: 'SubstitutionModal',

	components: {
		NcButton,
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
			return this.mode === 'cancel'
				? t('learniq', 'Cancel session')
				: t('learniq', 'Assign substitute')
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

			this.saving = true
			this.error = ''

			const body = {
				lifecycle:
					this.mode === 'cancel' ? 'cancelled' : this.session.lifecycle,

				changeReasonKind: this.changeReasonKind,
				changeReason: this.changeReason || null,
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
