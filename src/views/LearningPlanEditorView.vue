<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LearningPlanEditorView: edit the goals of a learning plan
 (route /learning-plans/:planId/edit, learniq#947).

 CnRelationshipGraph shows the plan with its goals and support measures.
 Below it the goals can be added, reworded, given a target, reordered with
 the Up and Down buttons (keyboard operable) and removed. Saving writes the
 goals array back to the plan; every other goal field is kept as it was.

 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<div class="plan-editor">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<template v-else>
			<CnRelationshipGraph
				:nodes="graph.nodes"
				:edges="graph.edges"
				layout="radial"
				:title="t('learniq', 'Learning plan')"
				:ariaLabel="
					t('learniq', 'The plan with its goals and support measures')
				" />

			<h3>{{ t('learniq', 'Goals') }}</h3>
			<ol class="plan-editor__goals">
				<li
					v-for="(goal, index) in goals"
					:key="goal.goalId"
					class="plan-editor__goal">
					<label :for="'goal-desc-' + index">{{
						t('learniq', 'Goal')
					}}</label>
					<input
						:id="'goal-desc-' + index"
						v-model="goal.description"
						type="text"
						required />
					<label :for="'goal-target-' + index">{{
						t('learniq', 'Target (optional)')
					}}</label>
					<input
						:id="'goal-target-' + index"
						v-model="goal.target"
						type="text" />
					<div class="plan-editor__goal-actions">
						<NcButton
							variant="tertiary"
							:disabled="index === 0"
							@click="move(index, -1)">
							{{ t('learniq', 'Up') }}
						</NcButton>
						<NcButton
							variant="tertiary"
							:disabled="index === goals.length - 1"
							@click="move(index, 1)">
							{{ t('learniq', 'Down') }}
						</NcButton>
						<NcButton variant="tertiary" @click="remove(index)">
							{{ t('learniq', 'Remove') }}
						</NcButton>
					</div>
				</li>
			</ol>
			<NcButton variant="secondary" @click="add">
				{{ t('learniq', 'Add a goal') }}
			</NcButton>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="saved" type="success">
				{{ t('learniq', 'The goals are saved.') }}
			</NcNoteCard>
			<div class="plan-editor__actions">
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ t('learniq', 'Save the goals') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { CnRelationshipGraph } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	moveItem,
	nextGoalId,
	objectsUrl,
	oneObject,
	planGraph,
} from '../utils/customPages.js'

export default {
	name: 'LearningPlanEditorView',

	components: { CnRelationshipGraph, NcButton, NcLoadingIcon, NcNoteCard },

	props: {
		/** LearningPlan UUID from the route. */
		planId: { type: String, required: true },
	},

	data() {
		return {
			loading: true,
			loadError: '',
			plan: {},
			goals: [],
			saving: false,
			saved: false,
			error: '',
		}
	},

	computed: {
		/**
		 * @return {{nodes: object[], edges: object[]}} The graph of the edited plan.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		graph() {
			return planGraph({ ...this.plan, goals: this.goals })
		},
	},

	async mounted() {
		try {
			this.plan = oneObject(
				(
					await axios.get(
						generateUrl(objectsUrl('learning-plan', this.planId)),
					)
				).data,
			)
			this.goals = (this.plan.goals ?? []).map((g) => ({ ...g }))
		} catch {
			this.loadError = this.t(
				'learniq',
				'This learning plan could not be loaded.',
			)
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * @return {void}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		add() {
			this.goals = [
				...this.goals,
				{ goalId: nextGoalId(this.goals), description: '', status: 'open' },
			]
			this.saved = false
		},

		/**
		 * @param {number} index Goal index.
		 * @param {number} delta -1 or 1.
		 * @return {void}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		move(index, delta) {
			this.goals = moveItem(this.goals, index, delta)
			this.saved = false
		},

		/**
		 * @param {number} index Goal index.
		 * @return {void}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		remove(index) {
			this.goals = this.goals.filter((_, i) => i !== index)
			this.saved = false
		},

		/**
		 * Write the goals back to the plan.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async save() {
			this.error = ''
			if (this.goals.some((g) => !String(g.description ?? '').trim())) {
				this.error = this.t('learniq', 'Every goal needs a description.')
				return
			}
			this.saving = true
			try {
				await axios.put(
					generateUrl(objectsUrl('learning-plan', this.planId)),
					{ ...this.plan, goals: this.goals },
				)
				this.plan = { ...this.plan, goals: this.goals }
				this.saved = true
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('learniq', 'The goals could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.plan-editor {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-inline-size: 48rem;
}

.plan-editor__goals {
	padding-inline-start: calc(var(--default-grid-baseline, 4px) * 5);
}

.plan-editor__goal {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 4);
}

.plan-editor__goal-actions,
.plan-editor__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-block-start: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>
