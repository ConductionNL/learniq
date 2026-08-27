<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ComposeReportPeriodModal — report-card-composer.

 Confirms a ReportPeriod's composition scope (curriculumPlanIds/cohortIds
 summary) and `isLocked` state before triggering the `compose` transition —
 no manifest page can express a bulk cross-object action, per report-card's
 own "Frontend is declarative with two named custom views" requirement.
 Blocks with an explanatory message when the period is not yet locked
 (ReportPeriodComposeGuard is the actual server-side enforcement; this dialog
 only pre-empts a doomed request with the same reasoning surfaced to the
 user).

 Opened by RapportvergaderingReviewView for an `open` ReportPeriod.

 @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
 @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-is-blocked-before-the-lock-date
 @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
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
		<div class="compose-report-period">
			<NcLoadingIcon v-if="loading" :size="32" />

			<template v-else-if="period">
				<dl class="compose-report-period__summary">
					<dt>{{ t('learniq', 'Academic year') }}</dt>
					<dd>{{ period.academicYear || '—' }}</dd>
					<dt>{{ t('learniq', 'Period code') }}</dt>
					<dd>{{ period.periodCode || '—' }}</dd>
					<dt>{{ t('learniq', 'Subjects in scope') }}</dt>
					<dd>{{ (period.curriculumPlanIds || []).length }}</dd>
					<dt>{{ t('learniq', 'Cohorts in scope') }}</dt>
					<dd>{{ (period.cohortIds || []).length }}</dd>
				</dl>

				<NcNoteCard v-if="!isLocked" type="warning">
					{{
						t(
							'learniq',
							'This report period is not yet locked — composition is blocked until the lock date has passed. A mentor/admin can still compose manually once locked.',
						)
					}}
				</NcNoteCard>
				<NcNoteCard v-else type="success">
					{{
						t(
							'learniq',
							'This report period is locked. Composing will create one draft report card per learner in scope.',
						)
					}}
				</NcNoteCard>

				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>
			</template>

			<NcNoteCard v-else type="error">
				{{ t('learniq', 'Could not load this report period.') }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('learniq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!period || !isLocked || composing"
				@click="compose">
				{{
					composing
						? t('learniq', 'Composing…')
						: t('learniq', 'Compose report cards')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'ComposeReportPeriodModal',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		reportPeriodId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'composed'],

	data() {
		return {
			loading: true,
			period: null,
			composing: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether this period is locked, and therefore composable.
		 *
		 * ⚠️ READING `isLocked` ALONE DISABLED THIS BUTTON FOREVER. The field is
		 * a declared `x-openregister-calculations` entry with `materialise:
		 * true`, and it IS computed — the create response carries
		 * `isLocked: true` for a past lockDate. It is simply never returned
		 * again: measured on a live instance, both the single-object GET and
		 * the list endpoint omit it entirely. So `period.isLocked` was
		 * `undefined` on every fetch, `=== true` was always false, and Compose
		 * could not be clicked no matter how long ago the period locked.
		 *
		 * ReportPeriodComposeGuard already carries the same fallback and says
		 * why — it mirrors the declared expression exactly (`lockDate` set AND
		 * `lockDate` < now). The two sides now agree instead of one of them
		 * trusting a field that does not survive a read.
		 *
		 * Kept preferring the materialised value: when OpenRegister does start
		 * projecting it, that becomes the single source and this stops
		 * recomputing.
		 *
		 * The requirement defines locked as "`lockDate` is set AND has passed
		 * `@now`" and mandates that the GUARDS read `isLocked` directly — it
		 * says nothing about this dialog, which is why reading the same field
		 * here, with no fallback, went unnoticed.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/report-card/spec.md#requirement-lock-date-is-enforced-by-a-materialised-calculation-and-guards-not-an-automatic-transition
		 */
		isLocked() {
			if (!this.period) {
				return false
			}

			if (this.period.isLocked === true) {
				return true
			}

			const lockDate = this.period.lockDate

			if (lockDate === null || lockDate === undefined || lockDate === '') {
				return false
			}

			const lockedAt = Date.parse(lockDate)

			return Number.isNaN(lockedAt) === false && lockedAt < Date.now()
		},

		/**
		 * Dialog title.
		 *
		 * @return {string}
		 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
		 */
		dialogTitle() {
			if (!this.period) return t('learniq', 'Compose report period')
			return t('learniq', 'Compose "{name}"', {
				name: this.period.name || this.period.periodCode || '',
			})
		},
	},

	async mounted() {
		await this.loadPeriod()
	},

	methods: {
		t,

		/**
		 * Load the ReportPeriod being composed.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
		 */
		async loadPeriod() {
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/learniq/report-period/{id}',
					{ id: this.reportPeriodId },
				)
				const response = await axios.get(url)
				this.period = response.data || null
			} catch (e) {
				console.error('[ComposeReportPeriodModal] loadPeriod failed', e)
				this.period = null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger the ReportPeriod's `compose` transition
		 * (open -> composed, requires ReportPeriodComposeGuard).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
		 */
		async compose() {
			if (!this.period || !this.isLocked) return

			this.composing = true
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/learniq/report-period/{id}',
					{ id: this.reportPeriodId },
				)
				await axios.put(url, { lifecycle: 'composed' })
				this.$emit('composed')
				this.$emit('close')
			} catch (e) {
				console.error('[ComposeReportPeriodModal] compose failed', e)
				this.error = t(
					'learniq',
					'Could not compose report cards. Please try again.',
				)
			} finally {
				this.composing = false
			}
		},
	},
}
</script>

<style scoped>
.compose-report-period {
	min-width: 420px;
	padding: 8px 4px;
}

.compose-report-period__summary {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin-bottom: 12px;
}

.compose-report-period__summary dt {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.compose-report-period__summary dd {
	margin: 0;
}
</style>
