<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CriterionResultDialog (governance-wcag-evidence-report).

 An accessibility owner records the result of one WCAG criterion: pass, fail,
 not applicable or not tested, how it was tested, where the evidence is and
 when. A failure asks for the known limitation that discloses it; without one
 the dialog warns that the statement cannot be published, which is the rule
 AccessibilityStatementPublishGuard enforces on the server.

 @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
-->
<template>
	<NcDialog
		:open="true"
		:name="
			t('learniq', 'Record the result of {criterion}', {
				criterion: row.criterion,
			})
		"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<div class="criterion-result-dialog">
			<p class="criterion-result-dialog__title">
				{{ row.criterion }} {{ row.title }} ({{ row.level }})
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcSelect
				v-model="result"
				:inputLabel="t('learniq', 'Result')"
				:options="resultOptions"
				:reduce="(opt) => opt.value"
				:clearable="false" />

			<NcTextField
				v-model="method"
				:label="t('learniq', 'Method')"
				:maxlength="200" />

			<NcTextField
				v-model="evidenceReference"
				:label="t('learniq', 'Evidence reference')"
				:maxlength="500" />

			<div class="criterion-result-dialog__field">
				<label for="criterion-result-tested-on">{{
					t('learniq', 'Tested on')
				}}</label>
				<input
					id="criterion-result-tested-on"
					v-model="testedOn"
					type="date" />
			</div>

			<template v-if="result === 'fail'">
				<NcSelect
					v-model="limitationId"
					:inputLabel="t('learniq', 'Known limitation')"
					:options="limitationOptions"
					:reduce="(opt) => opt.value" />
				<NcNoteCard v-if="blocksPublishing" type="warning">
					{{
						t(
							'learniq',
							'A failing criterion needs a known limitation before the statement can be published.',
						)
					}}
				</NcNoteCard>
			</template>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('learniq', 'Close') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="submit">
				{{ saving ? t('learniq', 'Saving…') : t('learniq', 'Save result') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { useObjectStore } from '@conduction/nextcloud-vue'
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import {
	limitationsFor,
	needsLimitation,
	resultPayload,
} from '../utils/conformance.js'

const RESULT_LABELS = {
	pass: 'Pass',
	fail: 'Fail',
	'not-applicable': 'Not applicable',
	'not-tested': 'Not tested',
}

export default {
	name: 'CriterionResultDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The table row: criterion, level, title and the current values. */
		row: {
			type: Object,
			required: true,
		},

		/** The stored record for this criterion, or null. */
		record: {
			type: Object,
			default: null,
		},

		/** The statement the result belongs to. */
		statement: {
			type: Object,
			required: true,
		},

		/** The statement's limitations. */
		limitations: {
			type: Array,
			default: () => [],
		},

		/** The object-store type the records are registered under. */
		objectType: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			result: this.row.result || 'not-tested',
			method: this.row.method || '',
			evidenceReference: this.row.evidenceReference || '',
			testedOn: this.row.testedOn || '',
			limitationId: this.row.limitationId || null,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The four results, labelled.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-an-owner-records-results
		 */
		resultOptions() {
			return Object.keys(RESULT_LABELS).map((value) => ({
				value,
				label: this.t('learniq', RESULT_LABELS[value]),
			}))
		},

		/**
		 * The limitations this failure can link.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-a-failure-needs-a-limitation
		 */
		limitationOptions() {
			return limitationsFor(this.row.criterion, this.limitations).map(
				(limitation) => ({
					value: limitation.id,
					label: `${limitation.wcagCriterion}: ${limitation.description || limitation.affectedSurface || ''}`,
				}),
			)
		},

		/**
		 * Whether this result would stop the statement from publishing.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-a-failure-needs-a-limitation
		 */
		blocksPublishing() {
			return needsLimitation(this.result, this.limitationId)
		},
	},

	methods: {
		/**
		 * Save the result through OpenRegister and hand it to the page.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-an-owner-records-results
		 */
		async submit() {
			this.saving = true
			this.error = ''
			try {
				const payload = resultPayload(
					{
						result: this.result,
						method: this.method,
						evidenceReference: this.evidenceReference,
						testedOn: this.testedOn,
						limitationId: this.limitationId,
					},
					{
						statementId: this.statement.id,
						tenantId: this.statement.tenant_id,
						criterion: this.row.criterion,
						level: this.row.level,
					},
				)
				if (this.record?.id) {
					payload.id = this.record.id
				}
				const store = useObjectStore()
				const saved = await store.saveObject(this.objectType, payload)
				if (!saved) {
					throw new Error('not saved')
				}
				this.$emit('saved', saved)
			} catch {
				this.error = this.t(
					'learniq',
					'The result could not be saved. Check that you may edit the accessibility statement.',
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.criterion-result-dialog {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);

	&__title {
		font-weight: bold;
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline, 4px);
	}
}
</style>
