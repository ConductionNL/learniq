<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 StandbyPlanning (route /standby, timetabling-standby-slots).

 The standby hours of the week: one row per time window, one column per
 weekday, the teachers on standby in each cell. A coordinator adds a teacher
 to a cell or removes one. The substitution dialog lists these teachers first.

 @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
-->
<template>
	<div class="standby-planning">
		<header class="standby-planning__header">
			<div>
				<h2 class="standby-planning__title">
					{{ t('learniq', 'Standby hours') }}
				</h2>
				<p class="standby-planning__intro">
					{{
						t(
							'learniq',
							'Who is on standby to cover a colleague. The substitution dialog lists these teachers first.',
						)
					}}
				</p>
			</div>
			<NcButton variant="primary" @click="openDialog('monday', '', '')">
				{{ t('learniq', 'Add standby') }}
			</NcButton>
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcEmptyContent
			v-else-if="rows.length === 0"
			:name="t('learniq', 'No standby hours yet')"
			:description="t('learniq', 'Add a teacher to a weekday and a time.')" />
		<table v-else class="standby-planning__grid">
			<caption class="hidden-visually">
				{{
					t('learniq', 'Teachers on standby per weekday and time')
				}}
			</caption>
			<thead>
				<tr>
					<th scope="col">
						{{ t('learniq', 'Time') }}
					</th>
					<th v-for="day in weekdays" :key="day.value" scope="col">
						{{ day.label }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.startsAt + row.endsAt">
					<th scope="row">{{ row.startsAt }}–{{ row.endsAt }}</th>
					<td v-for="day in weekdays" :key="day.value">
						<ul class="standby-planning__cell">
							<li v-for="slot in row.cells[day.value]" :key="slot.id">
								{{ nameOf(slot.teacherId) }}
								<NcButton
									variant="tertiary"
									:aria-label="
										t('learniq', 'Remove {name} from standby', {
											name: nameOf(slot.teacherId),
										})
									"
									@click="remove(slot)">
									×
								</NcButton>
							</li>
						</ul>
						<NcButton
							variant="tertiary"
							:aria-label="
								t('learniq', 'Add standby on {day} {time}', {
									day: day.label,
									time: row.startsAt,
								})
							"
							@click="openDialog(day.value, row.startsAt, row.endsAt)">
							+
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<StandbySlotDialog
			v-if="dialog"
			:teacherOptions="teacherOptions"
			:initialWeekday="dialog.weekday"
			:initialStartsAt="dialog.startsAt"
			:initialEndsAt="dialog.endsAt"
			@close="dialog = null"
			@saved="load" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import StandbySlotDialog from '../dialogs/StandbySlotDialog.vue'
import { listRows, objectId, objectsUrl } from '../utils/customPages.js'
import { planningGrid, WEEKDAYS } from '../utils/standby.js'

export default {
	name: 'StandbyPlanning',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		StandbySlotDialog,
	},

	data() {
		return {
			loading: true,
			error: '',
			slots: [],
			staff: [],
			dialog: null,
		}
	},

	computed: {
		/**
		 * The planning grid.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
		 */
		rows() {
			return planningGrid(this.slots)
		},

		/**
		 * The weekday columns.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
		 */
		weekdays() {
			return WEEKDAYS.map((value, index) => ({
				value,
				label: new Date(2026, 0, 5 + index).toLocaleDateString(undefined, {
					weekday: 'long',
				}),
			}))
		},

		/**
		 * The teachers to choose from.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
		 */
		teacherOptions() {
			return this.staff
				.map((s) => ({ value: s.ncUserId, label: s.ncUserId }))
				.filter((o) => o.value)
		},
	},

	/**
	 * Load the slots and the staff.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * Load the slots and the staff.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [slots, staff] = await Promise.all([
					axios.get(generateUrl(objectsUrl('standby-slot')), {
						params: { _limit: 1000 },
					}),
					axios.get(generateUrl(objectsUrl('staff')), {
						params: { _limit: 1000 },
					}),
				])
				this.slots = listRows(slots.data).map((s) => ({
					...s,
					id: objectId(s),
				}))
				this.staff = listRows(staff.data)
			} catch {
				this.error = t('learniq', 'The standby hours could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * A teacher's name as shown in the grid.
		 *
		 * @param {string} uid The teacher.
		 * @return {string}
		 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
		 */
		nameOf(uid) {
			return uid
		},

		/**
		 * Open the add dialog for a cell.
		 *
		 * @param {string} weekday The weekday.
		 * @param {string} startsAt The window start.
		 * @param {string} endsAt The window end.
		 * @return {void}
		 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
		 */
		openDialog(weekday, startsAt, endsAt) {
			this.dialog = { weekday, startsAt, endsAt }
		},

		/**
		 * Remove a teacher from standby.
		 *
		 * @param {object} slot The slot.
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-standby-slots/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
		 */
		async remove(slot) {
			try {
				await axios.delete(generateUrl(objectsUrl('standby-slot', slot.id)))
				await this.load()
			} catch {
				this.error = t(
					'learniq',
					'The standby could not be removed. Only team leads and compliance officers can plan standby hours.',
				)
			}
		},
	},
}
</script>

<style scoped>
.standby-planning {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.standby-planning__header {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: 12px;
}

.standby-planning__title {
	margin: 0;
}

.standby-planning__intro {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.standby-planning__grid {
	border-collapse: collapse;
}

.standby-planning__grid th,
.standby-planning__grid td {
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	vertical-align: top;
	text-align: start;
}

.standby-planning__cell {
	list-style: none;
	margin: 0;
	padding: 0;
}
</style>
