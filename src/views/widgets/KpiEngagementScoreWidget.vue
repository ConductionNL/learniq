<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 KPI tile: average EngagementScore.score across the tenant.
 learning-progress-and-analytics. Unlike KpiCard (a plain OR object COUNT),
 this widget needs an AVERAGE — OpenRegister's object listing endpoint
 returns a count/total directly, but not a pre-aggregated average, so this
 widget fetches the EngagementScore rows and averages .score client-side.
 Still a declarative KPI-tile widget in the same Kpi*Widget.vue pattern
 (small wrapper delegating render to CnStatsBlock) — not a new chart
 component.
-->
<template>
	<div
		class="kpi-engagement-score"
		:class="link ? 'kpi-engagement-score--linkable' : ''">
		<CnStatsBlock
			:title="t('scholiq', 'Avg. engagement score')"
			:count="displayValue"
			:loading="loading"
			variant="primary"
			:route="routeTarget"
			horizontal />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { CnStatsBlock } from '@conduction/nextcloud-vue'

export default {
	name: 'KpiEngagementScoreWidget',

	components: {
		CnStatsBlock,
	},

	data() {
		return {
			average: null,
			loading: true,
			link: '/progress/engagement-scores',
		}
	},

	computed: {
		/**
		 * "—" while no EngagementScore rows exist yet, otherwise the rounded
		 * average.
		 *
		 * @return {string|number}
		 */
		displayValue() {
			return this.average === null ? '—' : this.average
		},

		/**
		 * Vue Router location for the tile, or null when the tile is static.
		 *
		 * Passing `route` (rather than `clickable` + a click handler) makes
		 * CnStatsBlock render a real <router-link>, so the tile is reachable by
		 * keyboard, exposed to assistive tech as a link, and supports
		 * middle-click / open-in-new-tab. A bare <div @click> was none of those.
		 *
		 * @return {object|null} Router location object, or null when not linkable.
		 * @spec openspec/specs/student-analytics/spec.md#requirement-persist-engagementscore-domain-objects-in-openregister
		 */
		routeTarget() {
			return this.link ? { path: this.link } : null
		},
	},

	created() {
		this.fetchAverage()
	},

	methods: {
		/**
		 * Fetch every EngagementScore row and average .score client-side.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#requirement-persist-engagementscore-domain-objects-in-openregister
		 */
		async fetchAverage() {
			this.loading = true
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/scholiq/engagement-score?_limit=200',
				)
				const response = await axios.get(url)
				const data = response.data ?? {}
				const rows = data.results ?? (Array.isArray(data) ? data : [])
				const scored = rows.filter((r) => typeof r.score === 'number')
				this.average =
					scored.length === 0
						? null
						: Math.round(
								scored.reduce((sum, r) => sum + r.score, 0)
									/ scored.length,
							)
			} catch {
				this.average = null
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.kpi-engagement-score {
	height: 100%;
	display: flex;
	align-items: center;
	padding: 4px;
}

.kpi-engagement-score--linkable {
	cursor: pointer;
}

.kpi-engagement-score :deep(.cn-stats-block__header h4) {
	white-space: normal;
	overflow: visible;
	line-height: 1.2;
}
</style>
