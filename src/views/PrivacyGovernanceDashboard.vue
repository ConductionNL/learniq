<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 PrivacyGovernanceDashboard — the board-facing privacy governance surface
 (privacy-governance-surfaces, P-new-6/P-new-7). Mirrors
 LearniqAiProcessingDisclosure.vue's shape: a singleton disclosure page (no
 `:id` route), read-only, composing PrivacyGovernanceController's
 server-side read of rbac-declare-groups member counts, best-effort
 two-factor adoption, and DataExchangeJob partner-approval counts.

 Never renders a fabricated count: a `null` field (group not provisioned,
 2FA registry unavailable, DataExchangeJob read failed) shows as "unknown",
 never as 0.

 @spec openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#requirement-a-board-facing-dashboard-composes-group-2fa-and-integration-approval-state
-->

<template>
	<div class="privacy-governance-dashboard">
		<NcLoadingIcon
			v-if="loading"
			:size="44"
			class="privacy-governance-dashboard__loading" />

		<template v-else>
			<h2>{{ t('learniq', 'Privacy governance') }}</h2>
			<p class="privacy-governance-dashboard__intro">
				{{
					t(
						'learniq',
						'A board-level view of who has access, whether accounts are protected, and which data-exchange partners are approved.',
					)
				}}
			</p>

			<NcNoteCard v-if="loadError" type="warning">
				{{
					t(
						'learniq',
						'Some figures could not be loaded. Unknown values show as "unknown", never as zero.',
					)
				}}
			</NcNoteCard>

			<section class="privacy-governance-dashboard__section">
				<h3>{{ t('learniq', 'Groups') }}</h3>
				<table class="privacy-governance-dashboard__table">
					<thead>
						<tr>
							<th scope="col">{{ t('learniq', 'Group') }}</th>
							<th scope="col">{{ t('learniq', 'Provisioned') }}</th>
							<th scope="col">{{ t('learniq', 'Members') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="group in groups" :key="group.id">
							<td>{{ group.id }}</td>
							<td>
								{{
									group.provisioned
										? t('learniq', 'Yes')
										: t('learniq', 'No')
								}}
							</td>
							<td>{{ formatCount(group.memberCount) }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="privacy-governance-dashboard__section">
				<h3>{{ t('learniq', 'Two-factor adoption') }}</h3>
				<p>
					{{
						t(
							'learniq',
							'{enabled} of {eligible} staff accounts have two-factor authentication on.',
							{
								enabled: formatCount(twoFactor.enabledCount),
								eligible: formatCount(twoFactor.eligibleCount),
							},
						)
					}}
				</p>
			</section>

			<section class="privacy-governance-dashboard__section">
				<h3>{{ t('learniq', 'Partner integration approvals') }}</h3>
				<p>
					{{
						t(
							'learniq',
							'{pending} pending, {approved} approved, {rejected} rejected.',
							{
								pending: formatCount(dataExchange.pending),
								approved: formatCount(dataExchange.approved),
								rejected: formatCount(dataExchange.rejected),
							},
						)
					}}
				</p>
				<NcButton variant="secondary" @click="openPartnerApprovals">
					{{ t('learniq', 'Review partner approvals') }}
				</NcButton>
			</section>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'PrivacyGovernanceDashboard',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			loading: true,
			loadError: false,
			groups: [],
			twoFactor: { eligibleCount: null, enabledCount: null },
			dataExchange: { pending: null, approved: null, rejected: null },
		}
	},

	/**
	 * Load the overview payload as soon as the page mounts.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#requirement-a-board-facing-dashboard-composes-group-2fa-and-integration-approval-state
	 */
	async mounted() {
		await this.loadOverview()
		this.loading = false
	},

	methods: {
		/**
		 * Render a count, or "unknown" when the server could not resolve it
		 * (never a fabricated 0).
		 *
		 * @param {number|null} value The count, or null when unknown.
		 * @return {string} The rendered value.
		 * @spec openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#scenario-two-factor-adoption-degrades-to-unknown-rather-than-a-fabricated-zero
		 */
		formatCount(value) {
			if (value === null || value === undefined) {
				return this.t('learniq', 'unknown')
			}
			return String(value)
		},

		/**
		 * Load the server-composed governance overview from
		 * PrivacyGovernanceController::overview().
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/privacy-governance-surfaces/specs/avg-verwerkingsregister/spec.md#requirement-a-board-facing-dashboard-composes-group-2fa-and-integration-approval-state
		 */
		async loadOverview() {
			try {
				const url = generateUrl(
					'/apps/learniq/api/privacy-governance/overview',
				)
				const resp = await fetch(url, {
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
					},
				})
				if (!resp.ok) {
					throw new Error(
						`privacy governance overview fetch failed: ${resp.status}`,
					)
				}
				const data = await resp.json()
				this.groups = Array.isArray(data.groups) ? data.groups : []
				this.twoFactor = data.twoFactor || {
					eligibleCount: null,
					enabledCount: null,
				}
				this.dataExchange = data.dataExchange || {
					pending: null,
					approved: null,
					rejected: null,
				}
			} catch (err) {
				this.loadError = true
				// eslint-disable-next-line no-console
				console.error('[PrivacyGovernanceDashboard] loadOverview error', err)
			}
		},

		/**
		 * Navigate to the partner-approval index page.
		 *
		 * @return {void}
		 * @spec openspec/changes/privacy-governance-surfaces/specs/data-exchange/spec.md#requirement-a-dataexchangejob-target-can-require-standing-partner-approval-before-it-runs
		 */
		openPartnerApprovals() {
			this.$router.push({ name: 'PartnerApprovalJobs' })
		},
	},
}
</script>

<style scoped>
.privacy-governance-dashboard {
	padding: 20px;
	max-width: 900px;
}

.privacy-governance-dashboard__loading {
	margin: 40px auto;
}

.privacy-governance-dashboard__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
}

.privacy-governance-dashboard__section {
	margin-bottom: 28px;
}

.privacy-governance-dashboard__table {
	width: 100%;
	border-collapse: collapse;
}

.privacy-governance-dashboard__table th,
.privacy-governance-dashboard__table td {
	text-align: start;
	padding: 6px 12px;
	border-bottom: 1px solid var(--color-border);
}
</style>
