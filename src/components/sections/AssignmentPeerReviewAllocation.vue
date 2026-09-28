<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AssignmentPeerReviewAllocation: allocate peer reviewers from the assignment
 page (peer-review-allocation-trigger). A body section on AssignmentDetail.

 Calls PeerReviewController::allocate(), which checks that the caller is an
 admin or a teacher of the assignment's cohort. Staff only and only when the
 assignment has peer review turned on; for everyone else it renders nothing.

 @spec openspec/specs/assignments/spec.md#requirement-a-teacher-allocates-peer-reviewers-from-the-assignment-page
-->
<template>
	<div v-if="panel.visible" class="peer-review-allocation">
		<p class="peer-review-allocation__summary">
			{{
				n(
					'learniq',
					'Each submission gets %n reviewer, allocated {strategy}.',
					'Each submission gets %n reviewers, allocated {strategy}.',
					panel.reviewersPerSubmission,
					{ strategy: strategyLabel },
				)
			}}
		</p>
		<p v-if="!panel.canAllocate" class="peer-review-allocation__hint">
			{{ t('learniq', 'Add reviewers by hand from the peer reviews list.') }}
		</p>
		<template v-else>
			<p v-if="panel.beforeDeadline" class="peer-review-allocation__hint">
				{{
					t(
						'learniq',
						'Not everyone may have handed in yet. Allocate again later to add reviewers for new work.',
					)
				}}
			</p>
			<NcButton variant="primary" :disabled="working" @click="allocate">
				<template v-if="working" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('learniq', 'Allocate reviewers') }}
			</NcButton>
			<NcNoteCard v-if="outcomeText" type="success">
				{{ outcomeText }}
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { objectsUrl, oneObject } from '../../utils/customPages.js'
import {
	allocationOutcome,
	peerReviewPanel,
} from '../../utils/peerReviewAllocation.js'

export default {
	name: 'AssignmentPeerReviewAllocation',

	components: { NcButton, NcLoadingIcon, NcNoteCard },

	props: {
		/** Assignment UUID, from the manifest's `@objectId`. */
		assignmentId: { type: String, default: '' },
	},

	data() {
		return {
			views: loadState('learniq', 'dashboardRoles', []),
			assignment: {},
			working: false,
			outcome: null,
			error: '',
		}
	},

	computed: {
		/**
		 * @return {object} What the section shows.
		 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-allocates-peer-reviewers-from-the-assignment-page
		 */
		panel() {
			return peerReviewPanel(this.assignment, this.views, new Date())
		},

		/**
		 * @return {string} The translated strategy.
		 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-allocates-peer-reviewers-from-the-assignment-page
		 */
		strategyLabel() {
			return (
				{
					'round-robin': this.t('learniq', 'in turn'),
					random: this.t('learniq', 'at random'),
					manual: this.t('learniq', 'by hand'),
				}[this.panel.strategy] ?? this.panel.strategy
			)
		},

		/**
		 * @return {string} The result of the last allocation, or ''.
		 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-allocates-peer-reviewers-from-the-assignment-page
		 */
		outcomeText() {
			if (!this.outcome) return ''
			if (this.outcome.kind === 'empty') {
				return this.t('learniq', 'No handed-in work to review yet.')
			}
			if (this.outcome.kind === 'complete') {
				return this.t(
					'learniq',
					'Every submission already has its reviewers.',
				)
			}
			return this.t(
				'learniq',
				'{created} reviews allocated across {processed} submissions.',
				{ created: this.outcome.created, processed: this.outcome.processed },
			)
		},
	},

	watch: {
		/**
		 * Reload when the page moves to another assignment.
		 *
		 * @return {void}
		 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-allocates-peer-reviewers-from-the-assignment-page
		 */
		assignmentId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the assignment's peer review settings.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-allocates-peer-reviewers-from-the-assignment-page
		 */
		async load() {
			this.outcome = null
			this.error = ''
			if (!this.assignmentId || this.views.length === 0) return
			try {
				this.assignment = oneObject(
					(
						await axios.get(
							generateUrl(objectsUrl('assignment', this.assignmentId)),
						)
					).data,
				)
			} catch {
				this.assignment = {}
			}
		},

		/**
		 * Ask the server to allocate reviewers, then report what it did.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assignments/spec.md#requirement-a-teacher-allocates-peer-reviewers-from-the-assignment-page
		 */
		async allocate() {
			this.working = true
			this.outcome = null
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/learniq/api/peer-review/{id}/allocate',
					{
						id: this.assignmentId,
					},
				)
				this.outcome = allocationOutcome((await axios.post(url)).data)
			} catch (e) {
				this.error =
					e?.response?.status === 403
						? this.t(
								'learniq',
								'Only a teacher of this group can allocate reviewers.',
							)
						: this.t('learniq', 'Reviewers could not be allocated.')
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.peer-review-allocation__summary {
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 2);
}

.peer-review-allocation__hint {
	color: var(--color-text-maxcontrast);
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>
