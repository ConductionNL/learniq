<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LearniqCompliance — compliance dashboard page.
 Renders KPI tiles for regulations and signed attestations, plus a
 "View in LaunchPad" header action.

 @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
-->
<template>
	<CnDashboardPage
		:title="t('learniq', 'Compliance')"
		:widgets="widgets"
		:layout="layout">
		<template #header-actions>
			<NcButton variant="secondary" @click="viewInLaunchPad">
				{{ t('learniq', 'View in LaunchPad') }}
			</NcButton>
		</template>
	</CnDashboardPage>
</template>

<script>
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'LearniqCompliance',

	components: {
		CnDashboardPage,
		NcButton,
	},

	data() {
		return {
			layout: [
				{
					id: 1,
					widgetId: 'kpi-regulations',
					gridX: 0,
					gridY: 0,
					gridWidth: 3,
					gridHeight: 2,
					showTitle: false,
				},
				{
					id: 2,
					widgetId: 'kpi-attestations',
					gridX: 3,
					gridY: 0,
					gridWidth: 3,
					gridHeight: 2,
					showTitle: false,
				},
				{
					id: 3,
					widgetId: 'kpi-external-training',
					gridX: 0,
					gridY: 2,
					gridWidth: 3,
					gridHeight: 2,
					showTitle: false,
				},
			],
		}
	},

	computed: {
		/**
		 * The three compliance KPI tiles, declared rather than written.
		 *
		 * `type: 'stat'` resolves to the shared CnStatWidget through the
		 * dashboard widget registry, which counts server-side via the
		 * OpenRegister aggregation API. The wrapper components these replace
		 * each re-implemented the fetch and answered `catch { this.count = 0 }`,
		 * so a backend outage rendered as three confident zeroes.
		 *
		 * Computed rather than `data` so the labels can be translated: the
		 * widget titles here were plain English while the tiles they fronted
		 * were localised, which is the sort of split nobody notices until a
		 * Dutch user reads half a dashboard in English.
		 *
		 * @return {Array<object>} Widget definitions for CnDashboardPage.
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
		 */
		widgets() {
			return [
				{
					id: 'kpi-regulations',
					title: this.t('learniq', 'Regulations'),
					type: 'stat',
					content: {
						label: this.t('learniq', 'Regulations'),
						clickRoute: { path: '/compliance/regulations' },
						source: {
							register: 'learniq',
							schema: 'regulation',
							metric: 'count',
						},
					},
				},
				{
					id: 'kpi-attestations',
					title: this.t('learniq', 'Signed attestations'),
					type: 'stat',
					content: {
						label: this.t('learniq', 'Signed attestations'),
						variant: 'success',
						clickRoute: { path: '/compliance/attestations' },
						source: {
							register: 'learniq',
							schema: 'attestation',
							metric: 'count',
						},
					},
				},
				{
					id: 'kpi-external-training',
					title: this.t('learniq', 'External training'),
					type: 'stat',
					content: {
						label: this.t('learniq', 'External training'),
						variant: 'success',
						clickRoute: { path: '/compliance/external-training' },
						source: {
							register: 'learniq',
							schema: 'external-training-record',
							metric: 'count',
						},
					},
				},
			]
		},
	},

	methods: {
		/**
		 * Open LaunchPad in a new tab for heavier compliance analytics.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
		 */
		viewInLaunchPad() {
			// Open LaunchPad in a new tab — URL is installation-specific.
			window.open('/apps/launchpad', '_blank')
		},
	},
}
</script>
