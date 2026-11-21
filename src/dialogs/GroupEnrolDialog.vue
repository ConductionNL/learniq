<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 GroupEnrolDialog: "Groep inschrijven in de verplichte cursus"
 (learniq/LqCfGroepInschrijven). A compliance officer enrols a section, a
 team or everyone with one HR function in a regulation's mandatory course.
 Before sending, the dialog shows who is enrolled and who already is, from
 the server's preview of exactly that audience; without a preview nothing
 can be sent, so a wrong audience is never enrolled.
 @spec openspec/specs/compliance-audit/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'Enrol a group in the mandatory course')"
		:eyebrow="regulation.name || ''"
		width="wizard"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="group-enrol-dialog"
		@closing="$emit('close')">
		<div class="group-enrol">
			<CnChoiceCards
				v-model="audienceKind"
				:label="t('learniq', 'Who do you enrol?')"
				:options="kindOptions"
				:columns="3"
				:disabled="busy"
				data-testid="group-enrol-kind" />
			<div class="group-enrol__pair">
				<NcSelect
					v-model="audience"
					:inputLabel="audienceLabel"
					:options="audienceOptions"
					:reduce="(option) => option.value"
					:loading="loading"
					:disabled="busy"
					label="label"
					data-testid="group-enrol-audience" />
				<NcTextField
					:modelValue="preview.courseName || ''"
					:label="t('learniq', 'Course')"
					:helperText="t('learniq', 'The mandatory course of this rule.')"
					readonly />
				<NcTextField
					v-model="dueDate"
					type="date"
					:label="t('learniq', 'Complete before (optional)')"
					:disabled="busy" />
				<NcTextField
					v-model="message"
					:label="t('learniq', 'Message with the invitation (optional)')"
					:disabled="busy" />
			</div>
			<div v-if="previewReady" class="group-enrol__counts">
				<div class="group-enrol__count">
					<span>{{ t('learniq', 'In the audience') }}</span>
					<strong>{{ preview.total }}</strong>
					<span>{{ t('learniq', 'employees') }}</span>
				</div>
				<div class="group-enrol__count">
					<span>{{ t('learniq', 'Already enrolled') }}</span>
					<strong>{{ preview.alreadyEnrolled }}</strong>
					<span>{{ t('learniq', 'are skipped') }}</span>
				</div>
				<div class="group-enrol__count">
					<span>{{ t('learniq', 'Newly enrolled') }}</span>
					<strong>{{ preview.toEnrol }}</strong>
					<span>{{ t('learniq', 'get an invitation today') }}</span>
				</div>
			</div>
			<table v-if="previewReady && rows.length" class="group-enrol__table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('learniq', 'Employee') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'What happens') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.id || row.name">
						<td>
							<strong>{{ row.name }}</strong>
							<span v-if="row.role" class="group-enrol__muted">{{ row.role }}</span>
						</td>
						<td>{{ row.outcome === 'enrolled' ? t('learniq', 'Already enrolled') : t('learniq', 'Is enrolled') }}</td>
					</tr>
				</tbody>
			</table>
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<CnDialogActions
				:saving="busy"
				:canSubmit="previewReady && preview.toEnrol > 0"
				:confirmLabel="n('learniq', 'Enrol %n employee', 'Enrol %n employees', preview.toEnrol || 0)"
				:cancelLabel="t('learniq', 'Cancel')"
				@cancel="$emit('close')"
				@submit="enrol" />
		</template>
	</CnDialog>
</template>

<script>
import { CnChoiceCards, CnDialog, CnDialogActions } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcSelect, NcTextField } from '@nextcloud/vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import { ENROL_AUDIENCES, groupEnrolBody } from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, readObject } from './dialogRequests.js'

/** The regulation's assignment endpoint; `/preview` answers who it would reach. */
const ASSIGN_URL = '/apps/learniq/api/compliance/regulations/{id}/assign'

export default {
	name: 'GroupEnrolDialog',

	components: { CnChoiceCards, CnDialog, CnDialogActions, DialogNoticeFallback, NcSelect, NcTextField },

	props: {
		/** The Regulation's uuid. */
		objectId: { type: String, required: true },
	},

	emits: ['close'],

	data() {
		return {
			regulation: {},
			audienceKind: 'section',
			audience: null,
			dueDate: '',
			message: '',
			preview: {},
			loading: false,
			busy: false,
			notice: null,
		}
	},

	computed: {
		/**
		 * @return {object[]} Section, team or HR function as cards.
		 * @spec openspec/specs/compliance-audit/spec.md
		 */
		kindOptions() {
			const cards = {
				section: [this.t('learniq', 'A section'), this.t('learniq', 'Everyone in the section')],
				team: [this.t('learniq', 'A team'), this.t('learniq', 'Everyone in the team')],
				'hr-function': [this.t('learniq', 'An HR function'), this.t('learniq', 'For example all mentors')],
			}
			return ENROL_AUDIENCES.map((value) => ({ value, label: cards[value][0], description: cards[value][1] }))
		},

		/**
		 * @return {string} The label of the audience field for the chosen kind.
		 * @spec openspec/specs/compliance-audit/spec.md
		 */
		audienceLabel() {
			return { section: this.t('learniq', 'Section'), team: this.t('learniq', 'Team'), 'hr-function': this.t('learniq', 'HR function') }[this.audienceKind]
		},

		/**
		 * @return {object[]} The sections, teams or functions the server offers.
		 * @spec openspec/specs/compliance-audit/spec.md
		 */
		audienceOptions() {
			return this.preview.audiences?.[this.audienceKind] ?? []
		},

		/**
		 * @return {boolean} Whether the preview names an audience to enrol.
		 * @spec openspec/specs/compliance-audit/spec.md
		 */
		previewReady() {
			return Boolean(this.audience) && typeof this.preview.toEnrol === 'number'
		},

		/**
		 * @return {object[]} The first people of the preview.
		 * @spec openspec/specs/compliance-audit/spec.md
		 */
		rows() {
			return Array.isArray(this.preview.rows) ? this.preview.rows : []
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/compliance-audit/spec.md
		 */
		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	watch: {
		/**
		 * A new kind clears the chosen audience and reads its options.
		 *
		 * @spec openspec/specs/compliance-audit/spec.md
		 */
		audienceKind() {
			this.audience = null
			this.loadPreview()
		},

		/**
		 * A new audience reads who it would reach.
		 *
		 * @spec openspec/specs/compliance-audit/spec.md
		 */
		audience() {
			this.loadPreview()
		},
	},

	/**
	 * Read the rule and the audiences it can reach.
	 *
	 * @spec openspec/specs/compliance-audit/spec.md
	 */
	async created() {
		try {
			this.regulation = await readObject('regulation', this.objectId)
		} catch {
			// The title stays empty; the preview carries what the dialog needs.
		}
		this.loadPreview()
	},

	methods: {
		/**
		 * Ask the server who the chosen audience would reach.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/compliance-audit/spec.md
		 */
		async loadPreview() {
			this.loading = true
			this.notice = null
			try {
				const { data } = await axios.get(generateUrl(`${ASSIGN_URL}/preview`, { id: this.objectId }), {
					params: { audienceKind: this.audienceKind, audience: this.audience ?? '' },
				})
				this.preview = data ?? {}
			} catch (error) {
				this.preview = {}
				this.notice = requestRefusal(error, this.t('learniq', 'No preview.'), this.t('learniq', 'Who this enrols could not be read, so nothing can be sent yet.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Enrol the previewed audience and close.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/compliance-audit/spec.md
		 */
		async enrol() {
			this.busy = true
			this.notice = null
			try {
				const { data } = await axios.post(
					generateUrl(ASSIGN_URL, { id: this.objectId }),
					groupEnrolBody({ audienceKind: this.audienceKind, audience: this.audience, dueDate: this.dueDate, message: this.message }),
				)
				announceSaved('learniq:regulation:group-enrolled', { regulationId: this.objectId, result: data })
				this.$emit('close')
			} catch (error) {
				this.notice = requestRefusal(error, this.t('learniq', 'Not enrolled.'), this.t('learniq', 'The group could not be enrolled.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.group-enrol {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.group-enrol__pair {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.group-enrol__counts {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 12px;
}

.group-enrol__count {
	display: flex;
	flex-direction: column;
	padding: 10px 14px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.group-enrol__table {
	width: 100%;
	border-collapse: collapse;
}

.group-enrol__table th,
.group-enrol__table td {
	padding: 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.group-enrol__table td strong {
	display: block;
}

.group-enrol__muted {
	color: var(--color-text-maxcontrast);
}
</style>
