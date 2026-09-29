<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RoomUtilisationReport (route /reports/room-use, timetabling-room-utilisation).

 How well rooms are used over a period: per room the hours in use against the
 hours the building is open, the occupancy and the average fill, a weekday by
 hour grid of the share of rooms in use, and the lessons that have no room.
 Filters on room kind and building; CSV export of the room table. Team leads
 and compliance officers change the opening hours the report counts from.
 Everything is computed by GET /api/reports/room-use; nothing is stored.

 @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 @spec openspec/specs/school-structure/spec.md#requirement-lessons-without-a-room-are-counted-not-hidden
-->
<template>
	<div class="room-use">
		<header>
			<h2 class="room-use__title">
				{{ t('learniq', 'Room use') }}
			</h2>
			<p class="room-use__intro">
				{{
					t(
						'learniq',
						'How many of the open hours each room is used, and how full it is. Cancelled lessons do not count.',
					)
				}}
			</p>
		</header>

		<div class="room-use__filters">
			<NcDateTimePickerNative
				id="room-use-from"
				v-model="fromDate"
				type="date"
				:label="t('learniq', 'First day')" />
			<NcDateTimePickerNative
				id="room-use-to"
				v-model="toDate"
				type="date"
				:label="t('learniq', 'Last day')" />
			<NcSelect
				v-model="kind"
				:options="kindOptions"
				:reduce="(o) => o.value"
				:inputLabel="t('learniq', 'Room kind')" />
			<NcSelect
				v-model="building"
				:options="buildingOptions"
				:inputLabel="t('learniq', 'Building')" />
			<NcButton :disabled="loading" @click="load">
				{{ t('learniq', 'Show') }}
			</NcButton>
			<NcButton
				:disabled="loading || !report || report.rooms.length === 0"
				@click="exportCsv">
				{{ t('learniq', 'Export as CSV') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template v-else-if="report">
			<p class="room-use__summary">
				{{
					t(
						'learniq',
						'{days} teaching days, {hours} open hours per room.',
						{
							days: report.teachingDays,
							hours: report.openHoursPerRoom,
						},
					)
				}}
			</p>

			<NcNoteCard v-if="report.unassigned.count > 0" type="warning">
				<p>
					{{
						t(
							'learniq',
							'{count} lessons in this period have no room, so they are not counted.',
							{ count: report.unassigned.count },
						)
					}}
				</p>
				<details>
					<summary>{{ t('learniq', 'Show these lessons') }}</summary>
					<ul class="room-use__unassigned">
						<li
							v-for="session in report.unassigned.sessions"
							:key="session.id + session.startsAt">
							<router-link
								v-if="session.source === 'learniq' && session.id"
								:to="{
									name: 'SessionDetail',
									params: { id: session.id },
								}">
								{{ lessonLabel(session) }}
							</router-link>
							<span v-else>{{ lessonLabel(session) }}</span>
						</li>
					</ul>
				</details>
			</NcNoteCard>

			<NcEmptyContent
				v-if="report.rooms.length === 0"
				:name="t('learniq', 'No rooms match these filters')" />
			<table v-else class="room-use__table">
				<caption class="hidden-visually">
					{{
						t('learniq', 'Room use per room')
					}}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('learniq', 'Room') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Room kind') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Building') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Capacity') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Hours in use') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Hours open') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Occupancy') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Average fill') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in report.rooms" :key="row.roomId">
						<th scope="row">
							{{ row.name }}
						</th>
						<td>{{ kindLabel(row.kind) }}</td>
						<td>{{ row.buildingCode || '' }}</td>
						<td>{{ row.capacity ?? '' }}</td>
						<td>{{ row.hoursInUse }}</td>
						<td>{{ row.hoursOpen }}</td>
						<td>{{ percent(row.occupancy) }}</td>
						<td>{{ percent(row.fill) }}</td>
					</tr>
				</tbody>
			</table>

			<section v-if="report.grid.length > 0" class="room-use__grid">
				<h3>{{ t('learniq', 'Share of rooms in use per hour') }}</h3>
				<table>
					<thead>
						<tr>
							<th scope="col">
								{{ t('learniq', 'Hour') }}
							</th>
							<th
								v-for="day in report.grid"
								:key="day.weekday"
								scope="col">
								{{ weekdayLabel(day.weekday) }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="hour in gridHours" :key="hour">
							<th scope="row">
								{{ String(hour).padStart(2, '0') }}:00
							</th>
							<td
								v-for="day in report.grid"
								:key="day.weekday"
								:class="
									'room-use__heat room-use__heat--'
									+ heatOf(day, hour)
								">
								{{ percent(shareOf(day, hour)) }}
							</td>
						</tr>
					</tbody>
				</table>
			</section>
		</template>

		<details v-if="openingHours" class="room-use__hours">
			<summary>{{ t('learniq', 'Opening hours') }}</summary>
			<p v-if="!canEdit" class="room-use__intro">
				{{
					t(
						'learniq',
						'Team leads and compliance officers can change the opening hours.',
					)
				}}
			</p>
			<table>
				<tbody>
					<tr v-for="day in weekdays" :key="day.key">
						<th scope="row">
							{{ day.label }}
						</th>
						<td>
							<NcCheckboxRadioSwitch
								:modelValue="openingHours.weekdays[day.key] !== null"
								:disabled="!canEdit"
								@update:modelValue="toggleDay(day.key, $event)">
								{{ t('learniq', 'Open') }}
							</NcCheckboxRadioSwitch>
						</td>
						<td>
							<input
								v-if="openingHours.weekdays[day.key]"
								v-model="openingHours.weekdays[day.key].opens"
								type="time"
								:disabled="!canEdit"
								:aria-label="
									t('learniq', 'Opens on {day}', {
										day: day.label,
									})
								" />
						</td>
						<td>
							<input
								v-if="openingHours.weekdays[day.key]"
								v-model="openingHours.weekdays[day.key].closes"
								type="time"
								:disabled="!canEdit"
								:aria-label="
									t('learniq', 'Closes on {day}', {
										day: day.label,
									})
								" />
						</td>
					</tr>
				</tbody>
			</table>
			<NcCheckboxRadioSwitch
				v-model="openingHours.closedOnStudyDays"
				:disabled="!canEdit">
				{{ t('learniq', 'Rooms are closed on study days') }}
			</NcCheckboxRadioSwitch>
			<NcButton v-if="canEdit" variant="primary" @click="saveOpeningHours">
				{{ t('learniq', 'Save opening hours') }}
			</NcButton>
			<NcNoteCard v-if="hoursMessage" :type="hoursMessageType">
				{{ hoursMessage }}
			</NcNoteCard>
		</details>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDateTimePickerNative,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { listRows, objectsUrl } from '../utils/customPages.js'
import { heatLevel, percent, roomsCsv, workWeekOf } from '../utils/roomUse.js'

const BASE = '/apps/learniq/api/reports/room-use'

export default {
	name: 'RoomUtilisationReport',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDateTimePickerNative,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		const week = workWeekOf(new Date())
		return {
			fromDate: new Date(week.from + 'T00:00:00'),
			toDate: new Date(week.to + 'T00:00:00'),
			kind: null,
			building: null,
			buildingOptions: [],
			loading: false,
			error: '',
			report: null,
			openingHours: null,
			canEdit: false,
			hoursMessage: '',
			hoursMessageType: 'success',
		}
	},

	computed: {
		/**
		 * The room kind filter options.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		kindOptions() {
			return ['classroom', 'lab', 'gym', 'auditorium', 'online', 'other'].map(
				(value) => ({ value, label: this.kindLabel(value) }),
			)
		},

		/**
		 * The weekdays of the opening hours form.
		 *
		 * @return {Array<{key: string, label: string}>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		weekdays() {
			return [
				'monday',
				'tuesday',
				'wednesday',
				'thursday',
				'friday',
				'saturday',
				'sunday',
			].map((key, index) => ({ key, label: this.weekdayLabel(index) }))
		},

		/**
		 * Every clock hour that appears in the grid.
		 *
		 * @return {number[]}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		gridHours() {
			const hours = new Set()
			for (const day of this.report?.grid || []) {
				for (const cell of day.hours) {
					hours.add(cell.hour)
				}
			}
			return [...hours].sort((a, b) => a - b)
		},
	},

	/**
	 * Load the buildings, the opening hours and this week's report.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
	 */
	async mounted() {
		await Promise.all([this.loadBuildings(), this.loadOpeningHours()])
		await this.load()
	},

	methods: {
		t,
		percent,

		/**
		 * A date as `Y-m-d` in local time.
		 *
		 * @param {Date} date The date.
		 * @return {string}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		isoDay(date) {
			const d = date instanceof Date ? date : new Date(date)
			return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
		},

		/**
		 * Load the report for the chosen window and filters.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const res = await axios.get(generateUrl(BASE), {
					params: {
						from: this.isoDay(this.fromDate),
						to: this.isoDay(this.toDate),
						kind: this.kind || undefined,
						building: this.building || undefined,
					},
				})
				this.report = res.data
			} catch (e) {
				this.report = null
				this.error =
					e?.response?.data?.error
					|| t('learniq', 'The room use report could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The building codes of all rooms, for the filter.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		async loadBuildings() {
			try {
				const res = await axios.get(generateUrl(objectsUrl('room')), {
					params: { _limit: 1000 },
				})
				this.buildingOptions = [
					...new Set(
						listRows(res.data)
							.map((r) => r.buildingCode)
							.filter(Boolean),
					),
				].sort()
			} catch {
				this.buildingOptions = []
			}
		},

		/**
		 * Load the opening hours and whether the caller may change them.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		async loadOpeningHours() {
			try {
				const res = await axios.get(generateUrl(BASE + '/opening-hours'))
				this.openingHours = res.data.openingHours
				this.canEdit = res.data.canEdit === true
			} catch {
				this.openingHours = null
			}
		},

		/**
		 * Open or close a weekday.
		 *
		 * @param {string} key The weekday.
		 * @param {boolean} open Whether it is open.
		 * @return {void}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		toggleDay(key, open) {
			this.openingHours.weekdays[key] = open
				? { opens: '08:00', closes: '17:00' }
				: null
		},

		/**
		 * Save the opening hours and reload the report.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		async saveOpeningHours() {
			this.hoursMessage = ''
			try {
				const res = await axios.put(generateUrl(BASE + '/opening-hours'), {
					openingHours: this.openingHours,
				})
				this.openingHours = res.data.openingHours
				this.hoursMessage = t('learniq', 'The opening hours are saved.')
				this.hoursMessageType = 'success'
				await this.load()
			} catch (e) {
				this.hoursMessage =
					e?.response?.data?.error
					|| t('learniq', 'The opening hours could not be saved.')
				this.hoursMessageType = 'error'
			}
		},

		/**
		 * The share of rooms in use in one grid cell.
		 *
		 * @param {object} day A grid column.
		 * @param {number} hour The clock hour.
		 * @return {number|null}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		shareOf(day, hour) {
			const cell = day.hours.find((h) => h.hour === hour)
			return cell ? cell.share : null
		},

		/**
		 * The heat level of one grid cell.
		 *
		 * @param {object} day A grid column.
		 * @param {number} hour The clock hour.
		 * @return {number}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		heatOf(day, hour) {
			return heatLevel(this.shareOf(day, hour))
		},

		/**
		 * A weekday name, Monday first.
		 *
		 * @param {number} index 0 for Monday.
		 * @return {string}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		weekdayLabel(index) {
			// 5 January 2026 is a Monday.
			return new Date(2026, 0, 5 + index).toLocaleDateString(undefined, {
				weekday: 'long',
			})
		},

		/**
		 * The label of a room kind.
		 *
		 * @param {string} kind The kind.
		 * @return {string}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		kindLabel(kind) {
			return (
				{
					classroom: t('learniq', 'Classroom'),
					lab: t('learniq', 'Lab'),
					gym: t('learniq', 'Gym'),
					auditorium: t('learniq', 'Auditorium'),
					online: t('learniq', 'Online'),
					other: t('learniq', 'Other'),
				}[kind]
				|| kind
				|| ''
			)
		},

		/**
		 * One line for a lesson without a room.
		 *
		 * @param {object} session The lesson.
		 * @return {string}
		 * @spec openspec/specs/school-structure/spec.md#requirement-lessons-without-a-room-are-counted-not-hidden
		 */
		lessonLabel(session) {
			const ts = Date.parse(session.startsAt || '')
			const when = Number.isNaN(ts)
				? ''
				: new Date(ts).toLocaleString(undefined, {
						weekday: 'short',
						day: 'numeric',
						month: 'short',
						hour: '2-digit',
						minute: '2-digit',
					})
			return [session.title || t('learniq', 'Untitled session'), when]
				.filter(Boolean)
				.join(', ')
		},

		/**
		 * Download the room table as CSV.
		 *
		 * @return {void}
		 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
		 */
		exportCsv() {
			const csv = roomsCsv(this.report.rooms, {
				name: t('learniq', 'Room'),
				kind: t('learniq', 'Room kind'),
				buildingCode: t('learniq', 'Building'),
				capacity: t('learniq', 'Capacity'),
				hoursInUse: t('learniq', 'Hours in use'),
				hoursOpen: t('learniq', 'Hours open'),
				occupancy: t('learniq', 'Occupancy'),
				fill: t('learniq', 'Average fill'),
			})
			const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
			const link = document.createElement('a')
			link.href = URL.createObjectURL(blob)
			link.download = `room-use-${this.isoDay(this.fromDate)}-${this.isoDay(this.toDate)}.csv`
			link.click()
			URL.revokeObjectURL(link.href)
		},
	},
}
</script>

<style scoped>
.room-use {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.room-use__title {
	margin: 0;
}

.room-use__intro,
.room-use__summary {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.room-use__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
}

.room-use__table,
.room-use__grid table,
.room-use__hours table {
	border-collapse: collapse;
}

.room-use__table th,
.room-use__table td,
.room-use__grid th,
.room-use__grid td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.room-use__heat--0 {
	background: var(--color-main-background);
}

.room-use__heat--1 {
	background: var(--color-primary-element-light);
}

.room-use__heat--2 {
	background: var(--color-primary-element-light-hover);
}

.room-use__heat--3 {
	background: var(--color-primary-element-hover);
	color: var(--color-primary-element-text);
}

.room-use__heat--4 {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.room-use__unassigned {
	margin: 8px 0 0;
	padding-inline-start: 20px;
}
</style>
