<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 OsoDossierReviewView: the parent review step of an OSO-format transfer
 dossier (route /data-exchange/jobs/:id/oso-review, learniq#947). Covers
 both the PO to VO overstapdossier (target oso) and the SWV care-request
 dossier (target swv).

 Shows the job's dossier in CnStructuredDocReview. While the job is
 pending-parent-review, approving fires approveDossier (OsoDossierReviewGuard
 checks the reviewer is a parent of the learner) and rejecting records the
 comment and fails the job.

 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<div class="oso-review">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<template v-else>
			<CnStructuredDocReview
				:title="t('learniq', 'Review the transfer dossier')"
				:description="
					t(
						'learniq',
						'Check what the school sends on. Approve it, or reject it with a reason.',
					)
				"
				:content="dossier"
				language="json"
				:status="status"
				:statusLabels="statusLabels"
				:showDecision="job.lifecycle === 'pending-parent-review' && !decided"
				:loading="deciding"
				:commentLabel="t('learniq', 'Comment')"
				:commentPlaceholder="t('learniq', 'Say what is wrong or missing.')"
				:approveLabel="t('learniq', 'Approve')"
				:rejectLabel="t('learniq', 'Reject')"
				:rejectRequiresComment="true"
				@decision="decide" />
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="decided" type="success">
				{{ decided }}
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import { CnStructuredDocReview } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { objectsUrl, oneObject, transitionUrl } from '../utils/customPages.js'

export default {
	name: 'OsoDossierReviewView',

	components: { CnStructuredDocReview, NcLoadingIcon, NcNoteCard },

	props: {
		/** DataExchangeJob UUID from the route. */
		id: { type: String, required: true },
	},

	data() {
		return {
			loading: true,
			loadError: '',
			job: {},
			deciding: false,
			decided: '',
			error: '',
		}
	},

	computed: {
		/**
		 * @return {object|string} The dossier the job carries.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		dossier() {
			return (
				this.job.result?.dossier ?? this.job.result ?? this.job.scope ?? {}
			)
		},

		/**
		 * @return {string} CnStructuredDocReview status for the job's state.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		status() {
			if (this.job.lifecycle === 'pending-parent-review') return 'needs-review'
			if (this.job.lifecycle === 'failed') return 'rejected'
			return 'approved'
		},

		/**
		 * @return {Record<string, string>} Translated status labels.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		statusLabels() {
			return {
				'needs-review': this.t('learniq', 'Waiting for your review'),
				approved: this.t('learniq', 'Approved'),
				rejected: this.t('learniq', 'Rejected'),
			}
		},
	},

	async mounted() {
		try {
			this.job = oneObject(
				(
					await axios.get(
						generateUrl(objectsUrl('data-exchange-job', this.id)),
					)
				).data,
			)
		} catch {
			this.loadError = this.t('learniq', 'This dossier could not be loaded.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Approve or reject the dossier.
		 *
		 * @param {{verdict: string, comment: string}} decision The reviewer's decision.
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async decide({ verdict, comment }) {
			this.deciding = true
			this.error = ''
			try {
				if (verdict === 'approve') {
					await axios.post(generateUrl(transitionUrl(this.id)), {
						action: 'approveDossier',
					})
					this.decided = this.t(
						'learniq',
						'You approved the dossier. It is sent on.',
					)
				} else {
					await axios.patch(
						generateUrl(objectsUrl('data-exchange-job', this.id)),
						{
							errorMessage: comment,
						},
					)
					await axios.post(generateUrl(transitionUrl(this.id)), {
						action: 'fail',
					})
					this.decided = this.t(
						'learniq',
						'You rejected the dossier. The school sees your comment.',
					)
				}
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('learniq', 'Your decision could not be saved.')
			} finally {
				this.deciding = false
			}
		},
	},
}
</script>

<style scoped>
.oso-review {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-inline-size: 60rem;
}
</style>
