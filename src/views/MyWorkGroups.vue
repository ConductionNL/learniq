<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 MyWorkGroups: a learner joins a work group of their class (route
 /my-work-groups, enrolment-self-join-work-group).

 Lists the work group sets of the learner's classes from learniq's
 GET /api/my/work-groups, each group with its free places and members, and
 offers Join, Move or Leave while sign-up is open. learniq checks every rule
 (class membership, the sign-up date, a free place under a lock, one group per
 set) and answers in plain words.

 @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
-->
<template>
	<div class="my-work-groups">
		<h2>{{ t('learniq', 'My work groups') }}</h2>
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent
			v-else-if="sets.length === 0"
			:name="t('learniq', 'No work groups')"
			:description="
				t(
					'learniq',
					'Your teachers have not made work groups for your classes yet.',
				)
			" />
		<section
			v-for="set in sets"
			v-else
			:key="set.cohortId + set.setName"
			class="my-work-groups__set">
			<h3>{{ set.setName }} · {{ set.cohortName }}</h3>
			<p class="my-work-groups__meta">
				{{
					set.open
						? t('learniq', 'Sign-up is open until {date}.', {
								date: formatDate(set.selfJoinUntil),
							})
						: t(
								'learniq',
								'Sign-up has closed. Ask your teacher to change your group.',
							)
				}}
			</p>
			<ul class="my-work-groups__groups">
				<li
					v-for="group in set.groups"
					:key="group.id"
					class="my-work-groups__group">
					<strong>{{ group.name }}</strong>
					<span class="my-work-groups__meta">
						{{
							n(
								'learniq',
								'%n free place',
								'%n free places',
								group.free,
							)
						}}
					</span>
					<span
						v-if="group.members.length > 0"
						class="my-work-groups__members">
						{{ group.members.join(', ') }}
					</span>
					<div v-if="set.open" class="my-work-groups__actions">
						<NcButton
							v-if="group.mine"
							variant="tertiary"
							:disabled="busy"
							@click="act('leave', group)">
							{{ t('learniq', 'Leave') }}
						</NcButton>
						<NcButton
							v-else-if="group.free > 0"
							variant="primary"
							:disabled="busy"
							@click="act('join', group)">
							{{
								hasGroup(set)
									? t('learniq', 'Move here')
									: t('learniq', 'Join')
							}}
						</NcButton>
					</div>
				</li>
			</ul>
		</section>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'MyWorkGroups',

	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard },

	data() {
		return {
			loading: true,
			sets: [],
			busy: false,
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the learner's work group sets.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
		 */
		async load() {
			try {
				this.sets =
					(
						await axios.get(
							generateUrl('/apps/learniq/api/my/work-groups'),
						)
					).data.sets ?? []
			} catch {
				this.sets = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} set A work group set.
		 * @return {boolean} Whether the learner is in one of its groups.
		 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-is-in-one-work-group-per-set
		 */
		hasGroup(set) {
			return set.groups.some((g) => g.mine)
		},

		/**
		 * @param {string} value ISO date-time.
		 * @return {string} A local date.
		 */
		formatDate(value) {
			return value ? new Date(value).toLocaleDateString() : ''
		},

		/**
		 * Join (or move) or leave, then reload.
		 *
		 * @param {string} action `join` or `leave`.
		 * @param {object} group The group.
		 * @return {Promise<void>}
		 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#scenario-a-learner-joins-a-group
		 */
		async act(action, group) {
			this.busy = true
			this.error = ''
			try {
				await axios.post(
					generateUrl(
						`/apps/learniq/api/work-groups/${group.id}/${action}`,
					),
				)
			} catch (error) {
				this.error =
					error?.response?.data?.message
					?? this.t('learniq', 'That did not work. Try again.')
			}
			await this.load()
			this.busy = false
		},
	},
}
</script>

<style scoped>
.my-work-groups {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-inline-size: 60rem;
}

.my-work-groups__groups {
	list-style: none;
	padding: 0;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.my-work-groups__group {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: calc(var(--default-grid-baseline, 4px) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.my-work-groups__meta,
.my-work-groups__members {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
