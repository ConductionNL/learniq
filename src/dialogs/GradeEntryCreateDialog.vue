<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 GradeEntryCreateDialog: "Cijfer invoeren" (learniq/LqCijferInvoeren), the
 Grades list's create dialog. A teacher records one mark for one pupil on one
 plan component. It is saved as a concept: pupil and parents see it only once
 it is published, and after that it changes only through a correction.
 @spec openspec/specs/grading/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'Enter a grade')"
		:eyebrow="t('learniq', 'Grades')"
		width="wizard"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="grade-entry-create-dialog"
		@closing="$emit('close')">
		<div class="grade-entry">
			<div class="grade-entry__grid">
				<DialogUserSelect
					v-model="form.learnerId"
					:inputLabel="t('learniq', 'Pupil')"
					:disabled="busy || Boolean(learnerId)" />
				<CnResourceSelect
					v-model="form.courseId"
					register="learniq"
					schema="course"
					:inputLabel="t('learniq', 'Subject')"
					:allowCreate="false"
					:preload="true"
					:disabled="busy"
					@update:modelValue="onCourse" />
				<CnResourceSelect
					v-model="form.curriculumPlanId"
					register="learniq"
					schema="curriculum-plan"
					:inputLabel="t('learniq', 'Curriculum plan')"
					:allowCreate="false"
					:preload="true"
					:disabled="busy"
					@update:modelValue="onPlan" />
				<NcSelect
					v-model="form.componentId"
					:inputLabel="t('learniq', 'Part')"
					:options="componentOptions"
					:reduce="(option) => option.value"
					:disabled="busy || componentOptions.length === 0"
					label="label" />
				<NcTextField
					v-model="form.value"
					:label="t('learniq', 'Grade')"
					:helperText="t('learniq', 'On the scale of the part: 1 to 10, one decimal.')"
					:disabled="busy"
					data-testid="grade-entry-value" />
				<NcTextField
					v-model="form.weight"
					:label="t('learniq', 'Weight (optional)')"
					:helperText="weightHelp"
					:disabled="busy" />
				<CnResourceSelect
					v-model="form.gradeScaleId"
					register="learniq"
					schema="grade-scale"
					:inputLabel="t('learniq', 'Grade scale')"
					:allowCreate="false"
					:preload="true"
					:disabled="busy" />
				<NcSelect
					v-model="form.period"
					:inputLabel="t('learniq', 'Period')"
					:options="periodOptions"
					:disabled="busy" />
				<NcTextField
					v-model="form.courseName"
					:label="t('learniq', 'Course name (optional)')"
					:helperText="t('learniq', 'As parents and the pupil read it.')"
					:disabled="busy" />
				<NcTextField
					v-model="form.grader"
					:label="t('learniq', 'Assessor (optional)')"
					:helperText="t('learniq', 'Who gave the grade. Empty: you.')"
					:disabled="busy" />
				<NcSelect
					v-model="form.sourceKind"
					:inputLabel="t('learniq', 'Origin')"
					:options="sourceOptions"
					:reduce="(option) => option.value"
					:clearable="false"
					:disabled="busy"
					label="label" />
				<CnResourceSelect
					v-model="form.cohortId"
					register="learniq"
					schema="cohort"
					:inputLabel="t('learniq', 'Group')"
					:allowCreate="false"
					:preload="true"
					:disabled="busy" />
				<NcTextField
					v-model="form.gradedAt"
					type="date"
					:label="t('learniq', 'Assessed on (optional)')"
					:disabled="busy" />
				<NcTextField
					v-model="form.visibleFrom"
					type="date"
					:label="t('learniq', 'Visible from (optional)')"
					:helperText="t('learniq', 'Empty: visible as soon as you publish')"
					:disabled="busy" />
				<NcTextField
					v-model="form.methodName"
					:label="t('learniq', 'Method (optional)')"
					:disabled="busy" />
				<NcTextField
					v-model="form.methodBlock"
					:label="t('learniq', 'Method block (optional)')"
					:disabled="busy" />
			</div>
			<NcTextArea
				v-model="form.comment"
				:label="t('learniq', 'Comment for the pupil (optional)')"
				:disabled="busy"
				rows="3" />
			<p class="grade-entry__muted">
				{{ t('learniq', 'The grade is a concept first. The pupil and parents only see it once you publish it. After that it only changes through a correction.') }}
			</p>
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<CnDialogActions
				:saving="busy"
				:canSubmit="ready"
				:confirmLabel="t('learniq', 'Save as concept')"
				:cancelLabel="t('learniq', 'Cancel')"
				@cancel="$emit('close')"
				@submit="save" />
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnDialogActions, CnResourceSelect } from '@conduction/nextcloud-vue'
import { NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import DialogUserSelect from './DialogUserSelect.vue'
import { canSaveGrade, GRADE_SOURCES, gradeEntryCreateBody, planComponent } from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, callerTenant, createObject, currentUserId, readObject } from './dialogRequests.js'

export default {
	name: 'GradeEntryCreateDialog',

	components: { CnDialog, CnDialogActions, CnResourceSelect, DialogNoticeFallback, DialogUserSelect, NcSelect, NcTextArea, NcTextField },

	props: {
		/** The pupil's Nextcloud user id, when the grade is entered from the pupil's page. */
		learnerId: { type: String, default: '' },
	},

	emits: ['close'],

	data() {
		return {
			plan: {},
			form: {
				learnerId: this.learnerId,
				courseId: '',
				courseName: '',
				curriculumPlanId: '',
				componentId: '',
				value: '',
				weight: '',
				gradeScaleId: '',
				period: '',
				grader: '',
				sourceKind: 'manual',
				cohortId: '',
				gradedAt: '',
				visibleFrom: '',
				methodName: '',
				methodBlock: '',
				comment: '',
			},

			busy: false,
			notice: null,
		}
	},

	computed: {
		/**
		 * @return {object[]} The chosen plan's components.
		 * @spec openspec/specs/grading/spec.md
		 */
		componentOptions() {
			return (Array.isArray(this.plan.components) ? this.plan.components : [])
				.map((component) => ({ value: component.componentId, label: component.label || component.componentId }))
		},

		/**
		 * @return {string} What an empty weight means for the chosen part.
		 * @spec openspec/specs/grading/spec.md
		 */
		weightHelp() {
			const weight = planComponent(this.plan, this.form.componentId).weight
			return weight === undefined
				? this.t('learniq', 'Leave empty: the weight from the curriculum plan.')
				: this.t('learniq', 'Leave empty: the weight from the curriculum plan, here {weight}.', { weight })
		},

		/**
		 * @return {string[]} The periods a grade can belong to.
		 * @spec openspec/specs/grading/spec.md
		 */
		periodOptions() {
			return [this.t('learniq', 'Period 1'), this.t('learniq', 'Period 2'), this.t('learniq', 'Period 3'), this.t('learniq', 'Period 4')]
		},

		/**
		 * @return {object[]} Where a grade comes from.
		 * @spec openspec/specs/grading/spec.md
		 */
		sourceOptions() {
			const labels = {
				manual: this.t('learniq', 'Manual'),
				'assessment-result': this.t('learniq', 'Test result'),
				'assignment-submission': this.t('learniq', 'Handed-in assignment'),
				'method-test': this.t('learniq', 'Method test'),
				participation: this.t('learniq', 'Participation'),
				exemption: this.t('learniq', 'Exemption'),
				portfolio: this.t('learniq', 'Portfolio'),
			}
			return GRADE_SOURCES.map((value) => ({ value, label: labels[value] }))
		},

		/**
		 * @return {boolean} Whether the grade can be saved.
		 * @spec openspec/specs/grading/spec.md
		 */
		ready() {
			return canSaveGrade(this.form, this.plan)
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/grading/spec.md
		 */
		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	methods: {
		/**
		 * Fill the course name from the chosen subject, unless one was typed.
		 *
		 * @param {string} courseId The chosen course.
		 * @return {Promise<void>}
		 * @spec openspec/specs/grading/spec.md
		 */
		async onCourse(courseId) {
			if (!courseId || this.form.courseName) {
				return
			}
			try {
				this.form.courseName = (await readObject('course', courseId)).name ?? ''
			} catch {
				// The course name stays empty; it is optional.
			}
		},

		/**
		 * Read the chosen plan for its parts, and preset the scale.
		 *
		 * @param {string} planId The chosen plan.
		 * @return {Promise<void>}
		 * @spec openspec/specs/grading/spec.md
		 */
		async onPlan(planId) {
			this.form.componentId = ''
			this.plan = {}
			if (!planId) {
				return
			}
			try {
				this.plan = await readObject('curriculum-plan', planId)
				if (!this.form.gradeScaleId && this.plan.gradeScaleId) {
					this.form.gradeScaleId = this.plan.gradeScaleId
				}
			} catch (error) {
				this.notice = requestRefusal(error, this.t('learniq', 'Not found.'), this.t('learniq', 'The curriculum plan could not be read.'))
			}
		},

		/**
		 * Save the grade as a concept and close.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/grading/spec.md
		 */
		async save() {
			this.busy = true
			this.notice = null
			try {
				const entry = await createObject('grade-entry', gradeEntryCreateBody(this.form, this.plan, currentUserId(), callerTenant()))
				announceSaved('learniq:grade-entry:created', { entryId: entry?.id ?? '', learnerId: this.form.learnerId })
				this.$emit('close')
			} catch (error) {
				this.notice = requestRefusal(error, this.t('learniq', 'Not saved.'), this.t('learniq', 'The grade could not be saved.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.grade-entry {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.grade-entry__grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.grade-entry__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
