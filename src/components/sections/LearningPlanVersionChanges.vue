<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 LearningPlanVersionChanges: on LearningPlanDetail, what changed against the
 version this plan replaces (supersedesId): the goals and support measures
 added, removed or changed, and nothing the two versions share unchanged.
 Hidden on a first version. Reads the earlier version through OpenRegister,
 under the reader's own rights.
 @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#requirement-a-user-opens-a-plans-earlier-versions-and-sees-what-changed-between-them
-->
<template>
	<section v-if="supersedesId" class="plan-version-changes" data-testid="learning-plan-version-changes">
		<h3>{{ t('learniq', 'What changed since version {version}', { version: previousVersion }) }}</h3>
		<NcLoadingIcon v-if="loading" />
		<NcNoteCard v-else-if="failed" type="warning">
			{{ t('learniq', 'The earlier version could not be loaded.') }}
		</NcNoteCard>
		<p v-else-if="changes.length === 0">
			{{ t('learniq', 'The goals and support measures are the same as in the earlier version.') }}
		</p>
		<ul v-else class="plan-version-changes__list">
			<li v-for="item in changes" :key="item.kind + ':' + item.change + ':' + item.id">
				<strong>{{ label(item) }}</strong>
				<span>{{ item.description }}</span>
				<span v-if="item.change === 'changed' && item.before !== item.description" class="plan-version-changes__before">
					{{ t('learniq', 'Previously: {description}', { description: item.before }) }}
				</span>
			</li>
		</ul>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { objectsUrl, oneObject } from '../../utils/customPages.js'
import { planVersionChanges } from '../../utils/planVersionChanges.js'

export default {
	name: 'LearningPlanVersionChanges',
	components: { NcLoadingIcon, NcNoteCard },
	inject: {
		cnSectionContext: { default: null },
	},

	data() {
		return { previous: null, loading: false, failed: false }
	},

	computed: {
		/**
		 * @return {object|null} The plan being read.
		 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#requirement-a-user-opens-a-plans-earlier-versions-and-sees-what-changed-between-them
		 */
		plan() {
			return this.cnSectionContext?.value?.object ?? null
		},

		/**
		 * @return {string} The id of the version this plan replaces, or ''.
		 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#requirement-a-user-opens-a-plans-earlier-versions-and-sees-what-changed-between-them
		 */
		supersedesId() {
			return String(this.plan?.supersedesId ?? '')
		},

		/**
		 * @return {string|number} The earlier version's number, else one below this one.
		 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#requirement-a-user-opens-a-plans-earlier-versions-and-sees-what-changed-between-them
		 */
		previousVersion() {
			const own = Number(this.plan?.version)
			return this.previous?.version ?? (Number.isFinite(own) && own > 1 ? own - 1 : '')
		},

		/**
		 * @return {Array<object>} The changes against the earlier version.
		 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#scenario-a-coordinator-sees-what-changed-between-two-versions
		 */
		changes() {
			return this.previous ? planVersionChanges(this.plan, this.previous) : []
		},
	},

	watch: {
		supersedesId: {
			immediate: true,
			handler(id) {
				this.load(id)
			},
		},
	},

	methods: {
		/**
		 * Read the earlier version.
		 *
		 * @param {string} id The earlier version's id.
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#scenario-a-coordinator-sees-what-changed-between-two-versions
		 */
		async load(id) {
			this.previous = null
			this.failed = false
			if (!id) {
				return
			}

			this.loading = true
			try {
				const response = await axios.get(generateUrl(objectsUrl('learning-plan', id)))
				this.previous = oneObject(response.data)
			} catch {
				this.failed = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {{kind: string, change: string}} item A change.
		 * @return {string} Its label.
		 * @spec exclude Presentation-only label lookup.
		 */
		label(item) {
			const labels = {
				'goal:added': this.t('learniq', 'Goal added'),
				'goal:removed': this.t('learniq', 'Goal removed'),
				'goal:changed': this.t('learniq', 'Goal changed'),
				'measure:added': this.t('learniq', 'Support measure added'),
				'measure:removed': this.t('learniq', 'Support measure removed'),
				'measure:changed': this.t('learniq', 'Support measure changed'),
			}
			return labels[item.kind + ':' + item.change] ?? ''
		},
	},
}
</script>

<style scoped>
.plan-version-changes__list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.plan-version-changes__list li {
	display: flex;
	flex-direction: column;
}

.plan-version-changes__before {
	color: var(--color-text-maxcontrast);
}
</style>
