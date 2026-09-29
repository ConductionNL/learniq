<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AttendanceRegisterView: take the register for one lesson session
 (route /sessions/:sessionId/attendance, learniq#947).

 Lists every learner of the session's cohort with their saved mark, or
 "present" when none is saved yet, and writes one AttendanceRecord per
 learner: created when new, updated when a mark already exists.

 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<div class="attendance-register">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<template v-else>
			<h2>{{ t('learniq', 'Take the register') }}</h2>
			<p class="attendance-register__session">
				{{ session.title }} · {{ formatDate(session.startsAt) }}
			</p>
			<SelfCheckInPanel
				v-if="rows.length > 0"
				:session="session"
				@checkedIn="mergeCheckIns" />

			<NcEmptyContent
				v-if="rows.length === 0"
				:name="t('learniq', 'No learners in this group')"
				:description="
					t('learniq', 'Add learners to the group of this lesson first.')
				" />

			<form v-else @submit.prevent="save">
				<div class="attendance-register__bulk">
					<NcButton variant="secondary" @click="markAll('present')">
						{{ t('learniq', 'Mark everyone present') }}
					</NcButton>
				</div>
				<table class="attendance-register__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('learniq', 'Learner') }}
							</th>
							<th scope="col">
								{{ t('learniq', 'Status') }}
							</th>
							<th scope="col">
								{{ t('learniq', 'Note (optional)') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, index) in rows" :key="row.learnerId">
							<th scope="row">
								{{ learnerName(row.learnerId) }}
								<span
									v-if="row.markedVia === 'self-check-in'"
									class="attendance-register__self">
									{{ t('learniq', 'checked in') }}
								</span>
							</th>
							<td>
								<select
									:id="'att-status-' + index"
									v-model="row.status"
									:aria-label="
										t('learniq', 'Status for {name}', {
											name: learnerName(row.learnerId),
										})
									">
									<option
										v-for="status in statuses"
										:key="status"
										:value="status">
										{{ statusLabel(status) }}
									</option>
								</select>
							</td>
							<td>
								<input
									v-model="row.reason"
									type="text"
									:aria-label="
										t('learniq', 'Note for {name}', {
											name: learnerName(row.learnerId),
										})
									" />
							</td>
						</tr>
					</tbody>
				</table>

				<NcNoteCard v-if="saveError" type="error">
					{{ saveError }}
				</NcNoteCard>
				<NcNoteCard v-if="saved" type="success">
					{{ t('learniq', 'The register is saved.') }}
				</NcNoteCard>

				<NcButton type="submit" variant="primary" :disabled="saving">
					{{ t('learniq', 'Save the register') }}
				</NcButton>
			</form>
		</template>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import SelfCheckInPanel from '../components/SelfCheckInPanel.vue'
import {
	ATTENDANCE_STATUSES,
	attendanceRecord,
	attendanceRows,
	listRows,
	objectsUrl,
	oneObject,
	registerRowsToSave,
} from '../utils/customPages.js'

export default {
	name: 'AttendanceRegisterView',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		SelfCheckInPanel,
	},

	props: {
		/** Session UUID from the route. */
		sessionId: { type: String, required: true },
	},

	data() {
		return {
			loading: true,
			loadError: '',
			session: {},
			rows: [],
			names: {},
			saving: false,
			saveError: '',
			saved: false,
			statuses: ATTENDANCE_STATUSES,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the session, its cohort's learners and the saved marks.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async load() {
			this.loading = true
			try {
				const session = oneObject(
					(
						await axios.get(
							generateUrl(objectsUrl('session', this.sessionId)),
						)
					).data,
				)
				this.session = session
				let learnerIds = []
				if (session.cohortId) {
					const cohort = oneObject(
						(
							await axios.get(
								generateUrl(objectsUrl('cohort', session.cohortId)),
							)
						).data,
					)
					learnerIds = cohort.learnerIds ?? []
				}
				this.rows = attendanceRows(learnerIds, await this.loadRecords())
				await this.loadNames(learnerIds)
			} catch {
				this.loadError = this.t(
					'learniq',
					'This lesson could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * The saved AttendanceRecords of this lesson.
		 *
		 * @return {Promise<object[]>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async loadRecords() {
			return listRows(
				(
					await axios.get(generateUrl(objectsUrl('attendance-record')), {
						params: { sessionId: this.sessionId, _limit: 500 },
					})
				).data,
			)
		},

		/**
		 * Bring in self check-ins that arrived while the register is open,
		 * without touching rows the teacher already has a saved mark for.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
		 */
		async mergeCheckIns() {
			let records
			try {
				records = await this.loadRecords()
			} catch {
				return
			}
			const fresh = attendanceRows(
				this.rows.map((r) => r.learnerId),
				records,
			)
			this.rows = this.rows.map((row, index) =>
				!row.recordId && fresh[index].recordId ? fresh[index] : row,
			)
		},

		/**
		 * Resolve learner names from their profiles; the user id is the fallback.
		 *
		 * @param {string[]} learnerIds Nextcloud user ids.
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async loadNames(learnerIds) {
			if (learnerIds.length === 0) return
			try {
				const profiles = listRows(
					(
						await axios.get(generateUrl(objectsUrl('learner-profile')), {
							params: { _limit: 1000 },
						})
					).data,
				)
				const names = {}
				for (const p of profiles) {
					const name = [p.givenName, p.familyName]
						.filter(Boolean)
						.join(' ')
					if (p.ncUserId && name) names[p.ncUserId] = name
				}
				this.names = names
			} catch {
				this.names = {}
			}
		},

		/**
		 * @param {string} learnerId Nextcloud user id.
		 * @return {string} The display name.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		learnerName(learnerId) {
			return this.names[learnerId] || learnerId
		},

		/**
		 * @param {string} status AttendanceRecord.status value.
		 * @return {string} The translated label.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		statusLabel(status) {
			return (
				{
					present: this.t('learniq', 'Present'),
					late: this.t('learniq', 'Late'),
					'left-early': this.t('learniq', 'Left early'),
					'absent-excused': this.t('learniq', 'Absent, excused'),
					'absent-unexcused': this.t('learniq', 'Absent, not excused'),
				}[status] ?? status
			)
		},

		/**
		 * @param {string} status Status to give every learner.
		 * @return {void}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		markAll(status) {
			this.rows.forEach((row) => {
				row.status = status
			})
		},

		/**
		 * @param {string} value ISO date-time.
		 * @return {string} A local date and time.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		formatDate(value) {
			return value ? new Date(value).toLocaleString() : ''
		},

		/**
		 * Write one AttendanceRecord per learner.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async save() {
			this.saving = true
			this.saveError = ''
			this.saved = false
			const markedBy = getCurrentUser()?.uid ?? ''
			const markedAt = new Date().toISOString()
			const failed = []
			for (const row of registerRowsToSave(this.rows)) {
				const body = attendanceRecord(row, this.session, markedBy, markedAt)
				try {
					if (row.recordId) {
						await axios.put(
							generateUrl(
								objectsUrl('attendance-record', row.recordId),
							),
							body,
						)
					} else {
						const created = oneObject(
							(
								await axios.post(
									generateUrl(objectsUrl('attendance-record')),
									body,
								)
							).data,
						)
						row.recordId = created.id ?? created.uuid ?? ''
					}
					row.markedVia = 'teacher'
					row.savedStatus = row.status
					row.savedReason = row.reason ?? ''
				} catch {
					failed.push(this.learnerName(row.learnerId))
				}
			}
			this.saving = false
			if (failed.length > 0) {
				this.saveError = this.t('learniq', 'Not saved for: {names}', {
					names: failed.join(', '),
				})
			} else {
				this.saved = true
			}
		},
	},
}
</script>

<style scoped>
.attendance-register__self {
	margin-inline-start: var(--default-grid-baseline, 4px);
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 12px);
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 0.85em;
	font-weight: normal;
}

.attendance-register {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-inline-size: 60rem;
}

.attendance-register__session {
	color: var(--color-text-maxcontrast);
}

.attendance-register__bulk {
	margin-block: calc(var(--default-grid-baseline, 4px) * 2);
}

.attendance-register__table {
	inline-size: 100%;
	border-collapse: collapse;
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 4);
}

.attendance-register__table th,
.attendance-register__table td {
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	border-block-end: 1px solid var(--color-border);
	text-align: start;
}
</style>
