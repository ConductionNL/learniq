<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 StandbySlotDialog (timetabling-standby-slots).

 A coordinator puts a teacher on standby: a teacher, a weekday or one date, a
 time window and the school year it applies to. The register lets only team
 leads and compliance officers write a StandbySlot.

 @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
-->
<template>
	<NcDialog
		:open="true"
		:name="t('learniq', 'Add standby')"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<div class="standby-slot-dialog">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcSelect
				v-model="teacherId"
				:options="teacherOptions"
				:reduce="(o) => o.value"
				:inputLabel="t('learniq', 'Teacher')" />
			<NcSelect
				v-model="weekday"
				:options="weekdayOptions"
				:reduce="(o) => o.value"
				:clearable="false"
				:inputLabel="t('learniq', 'Weekday')" />
			<div class="standby-slot-dialog__times">
				<NcTextField
					v-model="startsAt"
					type="time"
					:label="t('learniq', 'Starts at')" />
				<NcTextField
					v-model="endsAt"
					type="time"
					:label="t('learniq', 'Ends at')" />
			</div>
			<div class="standby-slot-dialog__times">
				<NcTextField
					v-model="validFrom"
					type="date"
					:label="t('learniq', 'Valid from')" />
				<NcTextField
					v-model="validUntil"
					type="date"
					:label="t('learniq', 'Valid until')" />
			</div>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('learniq', 'Close') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSave || saving" @click="save">
				{{ t('learniq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { objectsUrl } from '../utils/customPages.js'
import { schoolYearBounds, WEEKDAYS } from '../utils/standby.js'

export default {
	name: 'StandbySlotDialog',

	components: { NcButton, NcDialog, NcNoteCard, NcSelect, NcTextField },

	props: {
		/** Teacher options, `{value, label}`. */
		teacherOptions: { type: Array, required: true },
		/** Weekday to start with. */
		initialWeekday: { type: String, default: 'monday' },
		/** Time window to start with. */
		initialStartsAt: { type: String, default: '' },
		initialEndsAt: { type: String, default: '' },
	},

	emits: ['close', 'saved'],

	data() {
		const bounds = schoolYearBounds(new Date())
		return {
			teacherId: null,
			weekday: this.initialWeekday,
			startsAt: this.initialStartsAt,
			endsAt: this.initialEndsAt,
			validFrom: bounds.validFrom,
			validUntil: bounds.validUntil,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The weekday options.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
		 */
		weekdayOptions() {
			return WEEKDAYS.map((value, index) => ({
				value,
				label: new Date(2026, 0, 5 + index).toLocaleDateString(undefined, {
					weekday: 'long',
				}),
			}))
		},

		/**
		 * Whether the slot is complete.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
		 */
		canSave() {
			return Boolean(
				this.teacherId
				&& this.weekday
				&& this.startsAt
				&& this.endsAt
				&& this.startsAt < this.endsAt
				&& this.validFrom
				&& this.validUntil,
			)
		},
	},

	methods: {
		t,

		/**
		 * Save the slot.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				await axios.post(generateUrl(objectsUrl('standby-slot')), {
					teacherId: this.teacherId,
					weekday: this.weekday,
					date: null,
					startsAt: this.startsAt,
					endsAt: this.endsAt,
					validFrom: this.validFrom,
					validUntil: this.validUntil,
				})
				this.$emit('saved')
				this.$emit('close')
			} catch {
				this.error = t(
					'learniq',
					'The standby could not be saved. Only team leads and compliance officers can plan standby hours.',
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.standby-slot-dialog {
	min-width: 360px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
}

.standby-slot-dialog__times {
	display: flex;
	gap: 8px;
}
</style>
