<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 MyProgrammeProgressWidget: learner-home widget.
 One block per programme the signed-in learner is enrolled in, read from
 GET /api/programmes/progress (ProgrammeProgress). The bar counts the
 mandatory courses only; optional courses are listed under their own heading
 and never count toward completion.

 @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#requirement-progress-counts-mandatory-parts
-->
<template>
	<div class="programme-progress-widget">
		<div v-if="loading" class="programme-progress-widget__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<p
			v-else-if="programmes.length === 0"
			class="programme-progress-widget__empty">
			{{ t('learniq', 'You are not enrolled in a programme') }}
		</p>

		<section
			v-for="programme in programmes"
			v-else
			:key="programme.programmeId"
			class="programme-progress-widget__programme">
			<h3 class="programme-progress-widget__name">
				{{ programme.name || t('learniq', 'Programme') }}
			</h3>
			<div
				class="programme-progress-widget__progress"
				role="progressbar"
				:aria-valuenow="programme.percent"
				aria-valuemin="0"
				aria-valuemax="100"
				:aria-label="
					t('learniq', 'Mandatory courses done: {percent}%', {
						percent: programme.percent,
					})
				">
				<div class="programme-progress-widget__track">
					<div
						class="programme-progress-widget__fill"
						:style="{ width: programme.percent + '%' }" />
				</div>
				<span class="programme-progress-widget__label">
					{{
						t('learniq', '{done} of {total} mandatory courses done', {
							done: programme.mandatoryCompleted,
							total: programme.mandatoryTotal,
						})
					}}
				</span>
			</div>
			<ul class="programme-progress-widget__list">
				<li
					v-for="part in programme.mandatory"
					:key="part.courseId"
					class="programme-progress-widget__part">
					<span class="programme-progress-widget__course">{{
						part.courseName
					}}</span>
					<span class="programme-progress-widget__state">{{
						stateLabel(part.lifecycle)
					}}</span>
				</li>
			</ul>
			<template v-if="programme.optional.length > 0">
				<h4 class="programme-progress-widget__optional-heading">
					{{ t('learniq', 'Optional courses') }}
				</h4>
				<ul class="programme-progress-widget__list">
					<li
						v-for="part in programme.optional"
						:key="part.courseId"
						class="programme-progress-widget__part">
						<span class="programme-progress-widget__course">{{
							part.courseName
						}}</span>
						<span class="programme-progress-widget__state">{{
							stateLabel(part.lifecycle)
						}}</span>
					</li>
				</ul>
			</template>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'MyProgrammeProgressWidget',

	components: {
		NcLoadingIcon,
	},

	data() {
		return {
			programmes: [],
			loading: true,
		}
	},

	created() {
		this.fetchProgress()
	},

	methods: {
		/**
		 * Fetch the signed-in learner's programme progress.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#scenario-optional-parts-do-not-block-completion
		 */
		async fetchProgress() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/learniq/api/programmes/progress'),
				)
				this.programmes = response.data?.programmes ?? []
			} catch {
				this.programmes = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * The learner-facing word for an enrolment state.
		 *
		 * @param {string} lifecycle Enrolment lifecycle state
		 * @return {string}
		 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#requirement-progress-counts-mandatory-parts
		 */
		stateLabel(lifecycle) {
			const labels = {
				pending: this.t('learniq', 'Waiting for approval'),
				active: this.t('learniq', 'Started'),
				completed: this.t('learniq', 'Done'),
				failed: this.t('learniq', 'Not passed'),
			}
			return labels[lifecycle] ?? lifecycle
		},
	},
}
</script>

<style scoped>
.programme-progress-widget {
	padding: 8px 0;
	overflow-y: auto;
	height: 100%;
}

.programme-progress-widget__loading {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}

.programme-progress-widget__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 24px 0;
	margin: 0;
}

.programme-progress-widget__programme {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.programme-progress-widget__programme:last-child {
	border-bottom: none;
}

.programme-progress-widget__name {
	font-size: 14px;
	font-weight: 600;
	margin: 0 0 6px;
}

.programme-progress-widget__progress {
	display: flex;
	align-items: center;
	gap: 8px;
}

.programme-progress-widget__track {
	flex: 1;
	height: 6px;
	border-radius: 3px;
	background-color: var(--color-border);
	overflow: hidden;
}

.programme-progress-widget__fill {
	height: 100%;
	background-color: var(--color-primary-element);
}

.programme-progress-widget__label,
.programme-progress-widget__state {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.programme-progress-widget__optional-heading {
	font-size: 13px;
	font-weight: 600;
	margin: 8px 0 2px;
}

.programme-progress-widget__list {
	list-style: none;
	margin: 4px 0 0;
	padding: 0;
}

.programme-progress-widget__part {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	padding: 2px 0;
	font-size: 13px;
}
</style>
