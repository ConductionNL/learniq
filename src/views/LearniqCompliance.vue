<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LearniqCompliance — compliance dashboard page.
 Renders KPI tiles for regulations and signed attestations, the
 per-department compliance roll-up (learniq#951), the coverage per rule
 (compliance-rule-coverage-table), and a "View in LaunchPad" header action.

 @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
-->
<template>
	<CnDashboardPage
		:title="t('learniq', 'Compliance')"
		:widgets="widgets"
		:layout="layout"
		:showActionsMenu="false">
		<template #widget-department-compliance>
			<DepartmentComplianceWidget />
		</template>
		<template #widget-rule-coverage>
			<RegulationCoverageWidget />
		</template>
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
import DepartmentComplianceWidget from './widgets/DepartmentComplianceWidget.vue'
import RegulationCoverageWidget from './widgets/RegulationCoverageWidget.vue'
import {
	countTile,
	customTile,
	freshLayout,
} from '../utils/dashboardTiles.js'

/** The compliance dashboard grid: the three counts, then the two coverage lists. */
const COMPLIANCE_LAYOUT = [
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
	{
		id: 4,
		widgetId: 'department-compliance',
		gridX: 0,
		gridY: 4,
		gridWidth: 12,
		gridHeight: 6,
		showTitle: true,
	},
	{
		id: 5,
		widgetId: 'rule-coverage',
		gridX: 0,
		gridY: 10,
		gridWidth: 12,
		gridHeight: 6,
		showTitle: true,
	},
]

export default {
	name: 'LearniqCompliance',

	components: {
		CnDashboardPage,
		DepartmentComplianceWidget,
		NcButton,
		RegulationCoverageWidget,
	},

	data() {
		return {
			layout: freshLayout(COMPLIANCE_LAYOUT),
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
				countTile('kpi-regulations', this.t('learniq', 'Regulations')),
				countTile('kpi-attestations', this.t('learniq', 'Signed attestations')),
				countTile('kpi-external-training', this.t('learniq', 'External training')),
				customTile('department-compliance', this.t('learniq', 'Compliance per department')),
				customTile('rule-coverage', this.t('learniq', 'Coverage per rule')),
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
