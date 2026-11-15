<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 CohortCreateDialog: "Nieuwe groep" (learniq/LqNieuweGroep), the Groups
 list's create dialog. The kind of group is a choice of three cards (lesson
 group, care group, plus class); pupils can be added now or later on the
 group's page. The group starts planned and its lifecycle moves it on.
 @spec openspec/specs/school-structure/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'New group')"
		:eyebrow="t('learniq', 'Groups')"
		:subtitle="t('learniq', 'You can add pupils now or later.')"
		width="wizard"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="cohort-create-dialog"
		@closing="$emit('close')">
		<div class="cohort-create">
			<NcTextField
				v-model="form.name"
				:label="t('learniq', 'Name')"
				:helperText="t('learniq', 'As the school calls the group, for example 3HV-C.')"
				:disabled="busy"
				data-testid="cohort-create-name" />
			<div class="cohort-create__pair">
				<NcTextField
					v-model="form.academicYear"
					:label="t('learniq', 'School year')"
					:disabled="busy" />
				<NcSelect
					v-model="form.period"
					:inputLabel="t('learniq', 'Period')"
					:options="periodOptions"
					:clearable="false"
					:disabled="busy" />
			</div>
			<CnChoiceCards
				v-model="form.kind"
				:label="t('learniq', 'Kind of group')"
				:options="kindOptions"
				:columns="3"
				:disabled="busy"
				data-testid="cohort-create-kind" />
			<div class="cohort-create__pair">
				<CnResourceSelect
					v-model="form.programmeId"
					register="learniq"
					schema="programme"
					:inputLabel="t('learniq', 'Programme')"
					:allowCreate="false"
					:preload="true"
					:disabled="busy" />
				<NcTextField
					v-model="form.programmeYear"
					type="number"
					:label="t('learniq', 'Year in the programme (optional)')"
					:disabled="busy" />
			</div>
			<DialogUserSelect
				v-model="form.teacherIds"
				:multiple="true"
				:inputLabel="t('learniq', 'Teachers (optional)')"
				:helperText="t('learniq', 'Type a name to add a teacher.')"
				:disabled="busy" />
			<div class="cohort-create__pair">
				<CnResourceSelect
					v-model="form.locationId"
					register="learniq"
					schema="vestiging"
					:inputLabel="t('learniq', 'Location')"
					:allowCreate="false"
					:preload="true"
					:disabled="busy" />
				<CnResourceSelect
					v-model="form.courseId"
					register="learniq"
					schema="course"
					:inputLabel="t('learniq', 'Course')"
					:allowCreate="false"
					:preload="true"
					:disabled="busy" />
			</div>
			<DialogUserSelect
				v-model="form.learnerIds"
				:multiple="true"
				:inputLabel="t('learniq', 'Pupils (optional)')"
				:helperText="t('learniq', 'Search by name, or add them later on the group\'s page')"
				:disabled="busy" />
			<div class="cohort-create__pair">
				<NcTextField
					v-model="form.ncGroupId"
					:label="t('learniq', 'Nextcloud group (optional)')"
					:helperText="t('learniq', 'Is created when the group becomes active')"
					:disabled="busy" />
				<CnResourceSelect
					v-model="form.reportCardTemplateId"
					register="learniq"
					schema="report-card-template"
					:inputLabel="t('learniq', 'Report card template')"
					:allowCreate="false"
					:preload="true"
					:disabled="busy" />
			</div>
			<NcTextArea
				v-model="form.notes"
				:label="t('learniq', 'Notes (optional)')"
				:disabled="busy"
				rows="3" />
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<CnDialogActions
				:saving="busy"
				:canSubmit="ready"
				:confirmLabel="t('learniq', 'Create group')"
				:cancelLabel="t('learniq', 'Cancel')"
				@cancel="$emit('close')"
				@submit="create" />
		</template>
	</CnDialog>
</template>

<script>
import { CnChoiceCards, CnDialog, CnDialogActions, CnResourceSelect } from '@conduction/nextcloud-vue'
import { NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import DialogUserSelect from './DialogUserSelect.vue'
import { canCreateCohort, COHORT_KINDS, cohortBody } from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, callerTenant, createObject } from './dialogRequests.js'

/**
 * The school year a group made today belongs to: from August on, the year
 * that starts now.
 *
 * @param {Date} now The current moment.
 * @return {string} The school year, as 2026-2027.
 */
function schoolYear(now) {
	const start = now.getMonth() >= 7 ? now.getFullYear() : now.getFullYear() - 1
	return `${start}-${start + 1}`
}

export default {
	name: 'CohortCreateDialog',

	components: { CnChoiceCards, CnDialog, CnDialogActions, CnResourceSelect, DialogNoticeFallback, DialogUserSelect, NcSelect, NcTextArea, NcTextField },

	emits: ['close'],

	data() {
		return {
			form: {
				name: '',
				academicYear: schoolYear(new Date()),
				period: this.t('learniq', 'Whole school year'),
				kind: 'teaching',
				programmeId: '',
				programmeYear: '',
				teacherIds: [],
				locationId: '',
				courseId: '',
				learnerIds: [],
				ncGroupId: '',
				reportCardTemplateId: '',
				notes: '',
			},

			busy: false,
			notice: null,
		}
	},

	computed: {
		/**
		 * @return {string[]} The periods a group can run for.
		 * @spec openspec/specs/school-structure/spec.md
		 */
		periodOptions() {
			return [
				this.t('learniq', 'Whole school year'),
				this.t('learniq', 'Period 1'),
				this.t('learniq', 'Period 2'),
				this.t('learniq', 'Period 3'),
				this.t('learniq', 'Period 4'),
			]
		},

		/**
		 * @return {object[]} The kinds of group as choice cards.
		 * @spec openspec/specs/school-structure/spec.md
		 */
		kindOptions() {
			const cards = {
				teaching: [this.t('learniq', 'Lesson group'), this.t('learniq', 'An ordinary class or lesson group')],
				care: [this.t('learniq', 'Care group'), this.t('learniq', 'A fixed subgroup for extra care')],
				plusklas: [this.t('learniq', 'Plus class'), this.t('learniq', 'A fixed group for enrichment')],
			}
			return COHORT_KINDS.map((value) => ({ value, label: cards[value][0], description: cards[value][1] }))
		},

		/**
		 * @return {boolean} Whether the group can be made.
		 * @spec openspec/specs/school-structure/spec.md
		 */
		ready() {
			return canCreateCohort(this.form)
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/school-structure/spec.md
		 */
		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	methods: {
		/**
		 * Create the group and close.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-structure/spec.md
		 */
		async create() {
			this.busy = true
			this.notice = null
			try {
				const cohort = await createObject('cohort', cohortBody(this.form, callerTenant()))
				announceSaved('learniq:cohort:created', { cohortId: cohort?.id ?? '' })
				this.$emit('close')
			} catch (error) {
				this.notice = requestRefusal(error, this.t('learniq', 'Not created.'), this.t('learniq', 'The group could not be created.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.cohort-create {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.cohort-create__pair {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}
</style>
