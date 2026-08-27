<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 KPI tile: the signed-in learner's own totalPoints/level/streak.
 engagement-gamification. Fetches the caller's own LearnerEngagement row
 (self-match x-property-rbac already scopes this to their own row server-side)
 and, when levelId is set, resolves the EngagementLevel's display name.
 Visible unconditionally regardless of any Leaderboard/opt-out state — opting
 out of a peer-visible ranking never hides a learner's own progress from
 themselves (design.md "Pedagogical posture").
 Reuses CnStatsBlock rather than a bespoke chart component.

 THE LAST BESPOKE KPI TILE IN THIS APP, and it is here for a reason. Every
 other tile became a declared `type: 'stat'` widget backed by the shared
 CnStatWidget, which aggregates server-side. This one cannot: it reads the
 current user's `learner-engagement` row and then resolves `levelId` against
 `engagement-level` — a JOIN across two schemas, which no single aggregation
 config expresses.

 The shape that would replace it already exists on the other side:
 CnStatWidget's `caption` resolves `{token}` placeholders from an endpoint
 payload, so once learniq exposes the joined record at one URL this becomes

     endpointSource: { url: '/apps/learniq/api/engagement/me' },
     valueField: 'totalPoints',
     caption: '{levelName} · {currentStreakDays}-day streak',

 and this file goes away. Tracked separately; see the widget-migration PR.

 @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
-->
<template>
	<div class="kpi-points-level">
		<CnStatsBlock
			:title="t('learniq', 'My points')"
			:count="displayPoints"
			:loading="loading"
			variant="primary"
			horizontal />
		<p v-if="!loading" class="kpi-points-level__detail">
			<span v-if="levelName" class="kpi-points-level__level">{{
				levelName
			}}</span>
			<span v-if="streakDays > 0" class="kpi-points-level__streak">
				{{ t('learniq', '{days}-day streak', { days: streakDays }) }}
			</span>
		</p>
	</div>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'KpiPointsLevelWidget',

	components: {
		CnStatsBlock,
	},

	data() {
		return {
			totalPoints: null,
			levelName: null,
			streakDays: 0,
			loading: true,
		}
	},

	computed: {
		/**
		 * "—" until the learner's own LearnerEngagement row loads, otherwise
		 * the rounded total.
		 *
		 * @return {string|number}
		 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
		 */
		displayPoints() {
			return this.totalPoints === null ? '—' : Math.round(this.totalPoints)
		},
	},

	created() {
		this.fetchEngagement()
	},

	methods: {
		/**
		 * Fetch the current user's own LearnerEngagement row and, when a
		 * level is reached, resolve its display name.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
		 */
		async fetchEngagement() {
			this.loading = true
			try {
				const uid = getCurrentUser()?.uid ?? ''
				const params = new URLSearchParams({ learnerId: uid, _limit: '1' })
				const url = generateUrl(
					'/apps/openregister/api/objects/learniq/learner-engagement?'
						+ params.toString(),
				)
				const response = await axios.get(url)
				const data = response.data ?? {}
				const rows = data.results ?? (Array.isArray(data) ? data : [])
				const row = rows[0] ?? null

				if (row) {
					this.totalPoints = row.totalPoints ?? 0
					this.streakDays = row.currentStreakDays ?? 0
					if (row.levelId) {
						await this.fetchLevelName(row.levelId)
					}
				} else {
					this.totalPoints = 0
				}
			} catch {
				this.totalPoints = 0
			} finally {
				this.loading = false
			}
		},

		/**
		 * Resolve an EngagementLevel's display name by id.
		 *
		 * @param {string} levelId UUID of the EngagementLevel.
		 * @return {Promise<void>}
		 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
		 */
		async fetchLevelName(levelId) {
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/learniq/engagement-level/'
						+ levelId,
				)
				const response = await axios.get(url)
				this.levelName = response.data?.name ?? null
			} catch {
				this.levelName = null
			}
		},
	},
}
</script>

<style scoped>
.kpi-points-level {
	height: 100%;
	display: flex;
	flex-direction: column;
	justify-content: center;
	padding: 4px;
}

.kpi-points-level__detail {
	display: flex;
	gap: 12px;
	margin: 4px 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.kpi-points-level__level {
	font-weight: 500;
}
</style>
