<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LearniqAccessibilityStatement — the toegankelijkheidsverklaring disclosure
 surface (accessibility-conformance-statement).

 A purpose-built read surface over cross-schema data (mirrors
 LearniqCompliance.vue's role), NOT a generic detail page: it resolves the
 current `published` AccessibilityStatement itself (there is no `:id` route
 param — this is a singleton disclosure page reachable by every authenticated
 user, no visibleIf role gate, per design.md Decision 3) and its linked
 AccessibilityLimitation rows, renders every mandatory field from the Dutch
 government model in the invulassistent's field order, and offers a
 persistent "Report an accessibility problem" entry point that opens the
 generic AccessibilityFeedback create form (AccessibilityFeedbackCreate,
 route /accessibility/feedback/new) — no bespoke ticketing UI.

 @spec openspec/specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-model-s-mandatory-fields
 @spec openspec/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
 @spec openspec/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier

 governance-wcag-evidence-report: below the limitations sits the conformance
 table, one row per WCAG 2.1 A and AA criterion, read from the same public
 evidence endpoint an anonymous visitor downloads from, with the downloads
 and a link to the public statement page. A compliance officer or admin
 records a result per row in CriterionResultDialog.

 @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
 @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
-->

<template>
	<div class="accessibility-statement">
		<NcLoadingIcon
			v-if="loading"
			:size="44"
			class="accessibility-statement__loading" />

		<NcEmptyContent
			v-else-if="!statement"
			:name="t('learniq', 'No accessibility statement published yet')"
			:description="
				t(
					'learniq',
					'The compliance officer has not published a toegankelijkheidsverklaring for this environment yet.',
				)
			">
			<template #icon>
				<span class="icon-info" />
			</template>
			<template #action>
				<NcButton variant="primary" @click="openFeedbackForm">
					{{ t('learniq', 'Report an accessibility problem') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<div class="accessibility-statement__header">
				<h2>{{ t('learniq', 'Accessibility statement') }}</h2>
				<NcButton variant="primary" @click="openFeedbackForm">
					{{ t('learniq', 'Report an accessibility problem') }}
				</NcButton>
			</div>

			<dl class="accessibility-statement__fields">
				<dt>{{ t('learniq', 'Channel') }}</dt>
				<dd>{{ statement.channelTitle }}</dd>

				<dt>{{ t('learniq', 'Conformance status') }}</dt>
				<dd>{{ statusLabel }}</dd>

				<dt>{{ t('learniq', 'Evaluation method') }}</dt>
				<dd>{{ statement.evaluationMethod }}</dd>

				<dt>{{ t('learniq', 'Evaluation date') }}</dt>
				<dd>{{ statement.evaluationDate }}</dd>

				<dt>{{ t('learniq', 'Standard applied') }}</dt>
				<dd>{{ statement.standardApplied }}</dd>

				<dt>{{ t('learniq', 'Feedback contact') }}</dt>
				<dd>{{ statement.feedbackContact }}</dd>

				<dt>{{ t('learniq', 'Escalation route') }}</dt>
				<dd>{{ statement.escalationRoute }}</dd>

				<dt v-if="statement.lastReviewedAt">
					{{ t('learniq', 'Last reviewed') }}
				</dt>
				<dd v-if="statement.lastReviewedAt">
					{{ statement.lastReviewedAt }}
				</dd>
			</dl>

			<h3>{{ t('learniq', 'Known limitations') }}</h3>
			<p
				v-if="limitations.length === 0"
				class="accessibility-statement__no-limitations">
				{{ t('learniq', 'No known limitations are currently logged.') }}
			</p>
			<table v-else class="accessibility-statement__limitations">
				<thead>
					<tr>
						<th scope="col">{{ t('learniq', 'WCAG criterion') }}</th>
						<th scope="col">{{ t('learniq', 'Severity') }}</th>
						<th scope="col">{{ t('learniq', 'Affected surface') }}</th>
						<th scope="col">{{ t('learniq', 'Status') }}</th>
						<th scope="col">{{ t('learniq', 'Planned fix date') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="limitation in limitations" :key="limitation.id">
						<td>{{ limitation.wcagCriterion }}</td>
						<td>{{ limitation.severity }}</td>
						<td>{{ limitation.affectedSurface }}</td>
						<td>{{ limitation.lifecycle }}</td>
						<td>
							{{
								limitation.plannedFixDate
								|| t('learniq', 'Not yet determined')
							}}
						</td>
					</tr>
				</tbody>
			</table>

			<h3>{{ t('learniq', 'Conformance per WCAG criterion') }}</h3>
			<p class="accessibility-statement__summary">
				{{
					t(
						'learniq',
						'{pass} pass, {fail} fail, {notApplicable} not applicable, {notTested} not tested, of {total} criteria.',
						{
							pass: summary.pass,
							fail: summary.fail,
							notApplicable: summary['not-applicable'],
							notTested: summary['not-tested'],
							total: summary.total,
						},
					)
				}}
			</p>
			<p class="accessibility-statement__downloads">
				<a :href="evidenceUrl('csv')" download>{{
					t('learniq', 'Download evidence as CSV')
				}}</a>
				<a :href="evidenceUrl('json')" download>{{
					t('learniq', 'Download evidence as JSON')
				}}</a>
				<a :href="publicPageUrl">{{
					t('learniq', 'Public statement page')
				}}</a>
			</p>
			<table class="accessibility-statement__limitations">
				<thead>
					<tr>
						<th scope="col">{{ t('learniq', 'WCAG criterion') }}</th>
						<th scope="col">{{ t('learniq', 'Level') }}</th>
						<th scope="col">{{ t('learniq', 'Result') }}</th>
						<th scope="col">{{ t('learniq', 'Method') }}</th>
						<th scope="col">{{ t('learniq', 'Evidence reference') }}</th>
						<th scope="col">{{ t('learniq', 'Tested on') }}</th>
						<th scope="col">{{ t('learniq', 'Known limitation') }}</th>
						<th v-if="canEdit" scope="col">
							<span class="hidden-visually">{{
								t('learniq', 'Actions')
							}}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in criteria" :key="row.criterion">
						<td>{{ row.criterion }} {{ row.title }}</td>
						<td>{{ row.level }}</td>
						<td>{{ resultLabel(row.result) }}</td>
						<td>{{ row.method }}</td>
						<td>
							<a
								v-if="isLink(row.evidenceReference)"
								:href="row.evidenceReference"
								rel="noopener noreferrer"
								target="_blank"
								>{{
									t('learniq', 'Evidence for {criterion}', {
										criterion: row.criterion,
									})
								}}</a
							>
							<span v-else>{{ row.evidenceReference }}</span>
						</td>
						<td>{{ row.testedOn }}</td>
						<td>{{ row.limitation }}</td>
						<td v-if="canEdit">
							<NcButton
								variant="tertiary"
								:aria-label="
									t(
										'learniq',
										'Record the result of {criterion}',
										{ criterion: row.criterion },
									)
								"
								@click="editing = row">
								{{ t('learniq', 'Record result') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<CriterionResultDialog
				v-if="editing"
				:row="editing"
				:record="recordOf(editing.criterion)"
				:statement="statement"
				:limitations="limitations"
				:objectType="RESULT_TYPE"
				@close="editing = null"
				@saved="onSaved" />
		</template>
	</div>
</template>

<script>
import { useObjectStore } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CriterionResultDialog from '../dialogs/CriterionResultDialog.vue'
import { recordFor, summarise } from '../utils/conformance.js'

const REGISTER = 'learniq'
// Address a schema by the SLUG it declares in lib/Settings/learniq_register.json,
// read verbatim — never by its PascalCase schema key.
//
// The resolver (OpenRegister SchemaMapper::findBySlugInIds) lowercases BOTH
// sides, so the invariant is strtolower(<url segment>) === strtolower(<slug>).
// ⚠️ Casing is therefore NOT what breaks: structure is. 'AccessibilityStatement'
// lowercases to 'accessibilitystatement', which is not the declared slug
// 'accessibility-statement' — the hyphen is the difference. setSchema() then
// rethrows DoesNotExistException and the request 404s.
//
// ⚠️ Do NOT generalise this into "kebab-case the schema name". learniq is the
// fleet outlier in declaring hyphenated slugs for most (not all) of its
// schemas — AiFeature, for one, declares its slug as literally 'AiFeature' —
// and other apps declare camelCase or PascalCase slugs. Kebab-casing those
// would introduce exactly this bug. Always look the slug up.
const STATEMENT_SCHEMA = 'accessibility-statement'
const LIMITATION_SCHEMA = 'accessibility-limitation'
const STATEMENT_TYPE = `${REGISTER}-${STATEMENT_SCHEMA}`
const LIMITATION_TYPE = `${REGISTER}-${LIMITATION_SCHEMA}`
const RESULT_SCHEMA = 'accessibility-criterion-result'
const RESULT_TYPE = `${REGISTER}-${RESULT_SCHEMA}`

// The roles that may record results: the same gate as the Accessibility
// limitations menu entry in src/manifest.d/dashboard.json. The register's
// own authorization is what actually refuses a write.
const EDITOR_ROLES = ['compliance-officer', 'admin']

const RESULT_LABELS = {
	pass: 'Pass',
	fail: 'Fail',
	'not-applicable': 'Not applicable',
	'not-tested': 'Not tested',
}

const STATUS_LABELS = {
	'fully-compliant': 'Fully compliant',
	'partially-compliant': 'Partially compliant',
	'non-compliant': 'Not compliant',
}

export default {
	name: 'LearniqAccessibilityStatement',

	components: {
		CriterionResultDialog,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			statement: null,
			limitations: [],
			criteria: [],
			records: [],
			editing: null,
			canEdit: EDITOR_ROLES.includes(
				loadState('learniq', 'primaryRole', 'learner'),
			),

			RESULT_TYPE,
		}
	},

	computed: {
		/**
		 * Human-readable label for the statement's 3-value status.
		 *
		 * @return {string} The translated status label.
		 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-model-s-mandatory-fields
		 */
		statusLabel() {
			if (!this.statement || !this.statement.status) {
				return ''
			}
			return this.t(
				'learniq',
				STATUS_LABELS[this.statement.status] ?? this.statement.status,
			)
		},

		/**
		 * Counts per result over the conformance table.
		 *
		 * @return {object} `{ pass, fail, 'not-applicable', 'not-tested', total }`.
		 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-untested-criteria-are-visible
		 */
		summary() {
			return summarise(this.criteria)
		},

		/**
		 * The public statement page a signed-out visitor can open.
		 *
		 * @return {string}
		 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
		 */
		publicPageUrl() {
			return generateUrl(
				'/apps/learniq/public/accessibility-statement?statement={id}',
				{ id: this.statementId },
			)
		},

		/**
		 * The uuid of the shown statement.
		 *
		 * @return {string}
		 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
		 */
		statementId() {
			if (!this.statement) {
				return ''
			}
			return this.statement.id ?? this.statement['@self']?.id ?? ''
		},
	},

	async mounted() {
		await this.loadStatement()
	},

	methods: {
		/**
		 * Resolve the current `published` AccessibilityStatement and its
		 * linked AccessibilityLimitation rows.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-model-s-mandatory-fields
		 */
		async loadStatement() {
			this.loading = true
			const store = useObjectStore()

			if (typeof store.registerObjectType === 'function') {
				store.registerObjectType(STATEMENT_TYPE, STATEMENT_SCHEMA, REGISTER)
				store.registerObjectType(
					LIMITATION_TYPE,
					LIMITATION_SCHEMA,
					REGISTER,
				)
			}

			const results =
				typeof store.fetchCollection === 'function'
					? await store
							.fetchCollection(STATEMENT_TYPE, {
								lifecycle: 'published',
								_limit: 1,
							})
							.catch(() => [])
					: []
			this.statement =
				Array.isArray(results) && results.length > 0 ? results[0] : null

			if (this.statement) {
				const statementId =
					this.statement.id
					?? (this.statement['@self'] && this.statement['@self'].id)
				const limitationResults =
					typeof store.fetchCollection === 'function'
						? await store
								.fetchCollection(LIMITATION_TYPE, {
									accessibilityStatementId: statementId,
								})
								.catch(() => [])
						: []
				this.limitations = Array.isArray(limitationResults)
					? limitationResults
					: []
				await this.loadConformance()
			} else {
				this.limitations = []
			}

			this.loading = false
		},

		/**
		 * Navigate to the generic AccessibilityFeedback create form — no
		 * bespoke ticketing dialog, reuses the manifest's declarative
		 * no-id detail create-mode route (ADR-062), same pattern as
		 * course-evaluation's ImprovementActionCreate.
		 *
		 * @return {void}
		 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
		 */
		openFeedbackForm() {
			this.$router.push('/accessibility/feedback/new')
		},

		/**
		 * Read the conformance table from the public evidence endpoint and,
		 * for an editor, the stored result records the dialog updates.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
		 */
		async loadConformance() {
			try {
				const response = await axios.get(this.evidenceUrl('json'))
				this.criteria = Array.isArray(response.data?.criteria)
					? response.data.criteria
					: []
			} catch {
				this.criteria = []
			}

			if (!this.canEdit) {
				return
			}
			const store = useObjectStore()
			if (typeof store.registerObjectType === 'function') {
				store.registerObjectType(RESULT_TYPE, RESULT_SCHEMA, REGISTER)
			}
			const records =
				typeof store.fetchCollection === 'function'
					? await store
							.fetchCollection(RESULT_TYPE, {
								accessibilityStatementId: this.statementId,
							})
							.catch(() => [])
					: []
			this.records = Array.isArray(records) ? records : []
		},

		/**
		 * The evidence download of the shown statement.
		 *
		 * @param {string} format `csv` or `json`.
		 * @return {string}
		 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-a-procurement-officer-asks-for-evidence
		 */
		evidenceUrl(format) {
			return generateUrl(
				'/apps/learniq/api/accessibility/evidence?format={format}&statement={id}',
				{ format, id: this.statementId },
			)
		},

		/**
		 * The label of a result.
		 *
		 * @param {string} result The stored result.
		 * @return {string}
		 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
		 */
		resultLabel(result) {
			return this.t(
				'learniq',
				RESULT_LABELS[result] ?? RESULT_LABELS['not-tested'],
			)
		},

		/**
		 * Whether an evidence reference is a web link to render as one.
		 *
		 * @param {string|null} reference The evidence reference.
		 * @return {boolean}
		 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-an-owner-records-results
		 */
		isLink(reference) {
			return typeof reference === 'string' && /^https?:\/\//.test(reference)
		},

		/**
		 * The stored record of a criterion, for the dialog to update.
		 *
		 * @param {string} criterion The criterion number.
		 * @return {object|null}
		 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
		 */
		recordOf(criterion) {
			return recordFor(criterion, this.records)
		},

		/**
		 * A result was saved: close the dialog and read the table again.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-an-owner-records-results
		 */
		async onSaved() {
			this.editing = null
			await this.loadConformance()
		},
	},
}
</script>

<style scoped lang="scss">
.accessibility-statement {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-width: 900px;

	&__loading {
		margin: calc(var(--default-grid-baseline, 4px) * 8) auto;
	}

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
	}

	&__fields {
		display: grid;
		grid-template-columns: max-content 1fr;
		gap: calc(var(--default-grid-baseline, 4px) * 2)
			calc(var(--default-grid-baseline, 4px) * 4);
		margin-bottom: calc(var(--default-grid-baseline, 4px) * 6);

		dt {
			font-weight: bold;
			color: var(--color-text-maxcontrast);
		}

		dd {
			margin: 0;
		}
	}

	&__limitations {
		width: 100%;
		border-collapse: collapse;

		th,
		td {
			text-align: left;
			padding: calc(var(--default-grid-baseline, 4px) * 2);
			border-bottom: 1px solid var(--color-border);
		}
	}

	&__no-limitations,
	&__summary {
		color: var(--color-text-maxcontrast);
	}

	&__downloads {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline, 4px) * 4);
		margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);

		a {
			text-decoration: underline;
		}
	}
}
</style>
